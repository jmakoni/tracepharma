<?php

declare(strict_types=1);

namespace App\Support;

final class EpcisInboundStorageConfig
{
    public static function bucket(): ?string
    {
        $bucket = config('tracepharma.epcis.inbound_bucket');

        // filesystems.php may resolve this while configs are still loading.
        if (blank($bucket)) {
            $bucket = env('EPCIS_INBOUND_BUCKET') ?: env('AWS_BUCKET');
        }

        return filled($bucket) ? (string) $bucket : null;
    }

    public static function url(): ?string
    {
        $explicit = config('tracepharma.epcis.inbound_url');

        if (blank($explicit)) {
            $explicit = env('EPCIS_INBOUND_URL') ?: env('AWS_URL');
        }

        if (filled($explicit)) {
            return (string) $explicit;
        }

        $bucket = self::bucket();

        if (blank($bucket)) {
            return null;
        }

        $region = (string) (config('filesystems.disks.s3.region')
            ?: env('AWS_DEFAULT_REGION', 'us-east-1'));

        return "https://{$bucket}.s3.{$region}.amazonaws.com";
    }
}
