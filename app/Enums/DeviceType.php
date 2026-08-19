<?php

namespace App\Enums;

enum DeviceType: string
{
    case Scanner = 'scanner';
    case Printer = 'printer';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Scanner => 'Scanner',
            self::Printer => 'Printer',
            self::Other => 'Other',
        };
    }
}
