<?php

namespace App\DTOs\Labeling;

use App\Enums\SsccAllocationMode;

class SsccAllocationRequest
{
    public function __construct(
        public readonly SsccAllocationMode $mode,
        public readonly int $labelCount,
        public readonly string $companyPrefix,
        public readonly int $extensionDigit = 0,
        public readonly bool $enforceForwardOnly = true,
        public readonly ?int $rangeStart = null,
        public readonly ?int $rangeEnd = null,
        public readonly ?string $fixedPrefix = null,
        public readonly ?int $randomFloor = null,
        public readonly ?int $randomCeiling = null,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public static function fromInput(array $input, string $companyPrefix, int $extensionDigit): self
    {
        $mode = SsccAllocationMode::tryFrom((string) ($input['allocation_mode'] ?? SsccAllocationMode::Sequential->value))
            ?? SsccAllocationMode::Sequential;

        return new self(
            mode: $mode,
            labelCount: max(1, (int) ($input['label_count'] ?? 1)),
            companyPrefix: $companyPrefix,
            extensionDigit: $extensionDigit,
            enforceForwardOnly: (bool) ($input['enforce_forward_only'] ?? true),
            rangeStart: isset($input['range_start']) && $input['range_start'] !== '' ? (int) $input['range_start'] : null,
            rangeEnd: isset($input['range_end']) && $input['range_end'] !== '' ? (int) $input['range_end'] : null,
            fixedPrefix: isset($input['fixed_prefix']) && $input['fixed_prefix'] !== '' ? (string) $input['fixed_prefix'] : null,
            randomFloor: isset($input['random_floor']) && $input['random_floor'] !== '' ? (int) $input['random_floor'] : null,
            randomCeiling: isset($input['random_ceiling']) && $input['random_ceiling'] !== '' ? (int) $input['random_ceiling'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toConfigArray(): array
    {
        return [
            'enforce_forward_only' => $this->enforceForwardOnly,
            'range_start' => $this->rangeStart,
            'range_end' => $this->rangeEnd,
            'fixed_prefix' => $this->fixedPrefix,
            'random_floor' => $this->randomFloor,
            'random_ceiling' => $this->randomCeiling,
        ];
    }
}
