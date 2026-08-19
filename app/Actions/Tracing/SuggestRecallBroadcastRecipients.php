<?php

declare(strict_types=1);

namespace App\Actions\Tracing;

use App\Models\Epcis\EpcisDocument;
use App\Models\TracingRequest;
use App\Models\TradingPartner;
use Illuminate\Support\Collection;

class SuggestRecallBroadcastRecipients
{
    /**
     * @return Collection<int, TradingPartner>
     */
    public function execute(TracingRequest $request): Collection
    {
        $gtin = $this->normalizeGtin14($request->gtin);

        if ($gtin === null) {
            return collect();
        }

        $lot = filled($request->lot) ? trim((string) $request->lot) : null;

        $partnerIds = EpcisDocument::query()
            ->where('direction', 'outbound')
            ->whereNotNull('ship_to_partner_id')
            ->whereHas('documentEpcs.epc', function ($query) use ($gtin, $lot): void {
                $query->where('gtin14', $gtin);

                if ($lot !== null) {
                    $query->whereHas('ilmd', fn ($ilmd) => $ilmd->where('lot_number', $lot));
                }
            })
            ->distinct()
            ->pluck('ship_to_partner_id');

        if ($partnerIds->isEmpty()) {
            return collect();
        }

        return TradingPartner::query()
            ->whereIn('id', $partnerIds)
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->orderBy('name')
            ->get();
    }

    private function normalizeGtin14(mixed $gtin): ?string
    {
        if (! filled($gtin)) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', (string) $gtin) ?? '';

        if ($digits === '') {
            return null;
        }

        return str_pad($digits, 14, '0', STR_PAD_LEFT);
    }
}
