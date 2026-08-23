<?php

namespace App\Support\Epcis\Exceptions;

use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisException;
use App\Models\Epcis\EpcisExceptionGroup;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Collapse document exception signals to one row per type + status,
 * with GTIN/SSCC counts (identifiers themselves belong on the case EPC table).
 */
final class GroupDocumentExceptionSignals
{
    /**
     * @return Collection<int, EpcisExceptionGroup>
     */
    public function handle(EpcisDocument $document): Collection
    {
        $signals = EpcisException::query()
            ->where('document_id', $document->getKey())
            ->orderBy('id')
            ->get([
                'id',
                'case_id',
                'event_id',
                'epc_id',
                'exception_type',
                'severity',
                'description',
                'status',
                'created_at',
            ]);

        if ($signals->isEmpty()) {
            return collect();
        }

        return $signals
            ->groupBy(fn (EpcisException $signal): string => (string) $signal->exception_type.'|'.(string) $signal->status)
            ->map(fn (Collection $group): EpcisExceptionGroup => $this->toGroup($document, $group))
            ->sortByDesc(fn (EpcisExceptionGroup $row): mixed => $row->created_at)
            ->values();
    }

    /**
     * @param  Collection<int, EpcisException>  $group
     */
    private function toGroup(EpcisDocument $document, Collection $group): EpcisExceptionGroup
    {
        $first = $group->first();
        $epcIds = $group->pluck('epc_id')->filter()->unique()->map(fn ($id) => (int) $id)->values();
        $eventIds = $group->pluck('event_id')->filter()->unique()->map(fn ($id) => (int) $id)->values();
        $itemLevel = $epcIds->isNotEmpty() || $eventIds->isNotEmpty();

        $identifiers = $itemLevel
            ? $this->itemIdentifiers($epcIds->all(), $eventIds->all())
            : $this->fileIdentifiers($document);

        $gtins = $identifiers['gtins'];
        $ssccs = $identifiers['ssccs'];
        $descriptions = $group->pluck('description')->filter()->unique()->values();

        $model = new EpcisExceptionGroup;
        $model->forceFill([
            'id' => (string) $first->exception_type.'|'.(string) $first->status,
            'exception_type' => $first->exception_type,
            'severity' => $this->worstSeverity($group->pluck('severity')->all()),
            'status' => $first->status,
            'description' => $descriptions->implode('; '),
            'created_at' => $group->max('created_at'),
            'case_id' => $group->pluck('case_id')->filter()->first(),
            'signal_id' => $group->firstWhere('status', 'open')?->getKey() ?? $first->getKey(),
            'scope' => $itemLevel ? 'items' : 'file',
            'event_count' => $itemLevel ? $eventIds->count() : (int) $document->event_count,
            'epc_count' => $itemLevel ? $epcIds->count() : (int) $document->epc_count,
            'gtins' => $gtins,
            'ssccs' => $ssccs,
            'scope_display' => $itemLevel
                ? $this->itemScopeLabel($eventIds->count(), $epcIds->count())
                : 'Entire file',
            'gtin_display' => $this->countDisplay($gtins, $ssccs),
            'gtin_label' => $itemLevel ? 'Failed identifiers' : 'Identifiers in file',
        ]);
        $model->syncOriginal();
        $model->exists = false;

        return $model;
    }

    /**
     * @param  list<int>  $epcIds
     * @param  list<int>  $eventIds
     * @return array{gtins: list<string>, ssccs: list<string>}
     */
    private function itemIdentifiers(array $epcIds, array $eventIds): array
    {
        $hasEvents = $eventIds !== [] && Schema::hasTable('event_epcs');
        if ($epcIds === [] && ! $hasEvents) {
            return ['gtins' => [], 'ssccs' => []];
        }

        $query = DB::table('epcs')->select(['gtin14', 'sscc18']);

        $query->where(function ($outer) use ($epcIds, $eventIds, $hasEvents): void {
            if ($epcIds !== []) {
                $outer->orWhereIn('id', $epcIds);
            }

            if ($hasEvents) {
                $outer->orWhereIn('id', function ($sub) use ($eventIds): void {
                    $sub->select('event_epcs.epc_id')
                        ->from('event_epcs')
                        ->whereIn('event_epcs.event_id', $eventIds);
                });
            }
        });

        return $this->identifiersFromRows($query->get());
    }

    /**
     * @return array{gtins: list<string>, ssccs: list<string>}
     */
    private function fileIdentifiers(EpcisDocument $document): array
    {
        return $this->identifiersFromRows(
            $document->epcsQuery()->get(['epcs.gtin14', 'epcs.sscc18']),
        );
    }

    /**
     * @param  iterable<int, object>  $rows
     * @return array{gtins: list<string>, ssccs: list<string>}
     */
    private function identifiersFromRows(iterable $rows): array
    {
        $gtins = [];
        $ssccs = [];

        foreach ($rows as $row) {
            $gtin = (string) ($row->gtin14 ?? '');
            if ($gtin !== '') {
                $gtins[$gtin] = $gtin;
            }

            $sscc = (string) ($row->sscc18 ?? '');
            if ($sscc !== '') {
                $ssccs[$sscc] = $sscc;
            }
        }

        $gtins = array_values($gtins);
        $ssccs = array_values($ssccs);
        sort($gtins);
        sort($ssccs);

        return ['gtins' => $gtins, 'ssccs' => $ssccs];
    }

    /**
     * @param  list<string|null>  $severities
     */
    private function worstSeverity(array $severities): string
    {
        $rank = ['critical' => 4, 'error' => 3, 'warning' => 2, 'info' => 1];
        $worst = 'info';
        $worstRank = 0;
        foreach ($severities as $severity) {
            $key = strtolower((string) $severity);
            $value = $rank[$key] ?? 0;
            if ($value > $worstRank) {
                $worst = $key !== '' ? $key : 'info';
                $worstRank = $value;
            }
        }

        return $worst;
    }

    private function itemScopeLabel(int $events, int $epcs): string
    {
        $parts = [];
        if ($events > 0) {
            $parts[] = $events.' '.($events === 1 ? 'event' : 'events');
        }
        if ($epcs > 0) {
            $parts[] = $epcs.' '.($epcs === 1 ? 'EPC' : 'EPCs');
        }

        return $parts === [] ? 'Items' : implode(' · ', $parts);
    }

    /**
     * @param  list<string>  $gtins
     * @param  list<string>  $ssccs
     */
    private function countDisplay(array $gtins, array $ssccs): string
    {
        $parts = [];
        $gtinCount = count($gtins);
        $ssccCount = count($ssccs);

        if ($gtinCount > 0) {
            $parts[] = $gtinCount.' '.($gtinCount === 1 ? 'GTIN' : 'GTINs');
        }
        if ($ssccCount > 0) {
            $parts[] = $ssccCount.' '.($ssccCount === 1 ? 'SSCC' : 'SSCCs');
        }

        return $parts === [] ? '—' : implode(' · ', $parts);
    }
}
