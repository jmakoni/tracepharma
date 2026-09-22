<?php

declare(strict_types=1);

namespace App\Actions\Receiving;

use App\Enums\ExceptionActivityKind;
use App\Enums\ExceptionActivityVisibility;
use App\Enums\ExceptionSeverity;
use App\Enums\ExceptionStatus;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Receiving\ReceivingSession;
use App\Models\User;
use App\Services\Exceptions\ExceptionService;
use App\Services\Quarantine\QuarantineService;
use App\Support\Receiving\ReceiveExceptionTypes;
use App\Support\Receiving\ReceiveSessionExceptionQuery;
use App\Support\Receiving\ReceivingIssueSessionLabel;
use Illuminate\Support\Facades\DB;

/**
 * Session-scoped receive exception author. Scan-path and short-close skip notify.
 */
final class AuthorReceiveSessionException
{
    public function __construct(
        private readonly ExceptionService $exceptions,
        private readonly QuarantineService $quarantine,
    ) {}

    /**
     * @param  list<int>  $epcIds
     * @param  array<string, mixed>  $meta
     */
    public function handle(
        ReceivingSession $session,
        string $typeCode,
        string $title,
        string $description,
        array $epcIds = [],
        array $meta = [],
        ?User $actor = null,
        bool $quarantine = false,
        bool $notify = false,
    ): ExceptionCase {
        $existing = $this->findOpen($session, $typeCode, $epcIds);
        if ($existing !== null) {
            return $this->attach($existing, $session, $epcIds, $meta, $actor);
        }

        $type = $this->exceptions->resolveType($typeCode);
        $severity = $type->default_severity instanceof ExceptionSeverity
            ? $type->default_severity
            : ExceptionSeverity::Medium;

        $case = $this->exceptions->create([
            'exception_type_id' => $type->getKey(),
            'document_id' => $session->epcis_document_id,
            'trading_partner_id' => $session->trading_partner_id,
            'site_id' => $session->site_id,
            'title' => $title,
            'description' => $description,
            'severity' => $severity->value,
            'status' => ExceptionStatus::New->value,
        ], $epcIds, $actor, notify: $notify);

        DB::transaction(function () use ($session, $case, $actor, $epcIds, $quarantine, $meta): void {
            $case->logActivity(
                ExceptionActivityKind::System,
                $actor,
                'Receive exception authored ('.$case->type?->code.').',
                ExceptionActivityVisibility::Internal,
                $this->sessionMeta($session, $meta),
            );

            if ($quarantine && $epcIds !== []) {
                $this->quarantine->openForCase(
                    $case,
                    $epcIds,
                    (string) ($meta['notes'] ?? $case->description ?? 'Receive exception quarantine.'),
                    $actor,
                    $session->document,
                    $this->sessionMeta($session, $meta),
                );
            }
        });

        return $case->fresh(['type', 'epcs']) ?? $case;
    }

    /**
     * @param  list<int>  $epcIds
     */
    public function productNoData(
        ReceivingSession $session,
        array $epcIds = [],
        ?User $actor = null,
        string $notes = 'Product scanned with no matching inbound serial/file.',
        bool $alsoOverage = false,
    ): ExceptionCase {
        $case = $this->handle(
            $session,
            ReceiveExceptionTypes::PRODUCT_NO_DATA,
            'Product no data · receiving #'.$session->getKey(),
            $notes,
            $epcIds,
            [
                'source' => 'receive_scan',
                'notes' => $notes,
            ],
            $actor,
        );

        if ($alsoOverage) {
            $this->handle(
                $session,
                ReceiveExceptionTypes::OVERAGE,
                'Overage · receiving #'.$session->getKey(),
                $notes,
                $epcIds,
                [
                    'source' => 'receive_scan',
                    'notes' => $notes,
                ],
                $actor,
                quarantine: $epcIds !== [],
            );
        }

        return $case;
    }

    /**
     * @param  list<int>  $epcIds
     */
    public function dataNoProduct(
        ReceivingSession $session,
        array $epcIds,
        ?User $actor = null,
        string $notes = 'Short-close left expected lines unconfirmed.',
        string $source = 'short_close',
    ): ExceptionCase {
        return $this->handle(
            $session,
            ReceiveExceptionTypes::DATA_NO_PRODUCT,
            'Data no product · receiving #'.$session->getKey(),
            $notes,
            $epcIds,
            [
                'source' => $source,
                'manual_exception_type' => 'shortage',
                'reason' => ReceiveExceptionTypes::REASON_UNDECLARED_PARTIAL,
                'notes' => $notes,
            ],
            $actor,
        );
    }

    /**
     * @param  list<int>  $epcIds
     */
    public function aggregationBreak(
        ReceivingSession $session,
        Epc $parent,
        ?User $actor = null,
    ): ExceptionCase {
        return $this->handle(
            $session,
            ReceiveExceptionTypes::AGGREGATION_BREAK,
            'Aggregation break · receiving #'.$session->getKey(),
            'Sealed receive found no inbound aggregation children for this parent.',
            [(int) $parent->getKey()],
            [
                'source' => 'receive_scan',
                'notes' => 'Empty ResolveInboundAggregationChildEpcs',
                ...$this->hdaFromEpc($parent),
            ],
            $actor,
        );
    }

