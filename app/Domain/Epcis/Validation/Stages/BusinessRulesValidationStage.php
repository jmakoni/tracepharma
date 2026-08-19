<?php

declare(strict_types=1);

namespace App\Domain\Epcis\Validation\Stages;

use App\Domain\Epcis\Validation\Contracts\ValidationStage;
use App\Domain\Epcis\Validation\ValidationContext;
use App\Domain\Epcis\Validation\ValidationFailure;

/**
 * Stage 3 — lightweight Domain business rules (full DSCSA catalog stays in Actions).
 */
final class BusinessRulesValidationStage implements ValidationStage
{
    public function name(): string
    {
        return 'business_rules';
    }

    public function validate(ValidationContext $context): ?ValidationFailure
    {
        foreach ($context->events as $index => $event) {
            $type = (string) ($event['event_type'] ?? '');
            $action = strtoupper((string) ($event['action'] ?? ''));

            if (strcasecmp($type, 'AggregationEvent') === 0) {
                $parent = trim((string) ($event['parent_id'] ?? ''));
                $children = $event['child_epcs'] ?? [];
                $hasQuantityChildren = $this->hasUsableQuantityEntries(
                    $event['child_quantity_list'] ?? null,
                    $event['quantity_list'] ?? null,
                    $event['quantity_children'] ?? null,
                );

                if ($parent === '') {
                    return new ValidationFailure(
                        stage: $this->name(),
                        code: 'AGGREGATION_MISSING_PARENT',
                        message: "AggregationEvent {$index} requires parent_id.",
                        context: ['event_index' => $index],
                    );
                }

                // DELETE with empty childEPCs = disaggregate-all (GS1 / ProcessEpcisDocument).
                $allowsEmptyChildren = $action === 'DELETE' || $hasQuantityChildren;

                if ($children === [] && ! $allowsEmptyChildren) {
                    return new ValidationFailure(
                        stage: $this->name(),
                        code: 'AGGREGATION_MISSING_CHILDREN',
                        message: "AggregationEvent {$index} requires child EPCs or child quantity list.",
                        context: ['event_index' => $index],
                    );
                }

                if ($children !== [] && in_array($parent, $children, true)) {
                    return new ValidationFailure(
                        stage: $this->name(),
                        code: 'AGGREGATION_PARENT_IN_CHILDREN',
                        message: "AggregationEvent {$index} parent cannot also be a child.",
                        context: ['event_index' => $index],
                    );
                }
            }

            if (strcasecmp($type, 'ObjectEvent') === 0) {
                $epcList = $event['epc_list'] ?? [];
                $hasQuantityList = $this->hasUsableQuantityEntries(
                    $event['quantity_list'] ?? null,
                    $event['child_quantity_list'] ?? null,
                );

                if ($epcList === [] && ! $hasQuantityList) {
                    return new ValidationFailure(
                        stage: $this->name(),
                        code: 'OBJECT_EVENT_EMPTY_EPC_LIST',
                        message: "ObjectEvent {$index} requires epc_list or quantity_list.",
                        context: ['event_index' => $index],
                    );
                }
            }
        }

        return null;
    }

    private function hasUsableQuantityEntries(mixed ...$lists): bool
    {
        foreach ($lists as $list) {
            if (! is_array($list)) {
                continue;
            }

            foreach ($list as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $epcClass = trim((string) ($entry['epc_class'] ?? $entry['epcClass'] ?? ''));
                if ($epcClass !== '') {
                    return true;
                }
            }
        }

        return false;
    }
}
