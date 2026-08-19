<?php

declare(strict_types=1);

namespace App\Enums;

enum OutboundEpcisAggregationMode: string
{
    case InstanceOnly = 'instance_only';
    case ClassOnly = 'class_only';
    case Hybrid = 'hybrid';

    public function label(): string
    {
        return match ($this) {
            self::InstanceOnly => 'Instance EPCs only (childEPCs)',
            self::ClassOnly => 'Class / lot quantity (childQuantityList)',
            self::Hybrid => 'Hybrid (childEPCs + childQuantityList)',
        };
    }

    public function emitsInstanceChildren(): bool
    {
        return match ($this) {
            self::InstanceOnly, self::Hybrid => true,
            self::ClassOnly => false,
        };
    }

    public function emitsClassChildren(): bool
    {
        return match ($this) {
            self::ClassOnly, self::Hybrid => true,
            self::InstanceOnly => false,
        };
    }
}
