<?php

declare(strict_types=1);

namespace App\Actions\Epcis;

use App\Domain\Epcis\Validation\ValidationContext;
use App\Domain\Epcis\Validation\ValidationPipeline;
use App\Domain\Epcis\Validation\ValidationResult;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EventEpc;
use App\Models\Epcis\EventQuantity;

/**
 * Thin adapter: load persisted event/EPC graph into Domain ValidationPipeline.
 * Does not persist — callers commit or dead-letter via RecordEpcisValidationFailure.
 */
final class RunDomainEpcisHardGate
{
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
            ->get();

        $eventIds = $events->modelKeys();

        if ($eventIds === []) {
            return $this->pipeline->validate(new ValidationContext([]));
        }

        $epcRowsByEvent = EventEpc::query()
            ->whereIn('event_id', $eventIds)
            ->with('epc')
            ->get()
            ->groupBy('event_id');

        $quantitiesByEvent = EventQuantity::query()
            ->whereIn('event_id', $eventIds)
            ->get()
            ->groupBy('event_id');

        $shapes = [];

        foreach ($events as $event) {
            $epcList = [];
            $childEpcs = [];
            $parentId = null;

            foreach ($epcRowsByEvent->get($event->getKey(), collect()) as $row) {
                $uri = (string) ($row->epc?->epc_uri ?? '');
                if ($uri === '') {
                    continue;
                }

                $role = strtolower((string) ($row->role ?? 'epclist'));

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

            foreach ($quantitiesByEvent->get($event->getKey(), collect()) as $qty) {
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
