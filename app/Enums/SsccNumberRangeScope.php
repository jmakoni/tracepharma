<?php

namespace App\Enums;

enum SsccNumberRangeScope: string
{
    case Tenant = 'tenant';
    case Site = 'site';
    case Partner = 'partner';

    public function label(): string
    {
        return match ($this) {
            self::Tenant => 'Tenant',
            self::Site => 'Site',
            self::Partner => 'Partner',
        };
    }
}
