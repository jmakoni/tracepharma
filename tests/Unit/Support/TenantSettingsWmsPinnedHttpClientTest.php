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
}
