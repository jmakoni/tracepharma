<?php

namespace App\Support\Shipping;

use App\Models\Epcis\AggregationLink;
use App\Models\Epcis\Epc;
use App\Models\SsccLabel;
use InvalidArgumentException;

/**
 * Empty tenant-issued SSCC labels are license plates, not shippable logistics units.
 * Live contents are open aggregation_links — leftover sscc_label_children after unpack
 * do not count. Inbound manufacturer SSCCs without a label row are unchanged.
 */
final class AssertOutermostSsccHasChildren
{
    public function handle(Epc $epc): void
    {
        if (($epc->epc_type ?? null) !== 'sscc') {
            return;
        }

        $labelQuery = SsccLabel::query()->where(function ($query) use ($epc): void {
            $urn = trim((string) $epc->epc_uri);
            $sscc18 = trim((string) $epc->sscc18);

            if ($urn !== '') {
                $query->orWhere('sscc_urn', $urn);
            }

            if ($sscc18 !== '') {
                $query->orWhere('sscc_18', $sscc18);
            }
        });

        if (! $labelQuery->exists()) {
            return;
        }

        $hasOpenChildren = AggregationLink::query()
            ->open()
            ->where('parent_epc_id', $epc->getKey())
            ->exists();

        if ($hasOpenChildren) {
            return;
        }

        throw new InvalidArgumentException(
            'This SSCC has no packed children. Pack items onto it before shipping or transferring.',
        );
    }
}
