<?php

declare(strict_types=1);

namespace App\Support\Receiving;

use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Quarantine\QuarantineHold;
use App\Models\Receiving\ReceivingScanLine;
use App\Models\Receiving\ReceivingSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Honesty predicates for receive-desk exception types.
 * True = condition still exists; false = safe to mark cleared.
 */
final class ReceiveExceptionCondition
{
    /**
     * @var list<string>
     */
    public const AUTO_RECHECK_TYPES = [
        ReceiveExceptionTypes::LATE_FAILED_EPCIS,
        ReceiveExceptionTypes::PRODUCT_NO_DATA,
        ReceiveExceptionTypes::PI_MISMATCH,
        ReceiveExceptionTypes::AGGREGATION_BREAK,
        ReceiveExceptionTypes::WRONG_DESTINATION,
        ReceiveExceptionTypes::OVERAGE,
        ReceiveExceptionTypes::DATA_NO_PRODUCT,
    ];

    /**
     * Manual Resolve is blocked while these predicates are still true unless override.
     *
     * @var list<string>
     */
    public const PREDICATE_GATED_RESOLVE = [
        ReceiveExceptionTypes::LATE_FAILED_EPCIS,
        ReceiveExceptionTypes::PRODUCT_NO_DATA,
    ];

    /**
     * OS&D / commercial close — Resolve is allowed even when a dock condition remains.
     *
     * @var list<string>
     */
    public const MANUAL_RESOLVE_ALLOWED = [
        ReceiveExceptionTypes::SHORTAGE,
        ReceiveExceptionTypes::OVERAGE,
        ReceiveExceptionTypes::DAMAGED,
        ReceiveExceptionTypes::WRONG_ITEM,
        ReceiveExceptionTypes::REFUSED,
        ReceiveExceptionTypes::DATA_NO_PRODUCT,
    ];

    public function stillTrue(ExceptionCase $case): bool
    {
        $code = strtoupper(trim((string) $case->type?->code));

        return match ($code) {
            ReceiveExceptionTypes::LATE_FAILED_EPCIS => $this->lateFailedEpcisStillTrue($case),
            ReceiveExceptionTypes::PRODUCT_NO_DATA => $this->productNoDataStillTrue($case),
            ReceiveExceptionTypes::PI_MISMATCH => $this->piMismatchStillTrue($case),
            ReceiveExceptionTypes::WRONG_DESTINATION => $this->wrongDestinationStillTrue($case),
            ReceiveExceptionTypes::AGGREGATION_BREAK => $this->aggregationBreakStillTrue($case),
            ReceiveExceptionTypes::OVERAGE => $this->overageStillTrue($case),
            ReceiveExceptionTypes::DATA_NO_PRODUCT => $this->dataNoProductStillTrue($case),
            ReceiveExceptionTypes::DUPLICATE_SERIAL => $this->duplicateStillTrue($case),
            ReceiveExceptionTypes::DAMAGED => $this->quarantineStillOpen($case),
            default => true,
        };
    }

    public function typeCode(ExceptionCase $case): string
    {
        return strtoupper(trim((string) $case->type?->code));
    }

    public function isAutoRecheckType(ExceptionCase $case): bool
    {
        return in_array($this->typeCode($case), self::AUTO_RECHECK_TYPES, true);
    }

    public function isPredicateGatedResolve(ExceptionCase $case): bool
    {
        return in_array($this->typeCode($case), self::PREDICATE_GATED_RESOLVE, true);
    }

    private function lateFailedEpcisStillTrue(ExceptionCase $case): bool
    {
        $session = $this->sessionFor($case);
        if ($session !== null && $this->sessionHasInboundFile($session)) {
            return false;
        }

        $epcIds = $this->epcIdsFor($case, $session);

        return ! $this->inboundDocumentContainsAnyEpc($epcIds);
    }

    private function productNoDataStillTrue(ExceptionCase $case): bool
    {
        $session = $this->sessionFor($case);
        $epcIds = $this->epcIdsFor($case, $session);
        if ($epcIds === []) {
            return true;
        }

        foreach ($epcIds as $epcId) {
            if (! $this->inboundDocumentContainsEpc($epcId)) {
                return true;
            }
        }

        return false;
    }

    private function piMismatchStillTrue(ExceptionCase $case): bool
    {
        $session = $this->sessionFor($case);
        if ($session === null || ! $this->sessionHasInboundFile($session)) {
            return true;
        }

        $compare = app(CompareInboundDocumentPi::class);
        foreach ($this->epcsFor($case, $session) as $epc) {
            $identity = $this->scanIdentity($session, $epc);
            if ($compare->mismatch($session, $epc, $identity) !== null) {
                return true;
            }
        }

        return false;
    }

    private function wrongDestinationStillTrue(ExceptionCase $case): bool
    {
        $session = $this->sessionFor($case);
        if ($session === null) {
            return true;
        }

        return app(ReceiveWrongDestination::class)->mismatch($session) !== null;
    }

    private function aggregationBreakStillTrue(ExceptionCase $case): bool
    {
        $session = $this->sessionFor($case);
        if ($session === null) {
            return true;
        }

        $preferred = $session->epcis_document_id ?? $session->matched_epcis_document_id;
        if ($preferred === null) {
            return true;
        }

        $resolve = app(ResolveInboundAggregationChildEpcs::class);
        foreach ($this->epcsFor($case, $session) as $epc) {
            if ($resolve->childEpcIdsForParent($session, $epc, (int) $preferred) === []) {
                return true;
            }
        }

        return false;
    }

