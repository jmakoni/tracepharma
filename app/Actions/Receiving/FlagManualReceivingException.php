<?php

namespace App\Actions\Receiving;

use App\Enums\ExceptionActivityKind;
use App\Enums\ExceptionActivityVisibility;
use App\Enums\ExceptionSeverity;
use App\Enums\ExceptionStatus;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Receiving\ReceivingScanLine;
use App\Models\Receiving\ReceivingSession;
use App\Models\User;
use App\Services\Exceptions\ExceptionService;
use App\Services\Quarantine\QuarantineService;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Auth\SiteAccess;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Floor / post-receive manual claims (shortage, overage, damaged) into the existing
 * ExceptionCase stack — mirrors Vatengi FlagManualReceivingException for TracePharma.
 */
final class FlagManualReceivingException
{
    public function __construct(
        private readonly ExceptionService $exceptions,
        private readonly QuarantineService $quarantine,
    ) {}

    /**
     * @param  array{
     *     notes?: string|null,
     *     epc_ids?: list<int>,
     *     expected?: int|string|null,
     *     actual?: int|string|null,
     * }|null  $payload
     */
    public function execute(
        ReceivingSession $session,
        string $type,
        ?array $payload = null,
        ?User $actor = null,
    ): ExceptionCase {
        $payload ??= [];
        $actor ??= auth()->user();

        if (! JobRoleAccess::allows(Permissions::NavReceive)) {
            throw new InvalidArgumentException('Receiving is not authorized for your job role.');
        }

        $this->assertSessionEligible($session, $actor);

        [$typeCode, $title, $description, $severity, $epcIds, $quarantine] = match ($type) {
            'shortage' => $this->shortageSpec($session, $payload),
            'overage' => $this->overageSpec($session, $payload),
            'damaged' => $this->damagedSpec($session, $payload),
            default => throw new InvalidArgumentException("Unknown receiving issue type [{$type}]."),
        };

        $exceptionType = $this->exceptions->resolveType($typeCode);

        $case = DB::transaction(function () use (
            $session,
            $type,
            $payload,
            $actor,
            $exceptionType,
            $title,
            $description,
            $severity,
            $epcIds,
            $quarantine,
        ): ExceptionCase {
            $case = $this->exceptions->create([
                'exception_type_id' => $exceptionType->getKey(),
                'document_id' => $session->epcis_document_id,
                'trading_partner_id' => $session->trading_partner_id,
                'site_id' => $session->site_id,
                'title' => $title,
                'description' => $description,
                'severity' => $severity->value,
                'status' => ExceptionStatus::New->value,
            ], $epcIds, $actor);

            $case->logActivity(
                ExceptionActivityKind::System,
                $actor,
                'Manual receiving issue reported ('.$type.').',
                ExceptionActivityVisibility::Internal,
                [
                    'source' => 'receiving_issues',
                    'manual_exception_type' => $type,
                    'receiving_session_id' => (int) $session->getKey(),
                    'notes' => $payload['notes'] ?? null,
                    'expected' => $payload['expected'] ?? null,
                    'actual' => $payload['actual'] ?? null,
                ],
            );

            if ($quarantine && $epcIds !== []) {
                $this->quarantine->openForCase(
                    $case,
                    $epcIds,
                    (string) ($payload['notes'] ?? match ($type) {
                        'overage' => 'Overage / unexpected EPC reported during receiving.',
                        default => 'Damaged on arrival reported during receiving.',
                    }),
                    $actor,
                    $session->document,
                    ['receiving_session_id' => (int) $session->getKey()],
                );
            }

            return $case;
        });

        return $case->fresh(['type', 'epcs', 'quarantineHolds']) ?? $case;
    }

