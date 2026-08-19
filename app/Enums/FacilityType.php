<?php

namespace App\Enums;

enum FacilityType: string
{
    case Wdd = 'wdd';
    case ThreePl = '3pl';

    public function label(): string
    {
        return match ($this) {
            self::Wdd => 'WDD',
            self::ThreePl => '3PL',
        };
    }
}
