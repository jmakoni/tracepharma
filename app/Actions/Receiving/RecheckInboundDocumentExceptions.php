<?php

declare(strict_types=1);

namespace App\Actions\Receiving;

use App\Actions\Exceptions\RecheckReceiveExceptionCondition;
use App\Models\Epcis\EpcisDocument;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Receiving\ReceivingScanLine;
use App\Models\User;
use App\Support\Receiving\ReceiveExceptionCondition;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class RecheckInboundDocumentExceptions
{
    public function __construct(
        private readonly RecheckReceiveExceptionCondition $recheck,
    ) {}

    /**
     * @return list<ExceptionCase>
     */
    public function handle(EpcisDocument $document, ?User $actor = null): array
    {
        if ((string) ($document->direction ?? '') !== 'inbound') {
            return [];
        }

        $epcIds = $this->documentEpcIds($document);
        $sessionIds = $this->sessionIdsForEpcs($epcIds);

        $cases = ExceptionCase::query()
            ->open()
            ->whereHas(
                'type',
                fn (Builder $types): Builder => $types->whereIn(
                    'code',
                    ReceiveExceptionCondition::AUTO_RECHECK_TYPES,
                ),
            )
            ->where(function (Builder $query) use ($document, $epcIds, $sessionIds): void {
                $query->where('document_id', $document->getKey());

                if ($epcIds !== []) {
                    $query->orWhereHas(
                        'epcs',
                        fn (Builder $epcs): Builder => $epcs->whereIn('epcs.id', $epcIds),
                    );
                }

                if ($sessionIds !== []) {
                    $query->orWhereHas('activities', function (Builder $activities) use ($sessionIds): void {
                        $activities->where(function (Builder $meta) use ($sessionIds): void {
                            $meta->whereIn('meta->receiving_session_id', $sessionIds);
                            foreach ($sessionIds as $sessionId) {
                                $meta->orWhere('meta->receiving_session_id', (string) $sessionId);
                            }
                        });
                    });
                }
            })
            ->get();

        $checked = [];
        foreach ($cases as $case) {
            $checked[] = $this->recheck->handle($case, $actor);
        }

        return $checked;
    }

    /**
     * @return list<int>
     */
    private function documentEpcIds(EpcisDocument $document): array
    {
        $ids = [];

        if (Schema::hasTable('document_epcs')) {
            $ids = array_merge(
                $ids,
                DB::table('document_epcs')
                    ->where('document_id', $document->getKey())
                    ->pluck('epc_id')
                    ->map(fn ($id): int => (int) $id)
                    ->all(),
            );
        }

        if (Schema::hasTable('event_epcs') && Schema::hasTable('epcis_events')) {
            $ids = array_merge(
                $ids,
                DB::table('event_epcs')
                    ->join('epcis_events', 'epcis_events.id', '=', 'event_epcs.event_id')
                    ->where('epcis_events.document_id', $document->getKey())
                    ->pluck('event_epcs.epc_id')
                    ->map(fn ($id): int => (int) $id)
                    ->all(),
            );
        }

        return array_values(array_unique(array_filter($ids)));
    }

    /**
     * @param  list<int>  $epcIds
     * @return list<int>
     */
    private function sessionIdsForEpcs(array $epcIds): array
    {
        if ($epcIds === []) {
            return [];
        }

        return ReceivingScanLine::query()
            ->whereIn('epc_id', $epcIds)
            ->pluck('receiving_session_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}
