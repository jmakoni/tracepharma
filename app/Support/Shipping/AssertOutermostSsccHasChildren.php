<?php

namespace App\Support\Shipping;

use App\Models\Epcis\AggregationLink;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisEvent;
use App\Models\SsccLabel;
use App\Support\Custody\ResolveEpcLastKnownGln;
use App\Support\Custody\TerminalEpcDisposition;
use Illuminate\Support\Facades\DB;

/**
 * Empty tenant-issued SSCC labels are license plates, not shippable logistics units.
 * Completeness is set equality: open aggregation_links (minus terminal dispositions)
 * vs cumulative non-superseded AggregationEvent ADD childEPCs minus later DELETEs.
 * Inbound manufacturer SSCCs without a label row are unchanged.
 */
final class AssertOutermostSsccHasChildren
{
    public function __construct(
        private readonly ResolveEpcLastKnownGln $resolveEpcLastKnownGln,
    ) {}

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

        $parentId = (int) $epc->getKey();
        $actual = $this->presentOpenChildIds($parentId);
        $expected = $this->cumulativeExpectedChildIds($parentId);

        sort($actual);
        sort($expected);

        if ($actual === $expected) {
            if ($expected === []) {
                throw new SsccShipCompletenessException(
                    'This SSCC has no packed children. Pack items onto it before shipping or transferring.',
                    'MISSING_CHILDREN',
                    $parentId,
                );
            }

            return;
        }

        $missing = array_values(array_diff($expected, $actual));
        $extra = array_values(array_diff($actual, $expected));
        $ssccId = trim((string) ($epc->sscc18 ?: $epc->epc_uri)) ?: (string) $parentId;

        if ($expected === []) {
            throw new SsccShipCompletenessException(
                sprintf(
                    'SSCC %s has open children but no establishing AggregationEvent ADD history (%d unexpected).',
                    $ssccId,
                    count($extra),
                ),
                'BROKEN_AGGREGATION',
                $parentId,
                $extra,
            );
        }

        throw new SsccShipCompletenessException(
            sprintf(
                'SSCC %s hierarchy is incomplete for shipping (missing %d, unexpected %d).',
                $ssccId,
                count($missing),
                count($extra),
            ),
            'BROKEN_AGGREGATION',
            $parentId,
            array_values(array_unique([...$missing, ...$extra])),
        );
    }

    /**
     * Open aggregation_links under the parent, excluding children whose latest trackable
     * disposition is terminal (decommissioned / destroyed / etc.).
     *
     * @return list<int>
     */
    private function presentOpenChildIds(int $parentEpcId): array
    {
        $openIds = AggregationLink::query()
            ->open()
            ->where('parent_epc_id', $parentEpcId)
            ->pluck('child_epc_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($openIds === []) {
            return [];
        }

        $metas = $this->resolveEpcLastKnownGln->latestEventMetaForEpcIds($openIds);

        return array_values(array_filter(
            $openIds,
            static fn (int $id): bool => ! TerminalEpcDisposition::matches($metas[$id] ?? null),
        ));
    }

    /**
     * Cumulative packing set: all non-superseded AggregationEvent ADD childEPCs for this
     * parent, minus children removed by a later non-superseded DELETE (empty DELETE = all).
     *
     * @return list<int>
     */
    private function cumulativeExpectedChildIds(int $parentEpcId): array
    {
        $events = EpcisEvent::query()
            ->notSuperseded()
            ->where('event_type', 'AggregationEvent')
            ->whereRaw('UPPER(COALESCE(action, "")) IN (?, ?)', ['ADD', 'DELETE'])
            ->whereExists(function ($query) use ($parentEpcId): void {
                $query->selectRaw('1')
                    ->from('event_epcs')
                    ->whereColumn('event_epcs.event_id', 'epcis_events.id')
                    ->where('event_epcs.role', 'parentID')
                    ->where('event_epcs.epc_id', $parentEpcId);
            })
            ->orderBy('event_time')
            ->orderBy('id')
            ->get(['id', 'action']);

        /** @var array<int, true> $expected */
        $expected = [];

        foreach ($events as $event) {
            $childIds = DB::table('event_epcs')
                ->where('event_id', $event->getKey())
                ->where('role', 'childEPC')
                ->pluck('epc_id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();

            $action = strtoupper(trim((string) $event->action));

            if ($action === 'ADD') {
                foreach ($childIds as $childId) {
                    $expected[$childId] = true;
                }

                continue;
            }

            if ($action === 'DELETE') {
                if ($childIds === []) {
                    $expected = [];
                } else {
                    foreach ($childIds as $childId) {
                        unset($expected[$childId]);
                    }
                }
            }
        }

        return array_map(static fn (int|string $id): int => (int) $id, array_keys($expected));
    }
}
