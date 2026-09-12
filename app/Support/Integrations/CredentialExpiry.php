<?php

declare(strict_types=1);

namespace App\Support\Integrations;

use Carbon\CarbonInterface;

/**
 * Shared credential-expiry presentation for connections and platform edges:
 * unknown (no expiry tracked) → ok → expiring (within the warning window) → expired.
 */
class CredentialExpiry
{
    public const STATE_UNKNOWN = 'unknown';

    public const STATE_OK = 'ok';

    public const STATE_EXPIRING = 'expiring';

    public const STATE_EXPIRED = 'expired';

    public static function state(?CarbonInterface $expiresAt, int $warningDays = 30): string
    {
        if ($expiresAt === null) {
            return self::STATE_UNKNOWN;
        }

        if ($expiresAt->isPast()) {
            return self::STATE_EXPIRED;
        }

        if ($expiresAt->lte(now()->addDays(max(1, $warningDays)))) {
            return self::STATE_EXPIRING;
        }

        return self::STATE_OK;
    }

    public static function badgeColor(?CarbonInterface $expiresAt, int $warningDays = 30): string
    {
        return match (self::state($expiresAt, $warningDays)) {
            self::STATE_EXPIRED => 'danger',
            self::STATE_EXPIRING => 'warning',
            self::STATE_OK => 'success',
            default => 'gray',
        };
    }

    public static function label(?CarbonInterface $expiresAt, int $warningDays = 30): string
    {
        if ($expiresAt === null) {
            return 'Not tracked';
        }

        $state = self::state($expiresAt, $warningDays);
        $date = $expiresAt->toDateString();

        return match ($state) {
            self::STATE_EXPIRED => "Expired {$date}",
            self::STATE_EXPIRING => "Expires {$date}",
            default => "Valid until {$date}",
        };
    }
}
