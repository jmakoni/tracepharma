<?php

namespace App\Enums;

enum SsccAllocationMode: string
{
    case Sequential = 'sequential';
    case Range = 'range';
    case PartialRandom = 'partial_random';
    case FullyRandom = 'fully_random';

    public function label(): string
    {
        return match ($this) {
            self::Sequential => 'Sequential',
            self::Range => 'Range',
            self::PartialRandom => 'Partial random',
            self::FullyRandom => 'Fully random',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Sequential => 'Assign serials in order starting after the last generated value.',
            self::Range => 'Assign a contiguous block from a start serial through a count or end serial.',
            self::PartialRandom => 'Keep a fixed digit prefix and randomize the remaining suffix digits.',
            self::FullyRandom => 'Pick unpredictable unused serials across the allowed range.',
        };
    }
}
