<?php

namespace App\Support\Fda;

final class DeaRegistration
{
    public static function normalize(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $compact = strtoupper(preg_replace('/[\s\-]+/', '', trim($raw)) ?? '');

        return $compact === '' ? null : $compact;
    }

    public static function parseFromLocationToken(string $token): ?string
    {
        $trimmed = trim($token);
        if (preg_match('/^(?:urn:epc:id:dea:|dea[:\/])(.+)$/i', $trimmed, $m) === 1) {
            return self::normalize($m[1]);
        }

        $normalized = self::normalize($trimmed);
        if ($normalized === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $normalized) ?? '';
        if (strlen($digits) === 13) {
            return null; // GLN-shaped; caller uses GLN ladder first
        }

        return $normalized;
    }
}
