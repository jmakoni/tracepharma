<?php

namespace App\Support\Epcis;

use App\Models\Epcis\EpcisEvent;
use App\Models\Epcis\EpcisEventArchive;
use App\Models\Epcis\EventBizTransaction;
use App\Models\Epcis\EventLocation;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Aged events moved off the hot table. Same TP-402 live-at-T rules as hot events.
 */
final class ArchivedEpcEvents
{
    /**
     * @return Collection<int, EpcisEvent>
     */
    public function forEpc(int $epcId, ?CarbonInterface $asOf = null): Collection
    {
        if ($epcId <= 0 || ! Schema::hasTable('epcis_events_archive') || ! Schema::hasTable('event_epcs_archive')) {
            return collect();
        }

        $query = EpcisEventArchive::query()
            ->whereIn('id', function ($sub) use ($epcId): void {
                $sub->select('event_id')
                    ->from('event_epcs_archive')
                    ->where('epc_id', $epcId);
            });

        if ($asOf !== null) {
            $asOfUtc = $asOf->copy()->utc();
            $query->where('event_time', '<=', $asOfUtc->toDateTimeString());
            $query->where(function ($live) use ($asOfUtc): void {
                $live->whereNull('superseded_at')
                    ->orWhere('superseded_at', '>', $asOfUtc->toDateTimeString());
            });
        } else {
            $query->whereNull('superseded_at');

            // Match hot eventsQuery last-good document projection (exclude voided / hard-error gens).
            if (Schema::hasTable('epcis_documents')) {
                $query->where(function ($documents): void {
                    $documents->whereNull('document_id')
                        ->orWhereExists(function ($exists): void {
                            $exists->selectRaw('1')
                                ->from('epcis_documents')
                                ->whereColumn('epcis_documents.id', 'epcis_events_archive.document_id');

                            if (Schema::hasColumn('epcis_documents', 'ingest_generation')
                                && Schema::hasColumn('epcis_events_archive', 'ingest_generation')) {
                                $exists->whereColumn(
                                    'epcis_events_archive.ingest_generation',
                                    'epcis_documents.ingest_generation',
                                );
                            }

                            LastGoodIngestProjection::constrainDocuments(
                                $exists,
                                successfulStatuses: ['parsed', 'validated', 'received', 'generated'],
                            );
                        });
                });
            }
        }

        return $query
            ->with('document')
            ->orderBy('event_time')
            ->orderBy('id')
            ->get()
            ->map(fn (EpcisEventArchive $row): EpcisEvent => $this->hydrate($row))
            ->values();
    }

    private function hydrate(EpcisEventArchive $row): EpcisEvent
    {
        $event = new EpcisEvent;
        $attributes = $row->getAttributes();
        unset($attributes['archived_at']);
        $event->setRawAttributes($attributes, true);
        $event->exists = true;

        $eventId = (int) $event->getKey();
        $event->setRelation('locations', $this->hydrateLocations($eventId));
        $event->setRelation('bizTransactions', $this->hydrateBizTransactions($eventId));

        if ($row->relationLoaded('document')) {
            $event->setRelation('document', $row->document);
        }

        return $event;
    }

    /**
     * @return Collection<int, EventLocation>
     */
    private function hydrateLocations(int $eventId): Collection
    {
        if ($eventId <= 0 || ! Schema::hasTable('event_locations_archive')) {
            return collect();
        }

        return DB::table('event_locations_archive')
            ->where('event_id', $eventId)
            ->orderBy('id')
            ->get()
            ->map(function (object $row): EventLocation {
                $model = new EventLocation;
                $model->setRawAttributes((array) $row, true);
                $model->exists = true;

                return $model;
            })
            ->values();
    }

    /**
     * @return Collection<int, EventBizTransaction>
     */
    private function hydrateBizTransactions(int $eventId): Collection
    {
        if ($eventId <= 0 || ! Schema::hasTable('event_biz_transactions_archive')) {
            return collect();
        }

        return DB::table('event_biz_transactions_archive')
            ->where('event_id', $eventId)
            ->orderBy('id')
            ->get()
            ->map(function (object $row): EventBizTransaction {
                $model = new EventBizTransaction;
                $model->setRawAttributes((array) $row, true);
                $model->exists = true;

                return $model;
            })
            ->values();
    }
}
