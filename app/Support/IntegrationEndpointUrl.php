<?php

namespace App\Support;

use App\Support\EpcisHub\EpcisHubPlatformConfig;

class IntegrationEndpointUrl
{
    public static function inboundWebhook(string $tenantId, int $connectionId): string
    {
        return self::build('/api/webhooks/epcis/'.$tenantId.'/'.$connectionId);
    }

    public static function inboundAs2(string $tenantId, int $connectionId): string
    {
        return self::build('/api/webhooks/as2/'.$tenantId.'/'.$connectionId);
    }

    public static function vrsResponder(string $tenantId): string
    {
        return self::build('/api/webhooks/vrs/'.$tenantId);
    }

    public static function inboundHub(string $provider, ?string $environment = null): string
    {
        $environment ??= tenant()?->inbound_environment;

        if (is_string($environment) && $environment !== '') {
            return app(EpcisHubPlatformConfig::class)->hubUrl($environment, $provider);
        }

        return self::build('/api/webhooks/epcis/hub/'.$provider);
    }

    private static function build(string $path): string
    {
        $centralDomain = (string) config('tracepharma.central_domain', 'localhost');

        return 'https://'.$centralDomain.$path;
    }
}
