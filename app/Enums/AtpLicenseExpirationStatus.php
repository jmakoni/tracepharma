<?php

namespace App\Enums;

enum AtpLicenseExpirationStatus: string
{
    case Active = 'active';
    case Expiring = 'expiring';
    case Expired = 'expired';
    case UnknownExpiry = 'unknown_expiry';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Expiring => 'Expiring',
            self::Expired => 'Expired',
            self::UnknownExpiry => 'Unknown expiry',
        };
    }

    public function badgeColor(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Expiring => 'warning',
            self::Expired => 'danger',
            self::UnknownExpiry => 'warning',
        };
    }

    /**
     * A license with no expiration date cannot be shown to be in force, so it
     * never counts as valid authorization.
     */
    public function isValid(): bool
    {
        return $this === self::Active || $this === self::Expiring;
    }
}
