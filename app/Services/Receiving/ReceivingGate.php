<?php

namespace App\Services\Receiving;

use App\Actions\Epcis\RecordDestinationGlnMismatch;
use App\Actions\Exceptions\SyncDestinationGlnMismatchReceiveImpact;
use App\Enums\ExceptionReceiveImpact;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisException;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Quarantine\QuarantineHold;
use App\Services\Exceptions\ExceptionService;
use App\Support\Exceptions\ExceptionReceiveImpactMap;
use App\Support\TenantSettings;

final class ReceivingGate
{
    /**
     * First open document-scoped exception that blocks receive for this file, if any.
     *
     * Only Hard / Blocking and Business Rule / Semantic types gate receiving.
     * Warning and Soft types surface in the exception UI but do not block ASN open.
     *
     * Phase 2: when destination GLN block-receive is on, open DESTINATION_* ingest
     * signals are promoted to cases so they participate in the same gate.
     */
    public function documentBlockedByOpenException(EpcisDocument $document): ?ExceptionCase
    {
        $blockingImpacts = [
            ExceptionReceiveImpact::HardBlocking->value,
            ExceptionReceiveImpact::BusinessRule->value,
        ];
        $blockingCodes = $this->blockingTypeCodes();

        $case = ExceptionCase::query()
            ->open()
            ->where('document_id', $document->getKey())
            ->whereDoesntHave('epcs')
            ->whereHas('type', function ($query) use ($blockingImpacts, $blockingCodes): void {
                $query->where(function ($inner) use ($blockingImpacts, $blockingCodes): void {
                    $inner->whereIn('receive_impact', $blockingImpacts)
                        ->orWhere(function ($legacy) use ($blockingCodes): void {
                            $legacy->whereNull('receive_impact')
                                ->whereIn('code', $blockingCodes);
                        });
                });
            })
            ->with('type:id,name,code,receive_impact')
            ->orderBy('id')
            ->first();

        if ($case !== null) {
            return $case;
        }

        return $this->blockingDestinationGlnMismatchCase($document);
    }

    /**
     * Re-derive destination GLN mismatch then evaluate the receive gate.
     * Use on open receive / Start Receiving — not on every scan confirm.
     */
    public function documentBlockedAfterDestinationRecheck(EpcisDocument $document): ?ExceptionCase
    {
        app(RecordDestinationGlnMismatch::class)->handle($document);

        return $this->documentBlockedByOpenException($document);
    }

    /**
     * Safety net when the tenant setting is on but a DESTINATION_* signal was not
     * yet promoted (e.g. setting flipped after ingest without a full sync).
     */
    private function blockingDestinationGlnMismatchCase(EpcisDocument $document): ?ExceptionCase
    {
        $tenant = tenant();
        if ($tenant === null || ! TenantSettings::forTenant($tenant)->blockReceiveOnDestinationGlnMismatch()) {
            return null;
        }

        $signal = EpcisException::query()
            ->where('document_id', $document->getKey())
            ->whereIn('exception_type', SyncDestinationGlnMismatchReceiveImpact::CODES)
            ->where('status', 'open')
            ->orderBy('id')
            ->first();

        if ($signal === null) {
            return null;
        }

        $case = app(ExceptionService::class)->createFromSignal($signal);

        if (! $case->status->isOpen()) {
            return null;
        }

        $case->loadMissing('type:id,name,code,receive_impact');

        return $case->type?->blocksReceiving() ? $case : null;
    }

    /**
     * Open quarantine hold for this EPC, if any (blocks confirming the unit on receive).
     */
    public function epcBlockedByOpenHold(Epc $epc): ?QuarantineHold
    {
        return QuarantineHold::query()
            ->open()
            ->where('epc_id', $epc->getKey())
            ->with('exception:id,title,status')
            ->orderBy('id')
            ->first();
    }

    /**
     * EPC ids (of those given) carrying an open quarantine hold — one query for bulk gates.
     *
     * @param  list<int>  $epcIds
     * @return list<int>
     */
    public function epcIdsBlockedByOpenHold(array $epcIds): array
    {
        $epcIds = array_values(array_unique(array_filter(
            array_map('intval', $epcIds),
            fn (int $id): bool => $id > 0,
        )));

        if ($epcIds === []) {
            return [];
        }

        return QuarantineHold::query()
            ->open()
            ->whereIn('epc_id', $epcIds)
            ->distinct()
            ->pluck('epc_id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function blockingTypeCodes(): array
    {
        $codes = [];
        foreach (ExceptionReceiveImpactMap::all() as $code => $impact) {
            if ($impact->blocksReceiving()) {
                $codes[] = $code;
            }
        }

        return $codes;
    }
}
