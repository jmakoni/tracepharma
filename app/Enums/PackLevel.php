<?php

namespace App\Enums;

enum PackLevel: string
{
    case Unit = 'unit';
    case Inner = 'inner';
    case Case = 'case';
    case Pallet = 'pallet';

    public function label(): string
    {
        return match ($this) {
            self::Unit => 'Unit',
            self::Inner => 'Inner',
            self::Case => 'Case',
            self::Pallet => 'Pallet',
        };
    }
}
