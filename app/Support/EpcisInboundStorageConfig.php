<?php

declare(strict_types=1);

namespace App\Support;

final class EpcisInboundStorageConfig
{
    public static function bucket(): ?string
    {
        $bucket = env('EPCIS_INBOUND_BUCKET');

        if (filled($bucket)) {
            return (string) $bucket;
        }

        $fallback = env('AWS_BUCKET');

        return filled($fallback) ? (string) $fallback : null;
    }

    public static function url(): ?string
    {
        $explicit = env('EPCIS_INBOUND_URL') ?: env('AWS_URL');

        if (filled($explicit)) {
            return (string) $explicit;
        }

        $bucket = self::bucket();

        if (blank($bucket)) {
            return null;
        }

        $region = (string) env('AWS_DEFAULT_REGION', 'us-east-1');

        return "https://{$bucket}.s3.{$region}.amazonaws.com";
    }
}
