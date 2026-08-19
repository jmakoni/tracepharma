<?php

namespace App\Enums;

enum AdminRole: string
{
    case PlatformAdmin = 'platform_admin';
    case Support = 'support';

    public function label(): string
    {
        return match ($this) {
            self::PlatformAdmin => 'Platform Admin',
            self::Support => 'Support',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $role): array => [$role->value => $role->label()])
            ->all();
    }
}
