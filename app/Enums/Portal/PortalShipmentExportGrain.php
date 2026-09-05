<?php

declare(strict_types=1);

namespace App\Enums\Portal;

enum PortalShipmentExportGrain: string
{
    case Summary = 'summary';
    case Lines = 'lines';

    public static function tryFromRequest(mixed $value): ?self
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return self::tryFrom(strtolower($value));
    }
}
