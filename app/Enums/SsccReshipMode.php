<?php

namespace App\Enums;

enum SsccReshipMode: string
{
    case PerChild = 'per_child';
    case Combined = 'combined';

    public function label(): string
    {
        return match ($this) {
            self::PerChild => 'One new SSCC per selected child',
            self::Combined => 'One new SSCC for all selected children',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::PerChild => 'Generate a separate outbound pallet label for each case or unit.',
            self::Combined => 'Generate one outbound pallet label aggregating all selected children.',
        };
    }
}
