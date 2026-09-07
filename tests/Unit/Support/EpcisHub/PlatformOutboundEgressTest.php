<?php

declare(strict_types=1);

namespace Tests\Unit\Support\EpcisHub;

use App\Enums\OutboundTransport;
use App\Models\OutboundConnection;
use App\Models\OutboundNetworkProfile;
use App\Support\EpcisHub\EpcisHubPlatformConfig;
use App\Support\EpcisHub\PlatformOutboundEgress;
use App\Support\PlatformSettings;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlatformOutboundEgressTest extends TestCase
{
    private string $environment;

    /** @var list<int> */
    private array $createdProfileIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->environment = app(EpcisHubPlatformConfig::class)->currentEnvironment();
        $this->forgetEdgeKeys();
    }

    protected function tearDown(): void
    {
        $this->forgetEdgeKeys();

        if ($this->createdProfileIds !== []) {
            OutboundNetworkProfile::query()->whereIn('id', $this->createdProfileIds)->delete();
        }

        parent::tearDown();
    }

    #[Test]
    public function egress_applies_to_profile_linked_unitrace_https_connection_when_edge_configured(): void
    {
        $this->configureEdge('unitrace');

        $egress = app(PlatformOutboundEgress::class);
        $connection = $this->connection($this->profile('unitrace'));

        $this->assertTrue($egress->usesPlatformEgress($connection));

        $edge = $egress->edgeFor($connection);
        $this->assertSame('https://edge.unitrace.example.com/inbound', $edge['url']);
        $this->assertSame('edge-token-123', $edge['token']);
    }

    #[Test]
    public function egress_requires_a_configured_edge(): void
    {
        $connection = $this->connection($this->profile('systech'));

        $this->assertFalse(app(PlatformOutboundEgress::class)->usesPlatformEgress($connection));
    }

    #[Test]
    public function egress_skips_overridden_endpoints_non_egress_networks_and_unlinked_connections(): void
    {
        $this->configureEdge('unitrace');
        $egress = app(PlatformOutboundEgress::class);

        $overridden = $this->connection($this->profile('unitrace'), override: true);
        $this->assertFalse($egress->usesPlatformEgress($overridden));

        $tracelink = $this->connection($this->profile('tracelink'));
        $this->assertFalse($egress->usesPlatformEgress($tracelink));

        $unlinked = $this->connection(null);
        $this->assertFalse($egress->usesPlatformEgress($unlinked));
    }

    #[Test]
    public function egress_requires_https_transport(): void
    {
        $this->configureEdge('unitrace');

        $sftp = $this->connection($this->profile('unitrace'), transport: OutboundTransport::Sftp);

        $this->assertFalse(app(PlatformOutboundEgress::class)->usesPlatformEgress($sftp));
    }

    #[Test]
    public function tracepharma_hub_profile_sends_with_the_platform_hub_token_not_the_stored_copy(): void
    {
        $egress = app(PlatformOutboundEgress::class);
        $profile = $this->profile('tracepharma');
        $connection = $this->connection($profile, credentials: ['webhook_token' => 'stale-tenant-copy']);

        $edge = $egress->edgeFor($connection);

        $this->assertNotNull($edge);
        $this->assertSame($profile->endpoint_url, $edge['url']);
        $this->assertSame(
            app(EpcisHubPlatformConfig::class)->hubToken($profile->environment),
            $edge['token'],
        );
    }

    #[Test]
    public function tracepharma_hub_edge_respects_endpoint_override_and_unlinked_connections(): void
    {
        $egress = app(PlatformOutboundEgress::class);

        $overridden = $this->connection($this->profile('tracepharma'), override: true);
        $this->assertFalse($egress->usesPlatformEgress($overridden));

        $unlinked = $this->connection(null);
        $this->assertFalse($egress->usesPlatformEgress($unlinked));
    }

    #[Test]
    public function platform_as2_signing_applies_only_when_hub_linked_without_own_pair(): void
    {
        $egress = app(PlatformOutboundEgress::class);
        $profile = $this->profile('unitrace');

        $linked = $this->connection($profile, transport: OutboundTransport::As2);
        $this->assertTrue($egress->usesPlatformAs2Signing($linked));

        $withOwnPair = $this->connection($profile, transport: OutboundTransport::As2, credentials: [
            'signing_cert_pem' => 'cert',
            'signing_key_pem' => 'key',
        ]);
        $this->assertFalse($egress->usesPlatformAs2Signing($withOwnPair));

        $https = $this->connection($profile);
        $this->assertFalse($egress->usesPlatformAs2Signing($https));

        $unlinked = $this->connection(null, transport: OutboundTransport::As2);
        $this->assertFalse($egress->usesPlatformAs2Signing($unlinked));
    }

    #[Test]
    public function platform_sftp_applies_only_when_hub_linked_without_own_credentials(): void
    {
        $egress = app(PlatformOutboundEgress::class);
        $profile = $this->profile('unitrace');

        $linked = $this->connection($profile, transport: OutboundTransport::Sftp);
        $this->assertTrue($egress->usesPlatformSftp($linked));

        $withCreds = $this->connection($profile, transport: OutboundTransport::Sftp, credentials: [
            'host' => 'sftp.partner.example.com',
            'username' => 'partner',
        ]);
        $this->assertFalse($egress->usesPlatformSftp($withCreds));

        $unlinked = $this->connection(null, transport: OutboundTransport::Sftp);
        $this->assertFalse($egress->usesPlatformSftp($unlinked));
    }

    private function connection(
        ?OutboundNetworkProfile $profile,
        bool $override = false,
        OutboundTransport $transport = OutboundTransport::Https,
        array $credentials = [],
        array $settings = [],
    ): OutboundConnection {
        return new OutboundConnection([
            'name' => 'Egress test',
            'transport' => $transport,
            'network_profile_id' => $profile?->id,
            'override_endpoint' => $override,
            'credentials' => $credentials,
            'settings' => $settings,
        ]);
    }

    private function profile(string $slug): OutboundNetworkProfile
    {
        $profile = OutboundNetworkProfile::query()->firstOrCreate(
            ['network_slug' => $slug, 'environment' => $this->environment],
            [
                'label' => ucfirst($slug).' ('.$this->environment.')',
                'default_transport' => 'https',
                'allowed_transports' => ['https', 'as2', 'sftp'],
                'endpoint_url' => 'https://profile-endpoint.'.$slug.'.example.com/inbound',
                'as2_url' => 'https://as2.'.$slug.'.example.com/hub',
                'as2_to' => strtoupper($slug).'-HUB',
            ],
        );

        if ($profile->wasRecentlyCreated) {
            $this->createdProfileIds[] = (int) $profile->id;
        }

        return $profile;
    }

    private function configureEdge(string $provider): void
    {
        PlatformSettings::put("epcis_hub.{$this->environment}.outbound_url_{$provider}", 'https://edge.'.$provider.'.example.com/inbound');
        PlatformSettings::put("epcis_hub.{$this->environment}.outbound_token_{$provider}", 'edge-token-123');
    }

    private function forgetEdgeKeys(): void
    {
        foreach (['systech', 'unitrace'] as $provider) {
            PlatformSettings::forget("epcis_hub.{$this->environment}.outbound_url_{$provider}");
            PlatformSettings::forget("epcis_hub.{$this->environment}.outbound_token_{$provider}");
        }
    }
}
