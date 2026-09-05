<?php

namespace App\Services\Custody;

use App\Actions\Epcis\ResolveEpcFromScan;
use App\Models\Epcis\Epc;
use App\Support\Custody\ResolveEpcLastKnownGln;
use App\Support\Custody\TerminalEpcDisposition;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Point-in-time custody snapshot for an EPC at a UTC instant.
 *
 * Uses events that were live at T (event_time ≤ T and generation not yet
 * soft-superseded at T). When multiple ingest generations are still live,
 * the lowest generation wins so a superseded gen answers for as_of in its window.
 */
final class ResolveEpcCustodyAsOf
{
    public function __construct(
        private readonly ResolveEpcFromScan $resolveEpcFromScan,
    ) {}

    /**
     * @return array{
     *     found: bool,
     *     as_of: string,
     *     status: string,
     *     status_tone: string,
     *     disposition: ?string,
     *     disposition_uri: ?string,
     *     biz_step: ?string,
     *     gln: ?string,
     *     event_id: ?int,
     *     event_time: ?string,
     *     ingest_generation: ?int
     * }
     */
    public function forScan(string $scan, DateTimeInterface $asOfUtc): array
    {
        $resolved = $this->resolveEpcFromScan->handle($scan);
        $epc = $resolved['epc'];

        if (! $epc instanceof Epc) {
            return $this->emptyResult($asOfUtc, found: false);
        }

        return $this->handle($epc, $asOfUtc);
    }

    /**
     * @return array{
     *     found: bool,
     *     as_of: string,
     *     status: string,
     *     status_tone: string,
     *     disposition: ?string,
     *     disposition_uri: ?string,
     *     biz_step: ?string,
     *     gln: ?string,
     *     event_id: ?int,
     *     event_time: ?string,
     *     ingest_generation: ?int
     * }
     */
    public function handle(Epc $epc, DateTimeInterface $asOfUtc): array
    {
        $asOf = Carbon::parse($asOfUtc)->utc();
        $rows = $this->eventsLiveAt($epc, $asOf);

        if ($rows === []) {
            return $this->emptyResult($asOf, found: true);
        }

        $latest = $rows[0];
        $dispositionUri = self::nullableString($latest->disposition);
        $dispositionLabel = $dispositionUri !== null
            ? TerminalEpcDisposition::label($dispositionUri)
            : null;
        $bizStep = self::nullableString($latest->biz_step);
        $gln = ResolveEpcLastKnownGln::preferredGln(
            self::nullableString($latest->biz_location_gln),
            self::nullableString($latest->read_point_gln),
        );

        if (TerminalEpcDisposition::isTerminal($dispositionUri)) {
            $label = TerminalEpcDisposition::label($dispositionUri);

            return [
                'found' => true,
                'as_of' => $asOf->toIso8601String(),
                'status' => ucfirst($label),
                'status_tone' => 'warn',
                'disposition' => $label,
                'disposition_uri' => $dispositionUri,
                'biz_step' => $bizStep,
                'gln' => $gln,
                'event_id' => (int) $latest->id,
                'event_time' => Carbon::parse($latest->event_time)->utc()->toIso8601String(),
                'ingest_generation' => isset($latest->ingest_generation) ? (int) $latest->ingest_generation : null,
            ];
        }

        if ($this->isCommissionedActiveAt($rows)) {
            return [
                'found' => true,
                'as_of' => $asOf->toIso8601String(),
                'status' => 'Commissioned',
                'status_tone' => 'ok',
                'disposition' => $dispositionLabel ?? 'active',
                'disposition_uri' => $dispositionUri ?? 'urn:epcglobal:cbv:disp:active',
                'biz_step' => $bizStep,
                'gln' => $gln,
                'event_id' => (int) $latest->id,
                'event_time' => Carbon::parse($latest->event_time)->utc()->toIso8601String(),
                'ingest_generation' => isset($latest->ingest_generation) ? (int) $latest->ingest_generation : null,
            ];
        }

        $inTransit = $bizStep !== null && (
            str_contains(strtolower($bizStep), 'shipping')
            || str_contains(strtolower($bizStep), 'transit')
        );

        return [
            'found' => true,
            'as_of' => $asOf->toIso8601String(),
            'status' => $inTransit ? 'In transit' : ($gln !== null ? 'In custody' : 'Unknown'),
            'status_tone' => $inTransit ? 'warn' : 'ok',
            'disposition' => $dispositionLabel,
            'disposition_uri' => $dispositionUri,
            'biz_step' => $bizStep,
            'gln' => $gln,
            'event_id' => (int) $latest->id,
            'event_time' => Carbon::parse($latest->event_time)->utc()->toIso8601String(),
            'ingest_generation' => isset($latest->ingest_generation) ? (int) $latest->ingest_generation : null,
        ];
    }

