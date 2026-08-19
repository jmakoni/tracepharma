<?php

namespace App\Support\Marketing;

use Illuminate\Support\Str;

final class LeadSubmissionMeta
{
    public const USER_AGENT_MAX_LENGTH = 512;

    public static function truncateUserAgent(?string $userAgent): ?string
    {
        if ($userAgent === null || $userAgent === '') {
            return null;
        }

        return Str::limit($userAgent, self::USER_AGENT_MAX_LENGTH, '');
    }
}
