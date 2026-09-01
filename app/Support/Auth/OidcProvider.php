<?php

declare(strict_types=1);

namespace App\Support\Auth;

enum OidcProvider: string
{
    case Entra = 'entra';
    case Okta = 'okta';
    case Oidc = 'oidc';

    public function label(): string
    {
        return match ($this) {
            self::Entra => 'Microsoft Entra ID',
            self::Okta => 'Okta',
            self::Oidc => 'OpenID Connect',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])
            ->all();
    }
}
