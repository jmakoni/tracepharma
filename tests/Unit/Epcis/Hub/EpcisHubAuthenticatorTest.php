<?php

declare(strict_types=1);

namespace Tests\Unit\Epcis\Hub;

use App\Support\EpcisHub\EpcisHubPlatformConfig;
use App\Support\Integrations\EpcisHubAuthenticator;
use App\Support\PlatformSettings;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Tests\TestCase;

class EpcisHubAuthenticatorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        PlatformSettings::forget('epcis_hub.demo.hub_token');
        PlatformSettings::forget('epcis_hub.stage.hub_token');
        PlatformSettings::forget('epcis_hub.prod.hub_token');
        PlatformSettings::forget('epcis_hub.stage.hub_token_previous');
        PlatformSettings::forget('epcis_hub.stage.hub_token_previous_expires_at');

        config([
            'tracepharma.epcis_hub.hub_token' => 'legacy-env-token',
            'tracepharma.epcis_hub.demo.hub_token' => 'env-demo-token',
            'tracepharma.epcis_hub.stage.hub_token' => 'env-stage-token',
            'tracepharma.epcis_hub.prod.hub_token' => 'env-prod-token',
            'tracepharma.epcis_hub.demo.host' => 'admin2.internal.vatengi.com',
            'tracepharma.epcis_hub.stage.host' => 'stage.tracepharma.io',
            'tracepharma.epcis_hub.prod.host' => 'prod.tracepharma.io',
            'tracepharma.epcis_hub.testing_hosts' => [
                'localhost' => 'demo',
            ],
        ]);
    }

    protected function tearDown(): void
    {
        PlatformSettings::forget('epcis_hub.demo.hub_token');
        PlatformSettings::forget('epcis_hub.stage.hub_token');
        PlatformSettings::forget('epcis_hub.prod.hub_token');
        PlatformSettings::forget('epcis_hub.stage.hub_token_previous');
        PlatformSettings::forget('epcis_hub.stage.hub_token_previous_expires_at');

        parent::tearDown();
    }

    #[Test]
    public function platform_token_for_stage_host_wins_over_env(): void
    {
        app(EpcisHubPlatformConfig::class)->setHubToken('stage', 'platform-stage-token');

        $request = Request::create(
            'https://stage.tracepharma.io/api/webhooks/epcis/hub/systech',
            'POST',
            server: [
                'HTTP_HOST' => 'stage.tracepharma.io',
                'HTTP_X_EPCIS_HUB_TOKEN' => 'platform-stage-token',
            ],
        );

        $environment = app(EpcisHubAuthenticator::class)->authorize($request);

        $this->assertSame('stage', $environment);

        $envTokenRequest = Request::create(
            'https://stage.tracepharma.io/api/webhooks/epcis/hub/systech',
            'POST',
            server: [
                'HTTP_HOST' => 'stage.tracepharma.io',
                'HTTP_X_EPCIS_HUB_TOKEN' => 'env-stage-token',
            ],
        );

        $this->expectException(UnauthorizedHttpException::class);
        app(EpcisHubAuthenticator::class)->authorize($envTokenRequest);
    }

    #[Test]
    public function stage_accepts_x_inbound_token(): void
    {
        app(EpcisHubPlatformConfig::class)->setHubToken('stage', '2a166bc7-81e6-43ed-8881-d64ea85bc794');

        $request = Request::create(
            'https://stage.tracepharma.io/api/webhooks/epcis/hub/systech',
            'POST',
            server: [
                'HTTP_HOST' => 'stage.tracepharma.io',
                'HTTP_X_INBOUND_TOKEN' => '2a166bc7-81e6-43ed-8881-d64ea85bc794',
            ],
        );

        $this->assertSame('stage', app(EpcisHubAuthenticator::class)->authorize($request));
    }

    #[Test]
    public function demo_host_uses_demo_token(): void
    {
        app(EpcisHubPlatformConfig::class)->setHubToken('demo', 'platform-demo-token');

        $request = Request::create(
            'https://admin2.internal.vatengi.com/api/webhooks/epcis/hub/systech',
            'POST',
            server: [
                'HTTP_HOST' => 'admin2.internal.vatengi.com',
                'HTTP_X_EPCIS_HUB_TOKEN' => 'platform-demo-token',
            ],
        );

        $this->assertSame('demo', app(EpcisHubAuthenticator::class)->authorize($request));
    }

    #[Test]
    public function previous_token_is_accepted_during_rotation_grace(): void
    {
        $config = app(EpcisHubPlatformConfig::class);
        $config->setHubToken('stage', 'old-stage-token');
        $newToken = $config->rotateHubToken('stage');

        $oldTokenRequest = Request::create(
            'https://stage.tracepharma.io/api/webhooks/epcis/hub/systech',
            'POST',
            server: [
                'HTTP_HOST' => 'stage.tracepharma.io',
                'HTTP_X_EPCIS_HUB_TOKEN' => 'old-stage-token',
            ],
        );

        $this->assertSame('stage', app(EpcisHubAuthenticator::class)->authorize($oldTokenRequest));

        $newTokenRequest = Request::create(
            'https://stage.tracepharma.io/api/webhooks/epcis/hub/systech',
            'POST',
            server: [
                'HTTP_HOST' => 'stage.tracepharma.io',
                'HTTP_X_EPCIS_HUB_TOKEN' => $newToken,
            ],
        );

        $this->assertSame('stage', app(EpcisHubAuthenticator::class)->authorize($newTokenRequest));
    }

    #[Test]
    public function previous_token_is_rejected_after_rotation_grace(): void
    {
        $config = app(EpcisHubPlatformConfig::class);
        $config->setHubToken('stage', 'old-stage-token');
        $config->rotateHubToken('stage');

        $this->travel(EpcisHubPlatformConfig::TOKEN_ROTATION_GRACE_HOURS + 1)->hours();

        $request = Request::create(
            'https://stage.tracepharma.io/api/webhooks/epcis/hub/systech',
            'POST',
            server: [
                'HTTP_HOST' => 'stage.tracepharma.io',
                'HTTP_X_EPCIS_HUB_TOKEN' => 'old-stage-token',
            ],
        );

        $this->expectException(UnauthorizedHttpException::class);
        $this->expectExceptionMessage('Invalid EPCIS hub token.');

        app(EpcisHubAuthenticator::class)->authorize($request);
    }

    #[Test]
    public function unknown_host_returns_401(): void
    {
        $request = Request::create(
            'https://unknown.example.com/api/webhooks/epcis/hub/systech',
            'POST',
            server: [
                'HTTP_HOST' => 'unknown.example.com',
                'HTTP_X_EPCIS_HUB_TOKEN' => 'platform-stage-token',
            ],
        );

        $this->expectException(UnauthorizedHttpException::class);
        $this->expectExceptionMessage('Unknown EPCIS hub host.');

        app(EpcisHubAuthenticator::class)->authorize($request);
    }
}
