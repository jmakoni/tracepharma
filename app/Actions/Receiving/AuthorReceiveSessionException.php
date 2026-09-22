<?php

declare(strict_types=1);

namespace App\Actions\Receiving;

use App\Enums\ExceptionActivityKind;
use App\Enums\ExceptionActivityVisibility;
use App\Enums\ExceptionSeverity;
use App\Enums\ExceptionStatus;
use App\Models\Epcis\Epc;
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

        return $open->first();
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
        ];
    }
}
