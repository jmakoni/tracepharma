<?php

declare(strict_types=1);

namespace Tests\Unit\Epcis\Hub;

use App\Support\EpcisHub\EpcisHubPlatformConfig;
use App\Support\PlatformSettings;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EpcisHubTokenRotationTest extends TestCase
{
    /** @var list<string> */
    private const KEYS = [
        'epcis_hub.demo.hub_token',
        'epcis_hub.demo.hub_token_previous',
        'epcis_hub.demo.hub_token_previous_expires_at',
        'epcis_hub.demo.host',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::KEYS as $key) {
            PlatformSettings::forget($key);
        }
    }

    protected function tearDown(): void
    {
        foreach (self::KEYS as $key) {
            PlatformSettings::forget($key);
        }

        parent::tearDown();
    }

    #[Test]
    public function rotate_generates_new_token_and_keeps_previous_during_grace(): void
    {
        $config = app(EpcisHubPlatformConfig::class);
        $config->setHubToken('demo', 'original-token');

        $newToken = $config->rotateHubToken('demo');

        $this->assertSame(64, strlen($newToken));
        $this->assertSame($newToken, $config->hubToken('demo'));
        $this->assertSame('original-token', $config->previousHubToken('demo'));

        $expiresAt = $config->previousHubTokenExpiresAt('demo');
        $this->assertNotNull($expiresAt);
        $this->assertTrue($expiresAt->isFuture());
        $this->assertTrue($expiresAt->diffInHours(now()) <= EpcisHubPlatformConfig::TOKEN_ROTATION_GRACE_HOURS);
    }

    #[Test]
    public function previous_token_is_rejected_after_grace_expires(): void
    {
        $config = app(EpcisHubPlatformConfig::class);
        $config->setHubToken('demo', 'original-token');
        $config->rotateHubToken('demo');

        $this->travel(EpcisHubPlatformConfig::TOKEN_ROTATION_GRACE_HOURS + 1)->hours();

        $this->assertNull($config->previousHubToken('demo'));
    }

    #[Test]
    public function rotate_preserves_config_fallback_token_as_previous(): void
    {
        config(['tracepharma.epcis_hub.demo.hub_token' => 'env-demo-token']);

        $config = app(EpcisHubPlatformConfig::class);
        $newToken = $config->rotateHubToken('demo');

        $this->assertSame($newToken, $config->hubToken('demo'));
        $this->assertSame('env-demo-token', $config->previousHubToken('demo'));
    }

    #[Test]
    public function rotate_without_any_existing_token_has_no_previous(): void
    {
        config([
            'tracepharma.epcis_hub.hub_token' => null,
            'tracepharma.epcis_hub.demo.hub_token' => null,
        ]);

        $config = app(EpcisHubPlatformConfig::class);
        $config->rotateHubToken('demo');

        $this->assertNull($config->previousHubToken('demo'));
        $this->assertNull($config->previousHubTokenExpiresAt('demo'));
    }

    #[Test]
    public function current_environment_derives_from_app_url_host(): void
    {
        config([
            'app.url' => 'https://stage.tracepharma.io',
            'tracepharma.epcis_hub.stage.host' => 'stage.tracepharma.io',
            'tracepharma.epcis_hub.testing_hosts' => [],
        ]);

        $this->assertSame('stage', app(EpcisHubPlatformConfig::class)->currentEnvironment());
    }

    #[Test]
    public function current_environment_falls_back_to_demo_for_unknown_hosts(): void
    {
        config([
            'app.url' => 'https://unknown.example.com',
            'tracepharma.epcis_hub.testing_hosts' => [],
        ]);

        $this->assertSame('demo', app(EpcisHubPlatformConfig::class)->currentEnvironment());
    }
}
