<?php

namespace App\Services\Receiving;

use App\Enums\ExceptionReceiveImpact;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Quarantine\QuarantineHold;
use App\Support\Exceptions\ExceptionReceiveImpactMap;

final class ReceivingGate
{
    /**
     * First open document-scoped exception that blocks receive for this file, if any.
     *
     * Only Hard / Blocking and Business Rule / Semantic types gate receiving.
     * Warning and Soft types surface in the exception UI but do not block ASN open.
     */
    public function documentBlockedByOpenException(EpcisDocument $document): ?ExceptionCase
    {
        $blockingImpacts = [
            ExceptionReceiveImpact::HardBlocking->value,
            ExceptionReceiveImpact::BusinessRule->value,
        ];
        $blockingCodes = $this->blockingTypeCodes();

        return ExceptionCase::query()
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