    /**
     * Bound ASN/shipment extra serial (usable inbound file exists).
     *
     * @param  list<int>  $epcIds
     * @param  array<string, mixed>  $identity
     */
    public function overage(
        ReceivingSession $session,
        array $epcIds,
        ?User $actor = null,
        string $notes = 'Serial is not on the bound inbound ASN/shipment.',
        ?string $scanRaw = null,
        array $identity = [],
    ): ExceptionCase {
        $epc = $this->firstEpc($epcIds);

        return $this->handle(
            $session,
            ReceiveExceptionTypes::OVERAGE,
            'Overage · receiving #'.$session->getKey(),
            $notes,
            $epcIds,
            [
                'source' => 'receive_scan',
                'notes' => $notes,
                ...$this->hdaFromEpc($epc, $scanRaw, $identity),
            ],
            $actor,
            quarantine: $epcIds !== [],
        );
    }

    /**
     * @param  array<string, mixed>  $mismatch
     * @param  array<string, mixed>  $identity
     */
    public function piMismatch(
        ReceivingSession $session,
        Epc $epc,
        array $mismatch,
        ?User $actor = null,
        ?string $scanRaw = null,
        array $identity = [],
    ): ExceptionCase {
        return $this->handle(
            $session,
            ReceiveExceptionTypes::PI_MISMATCH,
            'PI mismatch · receiving #'.$session->getKey(),
            'Scanned 2D GTIN/serial/lot/expiry does not match inbound EPCIS PI.',
            [(int) $epc->getKey()],
            [
                'source' => 'receive_scan',
                'notes' => 'Inbound document PI mismatch',
                'mismatch' => $mismatch,
                ...$this->hdaFromEpc($epc, $scanRaw, $identity),
            ],
            $actor,
        );
    }

    /**
     * @param  array<string, mixed>  $identity
     */
    public function duplicateSerial(
        ReceivingSession $session,
        Epc $epc,
        string $reason,
        ?User $actor = null,
        ?string $scanRaw = null,
        array $identity = [],
    ): ExceptionCase {
        return $this->handle(
            $session,
            ReceiveExceptionTypes::DUPLICATE_SERIAL,
            'Duplicate serial · receiving #'.$session->getKey(),
            'Serial already confirmed on receive, or already shipped/sold.',
            [(int) $epc->getKey()],
            [
                'source' => 'receive_scan',
                'notes' => $reason,
                'reason' => $reason,
                ...$this->hdaFromEpc($epc, $scanRaw, $identity),
            ],
            $actor,
        );
    }

    /**
     * @param  array{file: string, site: string}  $mismatch
     * @param  array<string, mixed>  $identity
     */
    public function wrongDestination(
        ReceivingSession $session,
        Epc $epc,
        array $mismatch,
        ?User $actor = null,
        ?string $scanRaw = null,
        array $identity = [],
    ): ExceptionCase {
        return $this->handle(
            $session,
            ReceiveExceptionTypes::WRONG_DESTINATION,
            'Wrong destination · receiving #'.$session->getKey(),
            'Inbound ship-to SGLN does not match this receive site.',
            [(int) $epc->getKey()],
            [
                'source' => 'receive_scan',
                'notes' => 'Ship-to '.$mismatch['file'].' ≠ site '.$mismatch['site'],
                'file_gln' => $mismatch['file'],
                'site_gln' => $mismatch['site'],
                ...$this->hdaFromEpc($epc, $scanRaw, $identity),
            ],
            $actor,
        );
    }

    /**
     * Missing, late, or schema-rejected inbound file. Session-level hold.
     *
     * @param  list<int>  $epcIds
     */
    public function lateFailedEpcis(
        ReceivingSession $session,
        string $reason = 'missing_or_failed_inbound_epcis',
        ?User $actor = null,
        array $epcIds = [],
        bool $quarantine = false,
    ): ExceptionCase {
        return $this->handle(
            $session,
            ReceiveExceptionTypes::LATE_FAILED_EPCIS,
            'Late / failed EPCIS · receiving #'.$session->getKey(),
            'Inbound EPCIS is missing, late, or schema-rejected.',
            $epcIds,
            [
                'source' => 'receive_document',
                'notes' => $reason,
                'reason' => $reason,
            ],
            $actor,
            quarantine: $quarantine && $epcIds !== [],
        );
    }

    /**
     * @param  array<string, array{scan: string, line: string}>  $mismatch
     * @param  array<string, mixed>  $identity
     */
    public function wrongItem(
        ReceivingSession $session,
        Epc $epc,
        array $mismatch,
        ?User $actor = null,
        ?string $scanRaw = null,
        array $identity = [],
    ): ExceptionCase {
        return $this->handle(
            $session,
            ReceiveExceptionTypes::WRONG_ITEM,
            'Wrong item · receiving #'.$session->getKey(),
            'Scanned GTIN/lot does not match the ASN/order line.',
            [(int) $epc->getKey()],
            [
                'source' => 'receive_scan',
                'notes' => 'ASN line product identity mismatch',
                'mismatch' => $mismatch,
                ...$this->hdaFromEpc($epc, $scanRaw, $identity),
            ],
            $actor,
        );
    }

