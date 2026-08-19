<?php

namespace App\Support\Epcis\Validation;

/**
 * @phpstan-type FindingArray array{
 *     exception_type: string,
 *     severity: string,
 *     description: string,
 *     event_id: int|null,
 *     epc_id: int|null
 * }
 */
final class EpcisValidationFinding
{
    public function __construct(
        public readonly string $exceptionType,
        public readonly string $severity,
        public readonly string $description,
        public readonly ?int $eventId = null,
        public readonly ?int $epcId = null,
    ) {}

    /**
     * @return FindingArray
     */
    public function toArray(): array
    {
        return [
            'exception_type' => $this->exceptionType,
            'severity' => $this->severity,
            'description' => $this->description,
            'event_id' => $this->eventId,
            'epc_id' => $this->epcId,
        ];
    }

    public function isBlocking(): bool
    {
        return in_array($this->severity, ['error', 'critical'], true);
    }
}
