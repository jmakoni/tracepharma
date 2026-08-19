<?php

namespace App\Support\Packing;

use App\Enums\SsccLabelBatchStatus;
use App\Models\Epcis\Epc;
use App\Support\Shipping\ShippableEpcsAtSite;
use Illuminate\Database\Eloquent\Builder;

/**
 * EPCs released by unpack (AggregationEvent DELETE / unpacking) that still have
 * no open parent aggregation link and are not claimed on a live SSCC pack label.
 *
 * Label exclusion mirrors {@see \App\Filament\App\Pages\PackWorkstation}
 * existingLabelConflictsForChildIds: a non-failed label child claim counts until
 * a closed aggregation link exists between that label's parent EPC and the child.
 */
final class UnpackedNotRepackedQuery
{
    /** @return Builder<Epc> */
    public static function builder(?int $siteId = null): Builder
    {
        $query = Epc::query()
            ->whereExists(function ($exists): void {
                $exists->selectRaw('1')
                    ->from('event_epcs')
                    ->join('epcis_events', 'epcis_events.id', '=', 'event_epcs.event_id')
                    ->whereColumn('event_epcs.epc_id', 'epcs.id')
                    ->where('event_epcs.role', 'childEPC')
                    ->where('epcis_events.event_type', 'AggregationEvent')
                    ->where('epcis_events.action', 'DELETE')
                    ->where(function ($biz): void {
                        $biz->where('epcis_events.biz_step', 'urn:epcglobal:cbv:bizstep:unpacking')
                            ->orWhere('epcis_events.biz_step', 'unpacking');
                    });
            })
            ->whereNotExists(function ($openParent): void {
                $openParent->selectRaw('1')
                    ->from('aggregation_links')
                    ->whereColumn('aggregation_links.child_epc_id', 'epcs.id')
                    ->whereNull('aggregation_links.valid_to');
            })
            ->whereNotExists(function ($labelClaim): void {
                $labelClaim->selectRaw('1')
                    ->from('sscc_label_children')
                    ->join('sscc_labels', 'sscc_labels.id', '=', 'sscc_label_children.sscc_label_id')
                    ->leftJoin('sscc_label_batches', 'sscc_label_batches.id', '=', 'sscc_labels.batch_id')
                    ->leftJoin('epcs as parent_epcs', 'parent_epcs.epc_uri', '=', 'sscc_labels.sscc_urn')
                    ->leftJoin('epcs as child_epcs', 'child_epcs.epc_uri', '=', 'sscc_label_children.child_epc')
                    ->whereColumn('sscc_label_children.child_epc', 'epcs.epc_uri')
                    ->where(function ($status): void {
                        $status->whereNull('sscc_label_batches.status')
                            ->orWhere('sscc_label_batches.status', '!=', SsccLabelBatchStatus::Failed->value);
                    })
                    ->whereNotExists(function ($closedLink): void {
                        $closedLink->selectRaw('1')
                            ->from('aggregation_links')
                            ->whereColumn('aggregation_links.parent_epc_id', 'parent_epcs.id')
                            ->whereColumn('aggregation_links.child_epc_id', 'child_epcs.id')
                            ->whereNotNull('aggregation_links.valid_to');
                    });
            });

        if ($siteId !== null) {
            self::applySiteConstraint($query, $siteId);
        }

        return $query;
    }

    /**
     * Restrict to on-hand / shippable inventory at a site.
     *
     * @param  Builder<Epc>  $query
     * @return Builder<Epc>
     */
    public static function applySiteConstraint(Builder $query, int $siteId): Builder
    {
        return $query->whereIn(
            'epcs.id',
            app(ShippableEpcsAtSite::class)->query($siteId)->select('epcs.id'),
        );
    }

    /**
     * Restrict to on-hand / shippable inventory at any of the given sites.
     *
     * @param  Builder<Epc>  $query
     * @param  list<int>  $siteIds
     * @return Builder<Epc>
     */
    public static function applySitesConstraint(Builder $query, array $siteIds): Builder
    {
        $siteIds = array_values(array_unique(array_map(static fn (mixed $id): int => (int) $id, $siteIds)));

        if ($siteIds === []) {
            return $query->whereRaw('0 = 1');
        }

        if (count($siteIds) === 1) {
            return self::applySiteConstraint($query, $siteIds[0]);
        }

        return $query->where(function (Builder $outer) use ($siteIds): void {
            foreach ($siteIds as $index => $siteId) {
                $method = $index === 0 ? 'whereIn' : 'orWhereIn';
                $outer->{$method}(
                    'epcs.id',
                    app(ShippableEpcsAtSite::class)->query($siteId)->select('epcs.id'),
                );
            }
        });
    }
}
