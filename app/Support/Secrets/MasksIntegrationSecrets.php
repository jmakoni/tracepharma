<?php

declare(strict_types=1);

namespace App\Support\Secrets;

final class MasksIntegrationSecrets
{
    public static function maskToken(?string $token): ?string
    {
        if ($token === null || $token === '') {
            return null;
        }

        $lastFour = substr($token, -4);

        return '••••••••'.$lastFour;
    }
}
