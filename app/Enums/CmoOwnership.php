<?php

declare(strict_types=1);

namespace App\Enums;

enum CmoOwnership: string
{
    case OwnProduct = 'own_product';
    case CmoSells = 'cmo_sells';

    public function label(): string
    {
        return match ($this) {
            self::OwnProduct => 'We already own this product (contract packager)',
            self::CmoSells => 'CMO sells to us',
        };
    }
}
