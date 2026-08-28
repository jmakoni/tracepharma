<?php

namespace App\Support\Auth;

final class SupportEngineerEmail
{
    public const DOMAIN = 'tracepharma.io';

    public static function isAllowed(?string $email): bool
    {
        $email = strtolower(trim((string) $email));
        if ($email === '' || ! str_contains($email, '@')) {
            return false;
        }

        $domain = substr($email, (int) strrpos($email, '@') + 1);

        return $domain === self::DOMAIN;
    }
}
