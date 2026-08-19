<?php

namespace App\Enums;

enum AuthorizationStatus: string
{
    case Authorized = 'authorized';
    case PendingManufacturer = 'pending_manufacturer';
    case PendingPackaging = 'pending_packaging';

    public function label(): string
    {
        return match ($this) {
            self::Authorized => 'Authorized',
            self::PendingManufacturer => 'Pending manufacturer',
            self::PendingPackaging => 'Pending packaging',
        };
    }

    public function operatorLabel(): string
    {
        return match ($this) {
            self::PendingPackaging => 'Incomplete',
            default => $this->label(),
        };
    }

    public function badgeColor(): string
    {
        return match ($this) {
            self::Authorized => 'success',
            self::PendingManufacturer => 'warning',
            default => 'gray',
        };
    }
}