    private function overageStillTrue(ExceptionCase $case): bool
    {
        $session = $this->sessionFor($case);
        $epcIds = $this->epcIdsFor($case, $session);
        if ($epcIds === []) {
            return true;
        }

        foreach ($epcIds as $epcId) {
            if (! $this->inboundDocumentContainsEpc($epcId)) {
                return true;
            }
        }

        return false;
    }

    private function dataNoProductStillTrue(ExceptionCase $case): bool
    {
        $session = $this->sessionFor($case);
        $epcIds = $this->epcIdsFor($case, $session);
        if ($epcIds === [] || $session === null) {
            return true;
        }

        $confirmed = ReceivingScanLine::query()
            ->where('receiving_session_id', $session->getKey())
            ->where('status', 'confirmed')
            ->whereIn('epc_id', $epcIds)
            ->pluck('epc_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        foreach ($epcIds as $epcId) {
            if ($this->inboundDocumentContainsEpc($epcId) && ! in_array($epcId, $confirmed, true)) {
                return true;
            }
        }

        return false;
    }

    private function duplicateStillTrue(ExceptionCase $case): bool
    {
        $session = $this->sessionFor($case);
        if ($session === null) {
            return true;
        }

        $duplicate = app(ReceiveDuplicateSerial::class);
        foreach ($this->epcsFor($case, $session) as $epc) {
            if ($duplicate->reason($session, $epc) !== null) {
                return true;
            }
        }

        return false;
    }

    private function quarantineStillOpen(ExceptionCase $case): bool
    {
        return QuarantineHold::query()
            ->open()
            ->where('exception_id', $case->getKey())
            ->exists();
    }

    public function sessionFor(ExceptionCase $case): ?ReceivingSession
    {
        $sessionId = ReceiveSessionExceptionQuery::sessionIdForCase($case);
        if ($sessionId !== null) {
            return ReceivingSession::query()->find($sessionId);
        }

        if ($case->document_id !== null) {
            return ReceivingSession::query()
                ->where(function ($query) use ($case): void {
                    $query->where('epcis_document_id', $case->document_id)
                        ->orWhere('matched_epcis_document_id', $case->document_id);
                })
                ->latest('id')
                ->first();
        }

        return null;
    }

    /**
     * @return list<int>
     */
    public function epcIdsFor(ExceptionCase $case, ?ReceivingSession $session = null): array
    {
        $ids = $case->epcs()->pluck('epcs.id')->map(fn ($id): int => (int) $id)->all();
        if ($ids !== []) {
            return array_values(array_unique($ids));
        }

        $session ??= $this->sessionFor($case);
        if ($session === null) {
            return [];
        }

        return ReceivingScanLine::query()
            ->where('receiving_session_id', $session->getKey())
            ->whereNotNull('epc_id')
            ->pluck('epc_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<Epc>
     */
    private function epcsFor(ExceptionCase $case, ?ReceivingSession $session = null): array
    {
        $ids = $this->epcIdsFor($case, $session);
        if ($ids === []) {
            return [];
        }

        return Epc::query()->whereIn('id', $ids)->get()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function scanIdentity(ReceivingSession $session, Epc $epc): array
    {
        $line = ReceivingScanLine::query()
            ->where('receiving_session_id', $session->getKey())
            ->where('epc_id', $epc->getKey())
            ->latest('id')
            ->first();

        $epc->loadMissing('ilmd');

        return [
            'gtin14' => $epc->gtin14,
            'serial' => $epc->serial_number,
            'lot_number' => $epc->ilmd?->lot_number,
            'scan_raw' => $line?->scan_raw,
        ];
    }

    private function sessionHasInboundFile(ReceivingSession $session): bool
    {
        foreach ([$session->epcis_document_id, $session->matched_epcis_document_id] as $documentId) {
            if ($documentId !== null && $this->inboundDocumentExists((int) $documentId)) {
                return true;
            }
        }

        return false;
    }

    private function inboundDocumentExists(int $documentId): bool
    {
        return EpcisDocument::query()
            ->whereKey($documentId)
            ->where('direction', 'inbound')
            ->whereIn('status', ['parsed', 'validated', 'accepted'])
            ->exists();
    }

    /**
     * @param  list<int>  $epcIds
     */
    private function inboundDocumentContainsAnyEpc(array $epcIds): bool
    {
        foreach ($epcIds as $epcId) {
            if ($this->inboundDocumentContainsEpc($epcId)) {
                return true;
            }
        }

        return false;
    }

    private function inboundDocumentContainsEpc(int $epcId): bool
    {
        if ($epcId <= 0) {
            return false;
        }

        if (Schema::hasTable('document_epcs')) {
            $onDocument = DB::table('document_epcs')
                ->join('epcis_documents', 'epcis_documents.id', '=', 'document_epcs.document_id')
                ->where('document_epcs.epc_id', $epcId)
                ->where('epcis_documents.direction', 'inbound')
                ->whereIn('epcis_documents.status', ['parsed', 'validated', 'accepted'])
                ->exists();
            if ($onDocument) {
                return true;
            }
        }

        if (! Schema::hasTable('event_epcs') || ! Schema::hasTable('epcis_events')) {
            return false;
        }

        return DB::table('event_epcs')
            ->join('epcis_events', 'epcis_events.id', '=', 'event_epcs.event_id')
            ->join('epcis_documents', 'epcis_documents.id', '=', 'epcis_events.document_id')
            ->where('event_epcs.epc_id', $epcId)
            ->where('epcis_documents.direction', 'inbound')
            ->whereIn('epcis_documents.status', ['parsed', 'validated', 'accepted'])
            ->exists();
    }
}
