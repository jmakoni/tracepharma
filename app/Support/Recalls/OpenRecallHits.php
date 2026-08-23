<?php

namespace App\Support\Recalls;

use App\Enums\TracingRequestStatus;
use App\Models\Epcis\Epc;
use App\Models\TracingRequest;
use App\Support\Shipping\ShippableEpcsAtSite;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Site-scoped open-recall hits for the new reconciliation page.
 * Matching stays aligned with OpenRecallFlag. Find/Recall is unchanged.
 */
final class OpenRecallHits
{
    public const DISPLAY_CAP = 400;

    public function __construct(
        private readonly ShippableEpcsAtSite $onHand,
    ) {}

    /**
     * @return Collection<int, Epc>
     */
    public function epcsAtSite(int $siteId, ?int $cap = null): Collection
    {
        $cap ??= self::DISPLAY_CAP;

        return $this->matchingQuery($siteId)
            ->with('ilmd')
            ->orderBy('epcs.id')
            ->limit($cap)
            ->get()
            ->values();
    }

    public function isTruncated(int $siteId, ?int $cap = null): bool
    {
        $cap ??= self::DISPLAY_CAP;

        return $this->matchingQuery($siteId)->count() > $cap;
    }

    /**
     * @return Builder<Epc>
     */
    private function matchingQuery(int $siteId): Builder
    {
        $recalls = TracingRequest::query()
            ->where('is_recall', true)
            ->whereIn('status', [TracingRequestStatus::Open, TracingRequestStatus::InProgress])
            ->get(['gtin', 'serial', 'lot']);

        $identities = [];
        foreach ($recalls as $recall) {
            $gtin = filled($recall->gtin) ? (string) $recall->gtin : null;
            if ($gtin === null) {
                continue;
            }
            $identities[] = [
                'gtin' => $gtin,
                'serial' => filled($recall->serial) ? (string) $recall->serial : null,
                'lot' => filled($recall->lot) ? (string) $recall->lot : null,
            ];
        }

        if ($identities === []) {
            return Epc::query()->whereRaw('0 = 1');
        }

        return $this->onHand->query($siteId)
            ->where(function (Builder $query) use ($identities): void {
                foreach ($identities as $identity) {
                    $gtin = $identity['gtin'];
                    $serial = $identity['serial'];
                    $lot = $identity['lot'];

                    $query->orWhere(function (Builder $match) use ($gtin, $serial, $lot): void {
                        if ($serial !== null) {
                            $match->orWhere(function (Builder $serialMatch) use ($gtin, $serial): void {
                                $serialMatch->where('epcs.gtin14', $gtin)
                                    ->where('epcs.serial_number', $serial)
                                    ->where(function (Builder $notSscc): void {
                                        $notSscc->where('epcs.epc_type', '!=', 'sscc')
                                            ->orWhereNull('epcs.epc_type');
                                    })
                                    ->where('epcs.epc_uri', 'not like', 'urn:epc:id:sscc:%');
                            });
                        }

                        if ($lot !== null) {
                            $match->orWhere(function (Builder $lotMatch) use ($gtin, $lot): void {
                                $lotMatch->where('epcs.gtin14', $gtin)
                                    ->whereHas('ilmd', fn (Builder $ilmd) => $ilmd->where('lot_number', $lot));
                            });
                        }

                        if ($serial === null && $lot === null) {
                            $match->orWhere('epcs.gtin14', $gtin);
                        }
                    });
                }
            });
    }
}
