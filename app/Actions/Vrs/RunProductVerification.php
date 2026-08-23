<?php

namespace App\Actions\Vrs;

use App\Actions\Epcis\ResolveEpcFromScan;
use App\Enums\ExceptionSeverity;
use App\Enums\ExceptionStatus;
use App\Models\Epcis\Epc;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Quarantine\QuarantineHold;
use App\Models\User;
use App\Models\Verification;
use App\Services\Exceptions\ExceptionService;
use App\Services\Quarantine\QuarantineService;
use App\Services\Receiving\ReceivingGate;
use App\Services\Vrs\Contracts\VrsClient;
use App\Services\Vrs\ManufacturerVerificationNotifier;
use App\Support\Auth\SiteAccess;
use App\Support\Custody\ResolveEpcLastKnownGln;
use App\Support\Gs1\ElementString;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class RunProductVerification
{
    /**
     * Outcomes where the VRS answered about the product.
     */
    private const VERDICT_STATUSES = ['verified', 'failed', 'suspect'];

    /**
     * Outcomes where no verdict was returned: the endpoint was unreachable, it (or a proxy
     * in between) faulted, or it responded but had no coverage/record for the product
     * ("not_in_network" / "unknown" reason codes — see HttpVrsClient::verify). Under DSCSA
     * an unanswered verification request is a retry, not a finding — the product is only
     * suspect once a responder actually says so.
     */
    private const TRANSPORT_FAILURE_STATUSES = ['unavailable', 'error'];

    public function __construct(
        private readonly VrsClient $vrsClient,
        private readonly ResolveEpcFromScan $resolveEpcFromScan,
        private readonly ReceivingGate $receivingGate,
        private readonly ExceptionService $exceptions,
        private readonly QuarantineService $quarantine,
        private readonly ManufacturerVerificationNotifier $manufacturerNotifier,
        private readonly ResolveEpcLastKnownGln $lastKnownGln,
    ) {}

    /**
     * @return array{
     *     verification: Verification,
     *     tone: 'ok'|'warn'|'error',
     *     title: string,
     *     body: string,
     *     exception_id: ?int
     * }
     */
    public function handle(string $scan, ?User $actor = null): array
    {
        $normalized = ElementString::normalize($scan);

        if ($normalized === '') {
            throw new InvalidArgumentException('Scan a product barcode or enter GTIN and serial.');
        }

        // Parse the raw scan so FNC1 still delimits AI 10 / AI 21; $normalized is the
        // display form kept for the audit trail.
        $identity = ElementString::sgtinIdentity($scan);

        if ($identity === null) {
            throw new InvalidArgumentException('Scan must include a GTIN (AI 01) and serial (AI 21).');
        }

        $resolved = $this->resolveEpcFromScan->handle($scan);
        /** @var Epc|null $epc */
        $epc = $resolved['epc'];

        $lot = $identity['lot_number'] ?? null;
        $expiry = $identity['expiry_yymmdd'] ?? null;

        if ($epc !== null) {
            $hold = $this->receivingGate->epcBlockedByOpenHold($epc);
            if ($hold !== null) {
                return $this->blockedByQuarantine($identity, $normalized, $hold, $actor, $lot, $expiry, $epc);
            }
        }

        $result = $this->vrsClient->verify(
            $identity['gtin14'],
            $identity['serial'],
            $lot,
            $expiry,
        );

        $verification = Verification::query()->create([
            'gtin14' => $result['gtin14'],
            'serial' => $result['serial'],
            'lot' => $result['lot'],
            'status' => $result['status'],
            'scanned_barcode' => $normalized,
            'verified_by' => $actor?->getKey(),
            // A plain array_filter() drops falsy values, which would silently strip a
            // legitimate serial or lot of "0" from the audit trail — keep every value
            // except null/empty string.
            'request_payload' => array_filter([
                'gtin14' => $identity['gtin14'],
                'serial' => $identity['serial'],
                'lot' => $lot,
                'expiry_yymmdd' => $expiry,
                'site_id' => $this->resolveSiteIdForEpc($epc),
            ], fn ($value): bool => $value !== null && $value !== ''),
            'response_payload' => $result,
            'message' => $result['message'],
            'verified_at' => in_array($result['status'], self::VERDICT_STATUSES, true) ? now() : null,
        ]);

        if ($result['status'] === 'verified' && $epc !== null) {
            $hold = $this->receivingGate->epcBlockedByOpenHold($epc);
            if ($hold !== null) {
                return $this->overrideVerifiedForQuarantine($verification, $hold);
            }
        }

        $exceptionId = null;

        if ($this->shouldOpenException($result['status'])) {
            $exceptionId = $this->openVerificationException(
                $verification,
                $epc,
                $result['message'],
                $actor,
            );
            $verification->forceFill(['exception_id' => $exceptionId])->save();
        }

        return [
            'verification' => $verification->refresh(),
            'tone' => $this->toneForStatus($result['status']),
            'title' => $this->titleForStatus($result['status']),
            'body' => $result['message'],
            'exception_id' => $exceptionId,
        ];
    }

    /**
     * A transport failure carries no information about the product, so it must not open a
     * High severity case or quarantine the EPC — that would strand good stock every time
     * the VRS is down. The Verification row keeps the attempt on the audit trail and the
     * operator is told to rescan.
     */
    public static function isTransportFailure(string $status): bool
    {
        return in_array($status, self::TRANSPORT_FAILURE_STATUSES, true);
    }

    private function shouldOpenException(string $status): bool
    {
        if (self::isTransportFailure($status)) {
            return false;
        }

        return in_array($status, ['failed', 'suspect'], true);
    }

    /**
     * @param  array<string, mixed>  $identity
     * @return array{
     *     verification: Verification,
     *     tone: 'ok'|'warn'|'error',
     *     title: string,
     *     body: string,
     *     exception_id: ?int
     * }
     */
    private function blockedByQuarantine(
        array $identity,
        string $normalized,
        QuarantineHold $hold,
        ?User $actor,
        ?string $lot,
        ?string $expiry,
        ?Epc $epc,
    ): array {
        $message = $this->quarantineBlockMessage($hold);

        $verification = Verification::query()->create([
            'gtin14' => $identity['gtin14'],
            'serial' => $identity['serial'],
            'lot' => $lot,
            'status' => 'quarantined',
            'scanned_barcode' => $normalized,
            'verified_by' => $actor?->getKey(),
            'request_payload' => array_filter([
                'gtin14' => $identity['gtin14'],
                'serial' => $identity['serial'],
                'lot' => $lot,
                'expiry_yymmdd' => $expiry,
                'site_id' => $this->resolveSiteIdForEpc($epc),
            ], fn ($value): bool => $value !== null && $value !== ''),
            'response_payload' => [
                'blocked_by' => 'open_quarantine_hold',
                'hold_id' => $hold->getKey(),
            ],
            'message' => $message,
            'exception_id' => $hold->exception_id,
            'verified_at' => null,
        ]);

        return [
            'verification' => $verification,
            'tone' => 'error',
            'title' => 'Quarantined',
            'body' => $message,
            'exception_id' => $hold->exception_id !== null ? (int) $hold->exception_id : null,
        ];
    }

    /**
     * @return array{
     *     verification: Verification,
     *     tone: 'ok'|'warn'|'error',
     *     title: string,
     *     body: string,
     *     exception_id: ?int
     * }
     */
    private function overrideVerifiedForQuarantine(Verification $verification, QuarantineHold $hold): array
    {
        $message = $this->quarantineBlockMessage($hold);

        $verification->forceFill([
            'status' => 'quarantined',
            'message' => $message,
            'exception_id' => $hold->exception_id,
            'verified_at' => null,
            'response_payload' => array_merge($verification->response_payload ?? [], [
                'overridden_by' => 'open_quarantine_hold',
                'hold_id' => $hold->getKey(),
            ]),
        ])->save();

        return [
            'verification' => $verification->refresh(),
            'tone' => 'error',
            'title' => 'Quarantined',
            'body' => $message,
            'exception_id' => $hold->exception_id !== null ? (int) $hold->exception_id : null,
        ];
    }

    private function quarantineBlockMessage(QuarantineHold $hold): string
    {
        $caseId = $hold->exception_id;
        $suffix = $caseId !== null ? " (exception #{$caseId})" : '';

        return 'Under quarantine'.$suffix.'. Clear or release quarantine before dispensing.';
    }

    private function openVerificationException(
        Verification $verification,
        ?Epc $epc,
        string $reason,
        ?User $actor,
    ): int {
        $type = $this->exceptions->resolveType('VERIFICATION_FAILED');
        $epcIds = $epc !== null ? [(int) $epc->getKey()] : [];

        $case = DB::transaction(function () use ($type, $verification, $reason, $epcIds, $actor, $epc): ExceptionCase {
            $case = $this->exceptions->create([
                'exception_type_id' => $type->getKey(),
                'document_id' => null,
                'site_id' => $this->resolveSiteIdForEpc($epc),
                'title' => 'VRS failed · '.$verification->gtin14.' / '.$verification->serial,
                'description' => $reason,
                'severity' => ExceptionSeverity::High->value,
                'status' => ExceptionStatus::New->value,
            ], $epcIds, $actor);

            if ($epcIds !== []) {
                $this->quarantine->openForCase(
                    $case,
                    $epcIds,
                    $reason,
                    $actor,
                );
            }

            return $case;
        });

        $this->manufacturerNotifier->notifyIfApplicable($verification, $case);

        return (int) $case->getKey();
    }

    private function resolveSiteIdForEpc(?Epc $epc): ?int
    {
        if ($epc === null) {
            return null;
        }

        $gln = $this->lastKnownGln->forEpc($epc);

        return SiteAccess::organizationSiteIdForGln($gln);
    }

    private function toneForStatus(string $status): string
    {
        return match ($status) {
            'verified' => 'ok',
            'deferred', 'unavailable' => 'warn',
            default => 'error',
        };
    }

    private function titleForStatus(string $status): string
    {
        return match ($status) {
            'verified' => 'Verified',
            'deferred' => 'Verification deferred',
            'unavailable' => 'VRS unavailable — rescan',
            'suspect' => 'Suspect product',
            'failed' => 'Verification failed',
            default => 'Verification error',
        };
    }
}
