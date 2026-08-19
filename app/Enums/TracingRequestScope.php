<?php

namespace App\Enums;

enum TracingRequestScope: string
{
    case SingleProduct = 'single_product';
    case Lot = 'lot';

    public function label(): string
    {
        return match ($this) {
            self::SingleProduct => 'Single Product',
            self::Lot => 'Lot',
        };
    }
}
