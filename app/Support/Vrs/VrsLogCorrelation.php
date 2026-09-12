<?php

namespace App\Support\Vrs;

final class VrsLogCorrelation
{
    /** Truncated sha256 hex of gtin14|serial for app logs (no raw serial). */
    public static function hash(string $gtin14, string $serial): string
    {
        return substr(hash('sha256', $gtin14.'|'.$serial), 0, 16);
    }

    /** Hash of a raw scan payload for invalid-scan job logs. */
    public static function scanHash(string $scan): string
    {
        return substr(hash('sha256', $scan), 0, 16);
    }
}