    /**
     * Events live at T for this EPC, newest first, filtered to the generation
     * that was active at T per document (lowest live ingest_generation).
     *
     * @return list<object{
     *     id: int|string,
     *     event_time: mixed,
     *     biz_step: ?string,
     *     disposition: ?string,
     *     read_point_gln: ?string,
     *     biz_location_gln: ?string,
     *     document_id: ?int|string,
     *     ingest_generation: ?int|string,
     *     action: ?string,
     *     event_type: ?string
     * }>
     */
    public function eventsLiveAt(Epc $epc, DateTimeInterface $asOfUtc): array
    {
        $asOf = Carbon::parse($asOfUtc)->utc();
        $epcId = (int) $epc->getKey();

        $columns = [
            'ev.id',
            'ev.event_time',
            'ev.biz_step',
            'ev.disposition',
            'ev.read_point_gln',
            'ev.biz_location_gln',
            'ev.document_id',
            'ev.action',
            'ev.event_type',
            'ev.ingest_generation',
        ];

        $query = DB::table('event_epcs as ee')
            ->join('epcis_events as ev', 'ev.id', '=', 'ee.event_id')
            ->leftJoin('epcis_documents as doc', 'doc.id', '=', 'ev.document_id')
            ->where('ee.epc_id', $epcId)
            ->where('ev.event_time', '<=', $asOf->toDateTimeString())
            ->where(fn ($status) => $status->whereNull('doc.status')->orWhereNotIn('doc.status', ['error', 'voided']));

        if (Schema::hasColumn('epcis_events', 'superseded_at')) {
            $query->where(function ($q) use ($asOf): void {
                $q->whereNull('ev.superseded_at')
                    ->orWhere('ev.superseded_at', '>', $asOf->toDateTimeString());
            });
        }

        $candidates = $query
            ->orderByDesc('ev.event_time')
            ->orderByDesc('ev.id')
            ->get($columns);

        if (Schema::hasTable('epcis_events_archive') && Schema::hasTable('event_epcs_archive')) {
            $archiveQuery = DB::table('event_epcs_archive as ee')
                ->join('epcis_events_archive as ev', 'ev.id', '=', 'ee.event_id')
                ->leftJoin('epcis_documents as doc', 'doc.id', '=', 'ev.document_id')
                ->where('ee.epc_id', $epcId)
                ->where('ev.event_time', '<=', $asOf->toDateTimeString())
                ->where(fn ($status) => $status->whereNull('doc.status')->orWhereNotIn('doc.status', ['error', 'voided']));

            if (Schema::hasColumn('epcis_events_archive', 'superseded_at')) {
                $archiveQuery->where(function ($q) use ($asOf): void {
                    $q->whereNull('ev.superseded_at')
                        ->orWhere('ev.superseded_at', '>', $asOf->toDateTimeString());
                });
            }

            $candidates = $candidates
                ->concat($archiveQuery->orderByDesc('ev.event_time')->orderByDesc('ev.id')->get($columns))
                ->unique('id')
                ->sortByDesc(fn (object $row): array => [
                    (string) $row->event_time,
                    (int) $row->id,
                ])
                ->values();
        }

        if ($candidates->isEmpty()) {
            return [];
        }

        if (! Schema::hasColumn('epcis_events', 'ingest_generation')) {
            return $candidates->all();
        }

        $minGenByDocument = [];
        foreach ($candidates as $row) {
            $docId = $row->document_id !== null ? (int) $row->document_id : 0;
            $gen = $row->ingest_generation !== null ? (int) $row->ingest_generation : 0;
            if (! isset($minGenByDocument[$docId]) || $gen < $minGenByDocument[$docId]) {
                $minGenByDocument[$docId] = $gen;
            }
        }

        return $candidates
            ->filter(function ($row) use ($minGenByDocument): bool {
                $docId = $row->document_id !== null ? (int) $row->document_id : 0;
                $gen = $row->ingest_generation !== null ? (int) $row->ingest_generation : 0;

                return ($minGenByDocument[$docId] ?? $gen) === $gen;
            })
            ->values()
            ->all();
    }

    /**
     * @param  list<object>  $rows  newest first, already live-at-T filtered
     */
    private function isCommissionedActiveAt(array $rows): bool
    {
        $commissioned = false;
        $shipped = false;

        foreach ($rows as $row) {
            $biz = strtolower((string) ($row->biz_step ?? ''));
            $type = (string) ($row->event_type ?? '');
            $action = strtoupper((string) ($row->action ?? ''));

            if ($type === 'ObjectEvent' && $action === 'ADD' && str_contains($biz, 'commissioning')) {
                $commissioned = true;
            }

            if (str_contains($biz, 'shipping')) {
                $shipped = true;
            }
        }

        return $commissioned && ! $shipped;
    }

    /**
     * @return array{
     *     found: bool,
     *     as_of: string,
     *     status: string,
     *     status_tone: string,
     *     disposition: ?string,
     *     disposition_uri: ?string,
     *     biz_step: ?string,
     *     gln: ?string,
     *     event_id: ?int,
     *     event_time: ?string,
     *     ingest_generation: ?int
     * }
     */
    private function emptyResult(DateTimeInterface $asOfUtc, bool $found): array
    {
        return [
            'found' => $found,
            'as_of' => Carbon::parse($asOfUtc)->utc()->toIso8601String(),
            'status' => $found ? 'Unknown' : 'Not found',
            'status_tone' => 'warn',
            'disposition' => null,
            'disposition_uri' => null,
            'biz_step' => null,
            'gln' => null,
            'event_id' => null,
            'event_time' => null,
            'ingest_generation' => null,
        ];
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }
}
