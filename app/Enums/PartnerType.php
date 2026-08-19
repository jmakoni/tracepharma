<?php

namespace App\Enums;

enum PartnerType: string
{
    case Manufacturer = 'manufacturer';
    case Wholesaler = 'wholesaler';
    case Pharmacy = 'pharmacy';
    case Logistics3pl = '3pl';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Manufacturer => 'Manufacturer',
            self::Wholesaler => 'Wholesaler',
            self::Pharmacy => 'Pharmacy',
            self::Logistics3pl => '3PL',
            self::Other => 'Other',
        };
    }
}
