<?php

declare(strict_types=1);

namespace App\Actions\Epcis;

use App\Domain\Epcis\Validation\ValidationContext;
use App\Domain\Epcis\Validation\ValidationPipeline;
use App\Domain\Epcis\Validation\ValidationResult;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EventQuantity;
use Illuminate\Support\Facades\DB;

/**
 * Thin adapter: load persisted event/EPC graph into Domain ValidationPipeline.
 * Does not persist — callers commit or dead-letter via RecordEpcisValidationFailure.
 */
final class RunDomainEpcisHardGate
{
    private const EVENT_CHUNK = 500;

    public function __construct(
        private readonly ValidationPipeline $pipeline,
    ) {}

    public static function withDefaultPipeline(): self
    {
        return new self(ValidationPipeline::default());
    }

    public function handle(EpcisDocument $document): ValidationResult
    {
        $events = $document->activeEvents()
            ->orderBy('id')
            ->get([
                'id',
                'event_type',
                'action',
                'event_time',
                'biz_step',
                'disposition',
            ]);

        $eventIds = $events->modelKeys();

        if ($eventIds === []) {
            return $this->pipeline->validate(new ValidationContext([]));
        }

        /** @var array<int, list<array{role: string, epc_uri: string}>> $epcRowsByEvent */
        $epcRowsByEvent = [];
        /** @var array<int, list<EventQuantity>> $quantitiesByEvent */
        $quantitiesByEvent = [];

        foreach (array_chunk($eventIds, self::EVENT_CHUNK) as $chunkIds) {
            $epcRows = DB::table('event_epcs')
                ->join('epcs', 'epcs.id', '=', 'event_epcs.epc_id')
                ->whereIn('event_epcs.event_id', $chunkIds)
                ->select([
                    'event_epcs.event_id',
                    'event_epcs.role',
                    'epcs.epc_uri',
                ])
                ->get();

            foreach ($epcRows as $row) {
                $eventId = (int) $row->event_id;
                $epcRowsByEvent[$eventId][] = [
                    'role' => (string) ($row->role ?? 'epclist'),
                    'epc_uri' => (string) ($row->epc_uri ?? ''),
                ];
            }

            $quantities = EventQuantity::query()
                ->whereIn('event_id', $chunkIds)
                ->get(['event_id', 'role', 'epc_class', 'quantity', 'uom']);

            foreach ($quantities as $qty) {
                $quantitiesByEvent[(int) $qty->event_id][] = $qty;
            }
        }

        $shapes = [];

        foreach ($events as $event) {
            $eventKey = (int) $event->getKey();
            $epcList = [];
            $childEpcs = [];
            $parentId = null;

            foreach ($epcRowsByEvent[$eventKey] ?? [] as $row) {
                $uri = $row['epc_uri'];
                if ($uri === '') {
                    continue;
                }

                $role = strtolower($row['role']);

                if (in_array($role, ['parentid', 'parent_id', 'parent'], true)) {
                    $parentId = $uri;
                } elseif (in_array($role, ['childepc', 'child_epc', 'child'], true)) {
                    $childEpcs[] = $uri;
                } else {
                    $epcList[] = $uri;
                }
            }

            $quantityList = [];
            $childQuantityList = [];

            /** @var list<EventQuantity> $qtyRows */
            $qtyRows = $quantitiesByEvent[$eventKey] ?? [];

            foreach ($qtyRows as $qty) {
                $role = strtolower((string) ($qty->role ?? ''));
                $epcClass = (string) ($qty->epc_class ?? '');
                if ($epcClass === '') {
                    continue;
                }

                $entry = [
                    'epc_class' => $epcClass,
                    'quantity' => $qty->quantity,
                    'uom' => $qty->uom,
                ];

                if (in_array($role, ['childquantitylist', 'child_quantity_list'], true)
                    || (strcasecmp((string) $event->event_type, 'AggregationEvent') === 0
                        && ! in_array($role, ['quantitylist', 'quantity_list'], true))) {
                    $childQuantityList[] = $entry;
                } elseif (in_array($role, ['quantitylist', 'quantity_list'], true)
                    || strcasecmp((string) $event->event_type, 'ObjectEvent') === 0) {
                    $quantityList[] = $entry;
                } else {
                    $childQuantityList[] = $entry;
                }
            }

            $shapes[] = [
                'event_type' => (string) $event->event_type,
                'action' => (string) $event->action,
                'event_time' => (string) ($event->event_time?->toIso8601String() ?? $event->event_time ?? ''),
                'epc_list' => $epcList,
                'parent_id' => $parentId,
                'child_epcs' => $childEpcs,
                'quantity_list' => $quantityList,
                'child_quantity_list' => $childQuantityList,
                'biz_step' => (string) ($event->biz_step ?? ''),
                'disposition' => (string) ($event->disposition ?? ''),
            ];
        }

        return $this->pipeline->validate(new ValidationContext($shapes));
    }

    /**
     * Validate an in-memory candidate (authoring / pre-persist hard gate).
     *
     * @param  list<array<string, mixed>>  $events
     * @param  array<string, mixed>  $attributes
     */
    public function validateCandidate(array $events, array $attributes = []): ValidationResult
    {
        return $this->pipeline->validate(new ValidationContext($events, $attributes));
    }
}
