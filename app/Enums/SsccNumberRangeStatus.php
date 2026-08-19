<?php

namespace App\Enums;

enum SsccNumberRangeStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Depleted = 'depleted';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Inactive => 'Inactive',
            self::Depleted => 'Depleted',
        };
    }

    public function badgeColor(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Inactive => 'gray',
            self::Depleted => 'danger',
        };
    }
}
