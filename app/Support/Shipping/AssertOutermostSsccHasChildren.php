<?php

namespace App\Support\Shipping;

use App\Models\Epcis\AggregationLink;
use App\Models\Epcis\Epc;
use App\Models\SsccLabel;
use App\Models\SsccLabelChild;
use InvalidArgumentException;

/**
 * Empty tenant-issued SSCC labels are license plates, not shippable logistics units.
 * Inbound manufacturer SSCCs and corrective shipments are unchanged.
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

        $hasLabelChildren = SsccLabelChild::query()
            ->whereHas('label', function ($query) use ($epc): void {
                $query->where(function ($match) use ($epc): void {
                    $urn = trim((string) $epc->epc_uri);
                    $sscc18 = trim((string) $epc->sscc18);

                    if ($urn !== '') {
                        $match->orWhere('sscc_urn', $urn);
                    }

                    if ($sscc18 !== '') {
                        $match->orWhere('sscc_18', $sscc18);
                    }
                });
            })
            ->exists();

        if ($hasLabelChildren) {
            return;
        }

        throw new InvalidArgumentException(
            'This SSCC has no packed children. Pack items onto it before shipping or transferring.',
        );
    }
}
