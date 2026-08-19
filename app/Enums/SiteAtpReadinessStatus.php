<?php

namespace App\Enums;

enum SiteAtpReadinessStatus: string
{
    case Ready = 'ready';
    case Expiring = 'expiring';
    case Expired = 'expired';
    case UnknownExpiry = 'unknown_expiry';
    case NoLicenses = 'no_licenses';
    case NeedsReceivingState = 'needs_receiving_state';
    case FdaRegistered = 'fda_registered';

    public function label(): string
    {
        return match ($this) {
            self::Ready => 'Ready',
            self::Expiring => 'Expiring',
            self::Expired => 'Expired',
            self::UnknownExpiry => 'Unknown expiry',
            self::NoLicenses => 'No license for state',
            self::NeedsReceivingState => 'Set receiving state',
            self::FdaRegistered => 'FDA registered (all states)',
        };
    }

    public function badgeColor(): string
    {
        return match ($this) {
            self::Ready => 'success',
            self::Expiring => 'warning',
            self::Expired => 'danger',
            self::UnknownExpiry => 'warning',
            self::NoLicenses => 'gray',
            self::NeedsReceivingState => 'gray',
            self::FdaRegistered => 'success',
        };
    }
}
