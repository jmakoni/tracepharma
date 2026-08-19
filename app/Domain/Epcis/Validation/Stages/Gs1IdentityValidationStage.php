<?php

declare(strict_types=1);

namespace App\Domain\Epcis\Validation\Stages;

use App\Domain\Epcis\Validation\Contracts\ValidationStage;
use App\Domain\Epcis\Validation\ValidationContext;
use App\Domain\Epcis\Validation\ValidationFailure;
use App\Domain\Gs1\EpcClassUri;
use App\Domain\Gs1\SgtinUri;
use App\Domain\Gs1\SsccUri;
use InvalidArgumentException;

/**
 * Stage 2 — GS1 Pure Identity / check-digit / EPC class schema gate.
 */
final class Gs1IdentityValidationStage implements ValidationStage
{
    public function name(): string
    {
        return 'gs1_schema';
    }

    public function validate(ValidationContext $context): ?ValidationFailure
    {
        foreach ($context->events as $index => $event) {
            $uris = [];

            foreach ($event['epc_list'] ?? [] as $uri) {
                $uris[] = (string) $uri;
            }

            if (isset($event['parent_id']) && is_string($event['parent_id']) && $event['parent_id'] !== '') {
                $uris[] = $event['parent_id'];
            }

            foreach ($event['child_epcs'] ?? [] as $uri) {
                $uris[] = (string) $uri;
            }

            foreach ($uris as $uri) {
                $failure = $this->assertInstanceUri($uri, $index);
                if ($failure !== null) {
                    return $failure;
                }
            }

            $quantityLists = array_merge(
                is_array($event['child_quantity_list'] ?? null) ? $event['child_quantity_list'] : [],
                is_array($event['quantity_list'] ?? null) ? $event['quantity_list'] : [],
                is_array($event['quantity_children'] ?? null) ? $event['quantity_children'] : [],
            );

            foreach ($quantityLists as $qty) {
                if (! is_array($qty)) {
                    return new ValidationFailure(
                        stage: $this->name(),
                        code: 'INVALID_EPC_URI',
                        message: "Event {$index} quantity entry must be an object with epc_class.",
                        context: ['event_index' => $index],
                    );
                }

                $epcClass = trim((string) ($qty['epc_class'] ?? $qty['epcClass'] ?? ''));
                if ($epcClass === '') {
                    return new ValidationFailure(
                        stage: $this->name(),
                        code: 'INVALID_EPC_URI',
                        message: "Event {$index} quantity entry is missing epc_class.",
                        context: ['event_index' => $index],
                    );
                }

                try {
                    EpcClassUri::fromString($epcClass);
                } catch (InvalidArgumentException $e) {
                    return new ValidationFailure(
                        stage: $this->name(),
                        code: 'INVALID_EPC_URI',
                        message: $e->getMessage(),
                        context: ['event_index' => $index, 'epc_class' => $epcClass],
                    );
                }
            }
        }

        return null;
    }

    private function assertInstanceUri(string $uri, int $eventIndex): ?ValidationFailure
    {
        $uri = trim($uri);

        try {
            if (preg_match('/^urn:epc:id:sgtin:/i', $uri) === 1) {
                SgtinUri::fromUrn($uri);

                return null;
            }

            if (preg_match('/^urn:epc:id:sscc:/i', $uri) === 1) {
                SsccUri::fromUrn($uri);

                return null;
            }
        } catch (InvalidArgumentException $e) {
            return new ValidationFailure(
                stage: $this->name(),
                code: 'INVALID_EPC_URI',
                message: $e->getMessage(),
                context: ['event_index' => $eventIndex, 'epc_uri' => $uri],
            );
        }

        return new ValidationFailure(
            stage: $this->name(),
            code: 'INVALID_EPC_URI',
            message: 'EPC URI must be an SGTIN or SSCC Pure Identity URN.',
            context: ['event_index' => $eventIndex, 'epc_uri' => $uri],
        );
    }
}
