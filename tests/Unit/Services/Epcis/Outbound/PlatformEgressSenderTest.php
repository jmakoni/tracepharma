<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Epcis\Outbound;

use App\Enums\OutboundTransport;
use App\Models\OutboundConnection;
use App\Models\OutboundNetworkProfile;
use App\Services\Epcis\Outbound\As2OutboundSender;
use App\Services\Epcis\Outbound\HttpsOutboundSender;
use App\Services\Epcis\Outbound\SftpOutboundSender;
use App\Support\EpcisHub\EpcisHubPlatformConfig;
use App\Support\EpcisHub\PlatformOutboundEgress;
use App\Support\Integrations\PlatformAs2Station;
use App\Support\Integrations\PlatformSftpConfig;
use App\Support\PlatformSettings;
use DomainException;
use Illuminate\Support\Facades\Http;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlatformEgressSenderTest extends TestCase
{
    private string $environment;

    /** @var list<int> */
    private array $createdProfileIds = [];

    /** @var array{cert: string, key: string}|null */
    private static ?array $stationPems = null;

    /** @var array{cert: string, key: string}|null */
    private static ?array $tenantPems = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->environment = app(EpcisHubPlatformConfig::class)->currentEnvironment();
        $this->forgetPlatformKeys();
    }

    protected function tearDown(): void
    {
        $this->forgetPlatformKeys();

        if ($this->createdProfileIds !== []) {
            OutboundNetworkProfile::query()->whereIn('id', $this->createdProfileIds)->delete();
        }

        parent::tearDown();
    }

    #[Test]
    public function https_send_posts_to_platform_edge_with_edge_token(): void
    {
        Http::fake(['https://edge.unitrace.example.com/*' => Http::response('', 200)]);

        $this->configureEdge('unitrace');

        $connection = new OutboundConnection([
            'name' => 'UniTrace via platform edge',
            'transport' => OutboundTransport::Https,
            'network_profile_id' => $this->profile('unitrace')->id,
            'override_endpoint' => false,
            'credentials' => ['webhook_token' => 'tenant-token'],
            'settings' => ['endpoint_url' => 'https://profile-endpoint.unitrace.example.com/inbound'],
        ]);

        app(HttpsOutboundSender::class)->send($connection, '<epcis/>', 'doc.xml');

        Http::assertSent(fn ($request): bool => $request->url() === 'https://edge.unitrace.example.com/inbound'
            && $request->hasHeader('X-Inbound-Token', 'edge-token-123'));
    }

    #[Test]
    public function https_send_uses_connection_endpoint_when_endpoint_overridden(): void
    {
        Http::fake(['https://partner.example.com/*' => Http::response('', 200)]);

        $this->configureEdge('unitrace');

        $connection = new OutboundConnection([
            'name' => 'Own endpoint',
            'transport' => OutboundTransport::Https,
            'network_profile_id' => $this->profile('unitrace')->id,
            'override_endpoint' => true,
            'credentials' => ['webhook_token' => 'tenant-token'],
            'settings' => ['endpoint_url' => 'https://partner.example.com/epcis'],
        ]);

        app(HttpsOutboundSender::class)->send($connection, '<epcis/>', 'doc.xml');

        Http::assertSent(fn ($request): bool => $request->url() === 'https://partner.example.com/epcis'
            && $request->hasHeader('X-Inbound-Token', 'tenant-token'));
    }

    #[Test]
    public function as2_send_signs_with_platform_station_when_connection_lacks_signing_pair(): void
    {
        Http::fake(['https://as2.unitrace.example.com/*' => Http::response('', 200)]);

        $station = app(PlatformAs2Station::class);
        $pems = self::stationPems();
        $station->save($this->environment, [
            'station_id' => 'TRACEPHARMA-DEMO',
            'signing_cert_pem' => $pems['cert'],
            'signing_key_pem' => $pems['key'],
        ]);

        $connection = new OutboundConnection([
            'name' => 'UniTrace AS2 via station',
            'transport' => OutboundTransport::As2,
            'network_profile_id' => $this->profile('unitrace')->id,
            'override_endpoint' => false,
            'credentials' => [],
            'settings' => ['as2_from' => 'TENANT-AS2'],
        ]);

        $result = app(As2OutboundSender::class)->send($connection, '<?xml version="1.0"?><epcis/>', 'doc.xml');

        $this->assertTrue($result->smimeApplied);
        $this->assertTrue($result->certificatesConfigured);

        Http::assertSent(function ($request): bool {
            $contentType = $request->header('Content-Type')[0] ?? '';

            return $request->hasHeader('AS2-From', 'TRACEPHARMA-DEMO')
                && $request->hasHeader('AS2-To', 'UNITRACE-HUB')
                && str_contains($contentType, 'pkcs7');
        });
    }

    #[Test]
    public function as2_send_prefers_connection_signing_pair_over_platform_station(): void
    {
        Http::fake(['https://as2.unitrace.example.com/*' => Http::response('', 200)]);

        $station = app(PlatformAs2Station::class);
        $stationPems = self::stationPems();
        $station->save($this->environment, [
            'station_id' => 'TRACEPHARMA-DEMO',
            'signing_cert_pem' => $stationPems['cert'],
            'signing_key_pem' => $stationPems['key'],
        ]);

        $tenantPems = self::tenantPems();

        $connection = new OutboundConnection([
            'name' => 'UniTrace AS2 with own certs',
            'transport' => OutboundTransport::As2,
            'network_profile_id' => $this->profile('unitrace')->id,
            'override_endpoint' => false,
            'credentials' => [
                'signing_cert_pem' => $tenantPems['cert'],
                'signing_key_pem' => $tenantPems['key'],
            ],
            'settings' => ['as2_from' => 'TENANT-AS2'],
        ]);

        $result = app(As2OutboundSender::class)->send($connection, '<?xml version="1.0"?><epcis/>', 'doc.xml');

        $this->assertTrue($result->smimeApplied);

        Http::assertSent(fn ($request): bool => $request->hasHeader('AS2-From', 'TENANT-AS2'));
    }

    #[Test]
    public function sftp_send_uses_platform_edge_when_connection_lacks_credentials(): void
    {
        $sftpConfig = app(PlatformSftpConfig::class);
        $sftpConfig->save($this->environment, [
            'host' => 'sftp.tracepharma.example.com',
            'username' => 'tracepharma',
            'password' => 'secret',
            'host_fingerprint' => 'aa:bb:cc:dd:ee:ff:00:11:22:33:44:55:66:77:88:99',
            'outbound_path' => 'outbound/edge',
        ]);

        $root = sys_get_temp_dir().'/egress-sftp-'.uniqid();
        $fakeFilesystem = new Filesystem(new LocalFilesystemAdapter($root));

        $sender = $this->platformSftpSender($sftpConfig, $fakeFilesystem);

        $connection = new OutboundConnection([
            'name' => 'UniTrace SFTP via platform',
            'transport' => OutboundTransport::Sftp,
            'network_profile_id' => $this->profile('unitrace')->id,
            'override_endpoint' => false,
            'credentials' => [],
            'settings' => [],
        ]);

        $sender->send($connection, '<epcis/>', 'doc.xml');

        $this->assertFileExists($root.'/outbound/edge/doc.xml');
        $this->assertSame('<epcis/>', file_get_contents($root.'/outbound/edge/doc.xml'));
    }

    #[Test]
    public function sftp_platform_egress_ignores_tenant_outbound_path(): void
    {
        $sftpConfig = app(PlatformSftpConfig::class);
        $sftpConfig->save($this->environment, [
            'host' => 'sftp.tracepharma.example.com',
            'username' => 'tracepharma',
            'password' => 'secret',
            'host_fingerprint' => 'aa:bb:cc:dd:ee:ff:00:11:22:33:44:55:66:77:88:99',
            'inbound_path' => 'inbound',
            'outbound_path' => 'outbound/edge',
        ]);

        $root = sys_get_temp_dir().'/egress-sftp-ignore-'.uniqid();
        $fakeFilesystem = new Filesystem(new LocalFilesystemAdapter($root));
        $sender = $this->platformSftpSender($sftpConfig, $fakeFilesystem);

        $connection = new OutboundConnection([
            'name' => 'UniTrace SFTP via platform',
            'transport' => OutboundTransport::Sftp,
            'network_profile_id' => $this->profile('unitrace')->id,
            'override_endpoint' => false,
            'credentials' => [],
            'settings' => ['outbound_path' => 'tenant/hijack'],
        ]);

        $sender->send($connection, '<epcis/>', 'doc.xml');

        $this->assertFileExists($root.'/outbound/edge/doc.xml');
        $this->assertFileDoesNotExist($root.'/tenant/hijack/doc.xml');
    }

    #[Test]
    public function sftp_platform_egress_rejects_when_outbound_equals_inbound(): void
    {
        $sftpConfig = app(PlatformSftpConfig::class);
        $sftpConfig->save($this->environment, [
            'host' => 'sftp.tracepharma.example.com',
            'username' => 'tracepharma',
            'password' => 'secret',
            'host_fingerprint' => 'aa:bb:cc:dd:ee:ff:00:11:22:33:44:55:66:77:88:99',
            'inbound_path' => '/shared/drop',
            'outbound_path' => 'shared/drop',
        ]);

        $sender = $this->platformSftpSender(
            $sftpConfig,
            new Filesystem(new LocalFilesystemAdapter(sys_get_temp_dir().'/egress-sftp-collide-'.uniqid())),
        );

        $connection = new OutboundConnection([
            'name' => 'UniTrace SFTP via platform',
            'transport' => OutboundTransport::Sftp,
            'network_profile_id' => $this->profile('unitrace')->id,
            'override_endpoint' => false,
            'credentials' => [],
            'settings' => [],
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('outbound_path must not equal inbound_path');

        $sender->send($connection, '<epcis/>', 'doc.xml');
    }

    #[Test]
    public function sftp_send_prefers_connection_credentials_over_platform_edge(): void
    {
        $sftpConfig = app(PlatformSftpConfig::class);
        $sftpConfig->save($this->environment, [
            'host' => 'sftp.tracepharma.example.com',
            'username' => 'tracepharma',
            'password' => 'secret',
            'host_fingerprint' => 'aa:bb:cc:dd:ee:ff:00:11:22:33:44:55:66:77:88:99',
            'outbound_path' => 'outbound/edge',
        ]);

        $root = sys_get_temp_dir().'/egress-sftp-own-'.uniqid();
        $fakeFilesystem = new Filesystem(new LocalFilesystemAdapter($root));

        $connection = new OutboundConnection([
            'name' => 'Own SFTP',
            'transport' => OutboundTransport::Sftp,
            'network_profile_id' => $this->profile('unitrace')->id,
            'override_endpoint' => false,
            'credentials' => ['host' => 'sftp.partner.example.com', 'username' => 'partner'],
            'settings' => ['outbound_path' => 'partner/inbound'],
        ]);

        app(SftpOutboundSender::class)->send($connection, '<epcis/>', 'doc.xml', $fakeFilesystem);

        $this->assertFileExists($root.'/partner/inbound/doc.xml');
        $this->assertFileDoesNotExist($root.'/outbound/edge/doc.xml');
    }

    private function platformSftpSender(PlatformSftpConfig $sftpConfig, Filesystem $fakeFilesystem): SftpOutboundSender
    {
        $sender = new class(app(PlatformOutboundEgress::class), $sftpConfig, app(EpcisHubPlatformConfig::class)) extends SftpOutboundSender
        {
            public ?Filesystem $fakeFilesystem = null;

            protected function platformFilesystem(string $environment): Filesystem
            {
                return $this->fakeFilesystem ?? parent::platformFilesystem($environment);
            }
        };
        $sender->fakeFilesystem = $fakeFilesystem;

        return $sender;
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

    private function forgetPlatformKeys(): void
    {
        foreach (['systech', 'unitrace'] as $provider) {
            PlatformSettings::forget("epcis_hub.{$this->environment}.outbound_url_{$provider}");
            PlatformSettings::forget("epcis_hub.{$this->environment}.outbound_token_{$provider}");
        }

        foreach (['station_id', 'signing_cert_pem', 'signing_key_pem', 'decrypt_cert_pem', 'decrypt_key_pem', 'senders'] as $key) {
            PlatformSettings::forget("platform_as2.{$this->environment}.{$key}");
        }

        foreach (['host', 'port', 'username', 'password', 'private_key', 'passphrase', 'host_fingerprint', 'inbound_path', 'processed_path', 'outbound_path'] as $key) {
            PlatformSettings::forget("platform_sftp.{$this->environment}.{$key}");
        }
    }

    /**
     * @return array{cert: string, key: string}
     */
    private static function stationPems(): array
    {
        return self::$stationPems ??= self::generatePemPair('tracepharma-egress-station-test');
    }

    /**
     * @return array{cert: string, key: string}
     */
    private static function tenantPems(): array
    {
        return self::$tenantPems ??= self::generatePemPair('tracepharma-egress-tenant-test');
    }

    /**
     * @return array{cert: string, key: string}
     */
    private static function generatePemPair(string $commonName): array
    {
        $config = [
            'digest_alg' => 'sha256',
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];

        $privateKey = openssl_pkey_new($config);
        self::assertNotFalse($privateKey);

        $csr = openssl_csr_new(['commonName' => $commonName], $privateKey, $config);
        self::assertNotFalse($csr);

        $cert = openssl_csr_sign($csr, null, $privateKey, 1, $config);
        self::assertNotFalse($cert);

        openssl_x509_export($cert, $certPem);
        openssl_pkey_export($privateKey, $keyPem);

        return ['cert' => $certPem, 'key' => $keyPem];
    }
}
