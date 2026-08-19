<?php

declare(strict_types=1);

namespace App\Actions\Outbound;

use App\Actions\Epcis\RunDomainEpcisHardGate;
use App\Domain\Epcis\Data\AggregationEventData;
use App\Domain\Epcis\EpcisEventFactory;
use App\Domain\Epcis\Enums\EpcisAction;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Pre-persist hard gate for authored AggregationEvent XML (pack / unpack / break pallet).
 */
final class AssertAuthoredAggregationCandidate
{
    public function __construct(
        private readonly EpcisEventFactory $factory,
        private readonly RunDomainEpcisHardGate $hardGate,
    ) {}

    /**
     * @param  list<string>  $childEpcs
     * @param  list<array{epcClass?: string, epc_class?: string, quantity?: float|int, uom?: ?string}>  $quantityChildren
     */
    public function handle(
        string $parentUri,
        array $childEpcs,
        EpcisAction $action,
        string $bizStep,
        string $disposition,
        ?DateTimeImmutable $eventTimeUtc = null,
        array $quantityChildren = [],
    ): AggregationEventData {
        $eventTimeUtc ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $normalizedChildren = [];
        foreach ($childEpcs as $child) {
            $child = trim((string) $child);
            if ($child !== '') {
                $normalizedChildren[] = $child;
            }
        }

        $quantityList = [];
        foreach ($quantityChildren as $row) {
            if (! is_array($row)) {
                continue;
            }
            $epcClass = trim((string) ($row['epc_class'] ?? $row['epcClass'] ?? ''));
            if ($epcClass === '') {
                continue;
            }
            $quantityList[] = [
                'epc_class' => $epcClass,
                'quantity' => $row['quantity'] ?? null,
                'uom' => $row['uom'] ?? null,
            ];
        }

        $data = $this->factory->aggregationEvent(
            parentUri: $parentUri,
            childUris: $normalizedChildren,
            action: $action,
            bizStep: $bizStep,
            disposition: $disposition,
            eventTimeUtc: $eventTimeUtc,
            childQuantityList: $quantityList,
        );

        $result = $this->hardGate->validateCandidate([
            [
                'event_type' => 'AggregationEvent',
                'action' => $action->value,
                'event_time' => $eventTimeUtc->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
                'parent_id' => $data->parentId,
                'child_epcs' => $data->childEpcs,
                'child_quantity_list' => $data->childQuantityList,
                'biz_step' => $data->bizStep,
                'disposition' => $data->disposition,
            ],
        ]);

        if ($result->isFailed()) {
            $failure = $result->failure;
            throw new InvalidArgumentException(
                $failure !== null
                    ? "[{$failure->stage}] {$failure->code}: {$failure->message}"
                    : 'Authored AggregationEvent failed Domain hard-gate validation.',
            );
        }

        return $data;
    }
}