    /**
     * Operator damage on an open receive — quarantine, do not confirm as sellable.
     *
     * @param  list<int>  $epcIds
     */
    public function damaged(
        ReceivingSession $session,
        array $epcIds,
        ?User $actor = null,
        string $notes = 'Operator flagged damaged product on this receive.',
        ?string $scanRaw = null,
    ): ExceptionCase {
        $epc = $this->firstEpc($epcIds);

        return $this->handle(
            $session,
            ReceiveExceptionTypes::DAMAGED,
            'Damaged · receiving #'.$session->getKey(),
            $notes,
            $epcIds,
            [
                'source' => 'receive_floor',
                'notes' => $notes,
                ...$this->hdaFromEpc($epc, $scanRaw),
            ],
            $actor,
            quarantine: $epcIds !== [],
        );
    }

    public function inheritLateFailedFromDocument(
        EpcisDocument $document,
        string $reason = 'schema_rejected_inbound_epcis',
        ?User $actor = null,
    ): void {
        if ((string) ($document->direction ?? '') !== 'inbound') {
            return;
        }

        $sessions = ReceivingSession::query()
            ->where(function ($query) use ($document): void {
                $query->where('epcis_document_id', $document->getKey())
                    ->orWhere('matched_epcis_document_id', $document->getKey());
            })
            ->whereIn('status', ['open', 'in_progress'])
            ->get();

        foreach ($sessions as $session) {
            $this->lateFailedEpcis($session, $reason, $actor);
        }
    }

    public function refused(
        ReceivingSession $session,
        string $reason,
        ?User $actor = null,
    ): ExceptionCase {
        return $this->handle(
            $session,
            ReceiveExceptionTypes::REFUSED,
            'Refused · receiving #'.$session->getKey(),
            'Receive cancelled after product was presented. Serials were not received.',
            [],
            [
                'source' => 'receive_cancel',
                'notes' => $reason,
                'reason' => $reason,
            ],
            $actor,
        );
    }

    /**
     * @param  list<int>  $epcIds
     */
    private function findOpen(ReceivingSession $session, string $typeCode, array $epcIds): ?ExceptionCase
    {
        $open = ReceiveSessionExceptionQuery::openCases($session, [$typeCode]);

        if ($epcIds === []) {
            return $open->first();
        }

        foreach ($open as $case) {
            $attached = $case->epcs()->allRelatedIds()->map(fn ($id): int => (int) $id)->all();
            if (array_intersect($epcIds, $attached) !== []) {
                return $case;
            }
        }

        return null;
    }

    /**
     * @param  list<int>  $epcIds
     * @param  array<string, mixed>  $meta
     */
    private function attach(
        ExceptionCase $case,
        ReceivingSession $session,
        array $epcIds,
        array $meta,
        ?User $actor,
    ): ExceptionCase {
        if ($epcIds === []) {
            return $case;
        }

        $before = $case->epcs()->allRelatedIds()->map(fn ($id): int => (int) $id)->all();
        $case->epcs()->syncWithoutDetaching($epcIds);
        $added = array_values(array_diff($epcIds, $before));
        $case->forceFill(['serials_affected' => $case->epcs()->count()])->save();

        if ($added !== []) {
            $case->logActivity(
                ExceptionActivityKind::System,
                $actor,
                'Receive exception updated with additional EPCs.',
                ExceptionActivityVisibility::Internal,
                $this->sessionMeta($session, [...$meta, 'added_epc_ids' => $added]),
            );
        }

        return $case->fresh(['type', 'epcs']) ?? $case;
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function sessionMeta(ReceivingSession $session, array $extra = []): array
    {
        $refs = ReceivingIssueSessionLabel::orderRefs($session);

        return [
            ...$extra,
            'receiving_session_id' => (int) $session->getKey(),
            'customer_po' => $refs['po'],
            'asn_number' => $refs['asn'],
            'po' => $extra['po'] ?? $refs['po'],
            'desadv' => $extra['desadv'] ?? $refs['asn'],
        ];
    }

    /**
     * @param  list<int>  $epcIds
     */
    private function firstEpc(array $epcIds): ?Epc
    {
        $epcId = $epcIds[0] ?? null;

        return $epcId !== null ? Epc::query()->find($epcId) : null;
    }

    /**
     * @param  array<string, mixed>  $identity
     * @return array<string, mixed>
     */
    private function hdaFromEpc(?Epc $epc, ?string $scanRaw = null, array $identity = []): array
    {
        return array_filter([
            'gtin' => $identity['gtin14'] ?? $epc?->gtin14,
            'lot' => $identity['lot_number'] ?? $epc?->ilmd?->lot_number,
            'sscc' => $epc?->sscc18 ?? $identity['sscc18'] ?? null,
            'scan_raw' => $scanRaw,
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }
}