    private function assertSessionEligible(ReceivingSession $session, ?User $actor): void
    {
        if ($session->status !== 'completed') {
            throw new InvalidArgumentException('Receiving session must be completed before filing issues.');
        }

        if ($session->site_id === null || $actor === null) {
            return;
        }

        if (! SiteAccess::canAccessSite($actor, (int) $session->site_id)) {
            throw new InvalidArgumentException('You do not have access to this receiving session site.');
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: string, 1: string, 2: string, 3: ExceptionSeverity, 4: list<int>, 5: bool}
     */
    private function shortageSpec(ReceivingSession $session, array $payload): array
    {
        $epcIds = $this->resolveEpcIds(
            $session,
            $payload,
            fn (): array => ReceivingScanLine::query()
                ->where('receiving_session_id', $session->getKey())
                ->where('status', 'expected')
                ->whereNotNull('epc_id')
                ->pluck('epc_id')
                ->map(fn ($id): int => (int) $id)
                ->unique()
                ->values()
                ->all(),
            ['expected'],
        );

        if ($epcIds === []) {
            throw new InvalidArgumentException('Shortage requires at least one unconfirmed expected EPC on this session.');
        }

        $expected = $payload['expected']
            ?? ((int) $session->expected_parent_count + (int) $session->expected_child_count);
        $actual = $payload['actual']
            ?? ((int) $session->confirmed_parent_count + (int) $session->confirmed_child_count);

        $notes = trim((string) ($payload['notes'] ?? ''));
        $description = $notes !== ''
            ? $notes
            : sprintf(
                'Operator reported shortage after receive. Expected lines/units: %s, confirmed: %s. Unconfirmed expected EPC(s): %d.',
                $expected,
                $actual,
                count($epcIds),
            );

        return [
            'PARTIAL_SHIPMENT_UNDECLARED',
            'Shortage reported during receiving #'.$session->getKey(),
            $description,
            ExceptionSeverity::Medium,
            $epcIds,
            false,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: string, 1: string, 2: string, 3: ExceptionSeverity, 4: list<int>, 5: bool}
     */
    private function overageSpec(ReceivingSession $session, array $payload): array
    {
        $epcIds = $this->resolveEpcIds(
            $session,
            $payload,
            fn (): array => ReceivingScanLine::query()
                ->where('receiving_session_id', $session->getKey())
                ->where('status', 'unexpected')
                ->whereNotNull('epc_id')
                ->pluck('epc_id')
                ->map(fn ($id): int => (int) $id)
                ->unique()
                ->values()
                ->all(),
            ['unexpected'],
        );

        $notes = trim((string) ($payload['notes'] ?? ''));
        $description = $notes !== ''
            ? $notes
            : sprintf(
                'Operator reported overage after receive. Unexpected line(s): %d.',
                count($epcIds),
            );

        return [
            'OVER_SHIPMENT',
            'Overage reported during receiving #'.$session->getKey(),
            $description,
            ExceptionSeverity::High,
            $epcIds,
            $epcIds !== [],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: string, 1: string, 2: string, 3: ExceptionSeverity, 4: list<int>, 5: bool}
     */
    private function damagedSpec(ReceivingSession $session, array $payload): array
    {
        $epcIds = $this->resolveEpcIds(
            $session,
            $payload,
            fn (): array => [],
            ['confirmed', 'unexpected'],
        );

        if ($epcIds === []) {
            throw new InvalidArgumentException('Select at least one confirmed or unexpected EPC from this receiving session to report as damaged.');
        }

        $notes = trim((string) ($payload['notes'] ?? ''));
        $description = $notes !== ''
            ? $notes
            : 'Operator flagged damaged product on arrival after receive.';

        return [
            'SUSPECT_PRODUCT',
            'Damaged on arrival · receiving #'.$session->getKey(),
            $description,
            ExceptionSeverity::Critical,
            $epcIds,
            true,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  callable(): list<int>  $fallback
     * @param  list<string>|null  $allowedStatuses  When set, only EPCs on lines with these statuses are eligible.
     * @return list<int>
     */
    private function resolveEpcIds(
        ReceivingSession $session,
        array $payload,
        callable $fallback,
        ?array $allowedStatuses = null,
    ): array {
        $lines = ReceivingScanLine::query()
            ->where('receiving_session_id', $session->getKey())
            ->whereNotNull('epc_id');

        if ($allowedStatuses !== null) {
            $lines->whereIn('status', $allowedStatuses);
        }

        $sessionEpcIds = $lines
            ->pluck('epc_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        if (! empty($payload['epc_ids']) && is_array($payload['epc_ids'])) {
            $requested = array_values(array_unique(array_filter(
                array_map('intval', $payload['epc_ids']),
                fn (int $id): bool => $id > 0,
            )));

            $allowed = array_values(array_intersect($requested, $sessionEpcIds));

            if ($allowedStatuses !== null && $requested !== [] && $allowed === []) {
                throw new InvalidArgumentException(
                    'Selected EPC(s) are not eligible for this receiving issue on this session.',
                );
            }

            return $allowed;
        }

        return array_values(array_intersect($fallback(), $sessionEpcIds));
    }
}
