<?php

declare(strict_types=1);

namespace App\Enums;

enum OutboundConnectionKind: string
{
    case ProviderHub = 'provider_hub';
    case DirectPartner = 'direct_partner';
    case LocalDelivery = 'local_delivery';

    public function label(): string
    {
        return match ($this) {
            self::ProviderHub => 'Through a network',
            self::DirectPartner => 'Direct to one customer',
            self::LocalDelivery => 'Our portal or email',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::ProviderHub => 'Network',
            self::DirectPartner => 'Direct partner',
            self::LocalDelivery => 'Portal / email',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $kind): array => [$kind->value => $kind->label()])
            ->all();
    }
}
