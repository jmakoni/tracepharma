<?php

namespace App\Support\MasterData;

use App\Enums\AuthorizationStatus;
use App\Enums\PartnerType;
use App\Models\Product;

final class ProductComplianceStatus
{
    public const Verified = 'Verified';

    public const PendingManufacturer = 'Pending manufacturer';

    public const Incomplete = 'Incomplete';

    public static function label(Product $record): string
    {
        $partners = $record->relationLoaded('tradingPartners')
            ? $record->tradingPartners
            : $record->tradingPartners()->get();

        if ($partners->isEmpty()) {
            return self::Incomplete;
        }

        if ($partners->contains(
            fn ($partner): bool => $partner->pivot->authorization_status === AuthorizationStatus::PendingManufacturer->value,
        )) {
            return self::PendingManufacturer;
        }

        $allAuthorized = $partners->every(
            fn ($partner): bool => $partner->pivot->authorization_status === AuthorizationStatus::Authorized->value,
        );

        if (! $allAuthorized) {
            return self::Incomplete;
        }

        if (filled($record->trading_partner_id)) {
            return self::Verified;
        }

        if (
            $partners->count() === 1
            && $partners->first()->partner_type === PartnerType::Manufacturer
        ) {
            return self::Verified;
        }

        return self::Incomplete;
    }

    public static function color(string $label): string
    {
        return match ($label) {
            self::Verified => 'success',
            self::PendingManufacturer => 'warning',
            default => 'gray',
        };
    }
}
