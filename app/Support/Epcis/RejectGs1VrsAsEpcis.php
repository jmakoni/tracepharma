<?php

declare(strict_types=1);

namespace App\Support\Epcis;

use JsonException;

/**
 * GS1 VRS Lightweight Messaging is not an EPCIS document.
 */
final class RejectGs1VrsAsEpcis
{
    public static function looksLike(string $content): bool
    {
        $trimmed = ltrim($content);
        if ($trimmed === '' || ($trimmed[0] !== '{' && $trimmed[0] !== '[')) {
            return false;
        }

        try {
            $decoded = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return false;
        }

        if (! is_array($decoded)) {
            return false;
        }

        foreach ([
            'verificationRequest',
            'verificationResponse',
            'GS1-Verification-Request',
            'GS1-Verification-Response',
        ] as $key) {
            if (array_key_exists($key, $decoded)) {
                return true;
            }
        }

        return false;
    }

    public static function rejectMessage(): string
    {
        return 'GS1 VRS Lightweight Messaging is not an EPCIS document and cannot be captured.';
    }
}
