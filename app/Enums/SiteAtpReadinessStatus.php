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
    case NotMonitored = 'not_monitored';

    public function label(): string
    {
        return match ($this) {
            self::Ready => 'Ready',
            self::Expiring => 'Expiring',
            self::Expired => 'Expired',
            self::UnknownExpiry => 'Unknown expiry',
            self::NoLicenses => 'No license for jurisdictions',
            self::NeedsReceivingState => 'No org site jurisdictions',
            self::FdaRegistered => 'FDA registered (all states)',
            self::NotMonitored => 'Manufacturer HQ · not monitored',
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
            self::NotMonitored => 'gray',
        };
    }
}
