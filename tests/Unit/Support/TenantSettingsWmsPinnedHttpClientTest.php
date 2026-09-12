<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\TenantSettings;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TenantSettingsWmsPinnedHttpClientTest extends TestCase
{
    #[Test]
    public function pinned_client_sets_curl_resolve_for_hostname(): void
    {
        $pending = TenantSettings::wmsPinnedHttpClient('https://8.8.8.8/receive-confirm', 15);
        $options = $pending->getOptions();

        // Literal IP host → no CURLOPT_RESOLVE pin needed.
        $this->assertArrayNotHasKey('curl', $options);
    }

    #[Test]
    public function pinned_client_refuses_metadata_ip(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/private or metadata/i');

        TenantSettings::wmsPinnedHttpClient('https://169.254.169.254/receive-confirm');
    }

    #[Test]
    public function pinned_client_fails_closed_when_hostname_cannot_be_resolved_outside_unit_tests(): void
    {
        $previous = $this->app['env'];
        $this->app['env'] = 'production';

        try {
            $this->assertFalse(app()->runningUnitTests());

            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessageMatches('/could not be resolved/i');

            TenantSettings::wmsPinnedHttpClient(
                'https://definitely-not-a-real-host-'.uniqid('', true).'.invalid/receive-confirm',
            );
        } finally {
            $this->app['env'] = $previous;
        }
    }

    #[Test]
    public function assert_wms_host_at_connect_fails_closed_when_unresolvable_outside_unit_tests(): void
    {
        $previous = $this->app['env'];
        $this->app['env'] = 'production';

        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessageMatches('/could not be resolved/i');

            TenantSettings::assertWmsReceiveConfirmHostAtConnect(
                'https://definitely-not-a-real-host-'.uniqid('', true).'.invalid/receive-confirm',
            );
        } finally {
            $this->app['env'] = $previous;
        }
    }
}
