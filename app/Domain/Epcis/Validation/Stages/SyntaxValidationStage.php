<?php

declare(strict_types=1);

namespace App\Domain\Epcis\Validation\Stages;

use App\Domain\Epcis\Validation\Contracts\ValidationStage;
use App\Domain\Epcis\Validation\ValidationContext;
use App\Domain\Epcis\Validation\ValidationFailure;

/**
 * Stage 1 — structural / syntax gate before GS1 identity work.
 */
final class SyntaxValidationStage implements ValidationStage
{
    public function name(): string
    {
        return 'syntax';
    }

    public function validate(ValidationContext $context): ?ValidationFailure
    {
        if ($context->events === []) {
            return new ValidationFailure(
                stage: $this->name(),
                code: 'EMPTY_EVENT_LIST',
                message: 'EPCIS document candidate has no events.',
            );
        }

        foreach ($context->events as $index => $event) {
            if (($context->attributes['require_xml_well_formed'] ?? false) === true
                && array_key_exists('xml_well_formed', $event)
                && $event['xml_well_formed'] === false) {
                return new ValidationFailure(
                    stage: $this->name(),
                    code: 'MALFORMED_XML',
                    message: "Event {$index} XML is not well-formed.",
                    context: ['event_index' => $index],
                );
            }

            $type = (string) ($event['event_type'] ?? '');
            if ($type === '') {
                return new ValidationFailure(
                    stage: $this->name(),
                    code: 'MISSING_EVENT_TYPE',
                    message: "Event {$index} is missing event_type.",
                    context: ['event_index' => $index],
                );
            }

            $action = (string) ($event['action'] ?? '');
            if ($action === '' || ! in_array(strtoupper($action), ['ADD', 'OBSERVE', 'DELETE'], true)) {
                return new ValidationFailure(
                    stage: $this->name(),
                    code: 'INVALID_ACTION',
                    message: "Event {$index} has an invalid action.",
                    context: ['event_index' => $index, 'action' => $action],
                );
            }

            if (! isset($event['event_time']) || trim((string) $event['event_time']) === '') {
                return new ValidationFailure(
                    stage: $this->name(),
                    code: 'MISSING_EVENT_TIME',
                    message: "Event {$index} is missing event_time.",
                    context: ['event_index' => $index],
                );
            }
        }

        return null;
    }
}
