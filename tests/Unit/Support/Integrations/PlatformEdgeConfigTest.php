<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Integrations;

use App\Models\PlatformSetting;
use App\Support\Integrations\PlatformAs2Station;
use App\Support\Integrations\PlatformSftpConfig;
use App\Support\PlatformSettings;
use Illuminate\Support\Facades\Crypt;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlatformEdgeConfigTest extends TestCase
{
    /** @var list<string> */
    private const KEYS = [
        'platform_as2.demo.station_id',
        'platform_as2.demo.signing_cert_pem',
        'platform_as2.demo.signing_key_pem',
        'platform_as2.demo.decrypt_cert_pem',
        'platform_as2.demo.decrypt_key_pem',
        'platform_as2.demo.senders',
        'platform_sftp.demo.host',
        'platform_sftp.demo.port',
        'platform_sftp.demo.username',
        'platform_sftp.demo.password',
        'platform_sftp.demo.private_key',
        'platform_sftp.demo.passphrase',
        'platform_sftp.demo.inbound_path',
        'platform_sftp.demo.processed_path',
        'platform_sftp.demo.outbound_path',
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
    public function as2_station_round_trips_and_encrypts_pems_at_rest(): void
    {
        $station = app(PlatformAs2Station::class);

        $station->save('demo', [
            'station_id' => 'TRACEPHARMA-DEMO',
            'signing_cert_pem' => "-----BEGIN CERTIFICATE-----\nsigning\n-----END CERTIFICATE-----",
            'signing_key_pem' => "-----BEGIN PRIVATE KEY-----\nsignkey\n-----END PRIVATE KEY-----",
            'decrypt_cert_pem' => "-----BEGIN CERTIFICATE-----\ndecrypt\n-----END CERTIFICATE-----",
            'decrypt_key_pem' => "-----BEGIN PRIVATE KEY-----\ndeckey\n-----END PRIVATE KEY-----",
        ]);

        $this->assertSame('TRACEPHARMA-DEMO', $station->stationId('demo'));
        $this->assertStringContainsString('signing', (string) $station->signingCertPem('demo'));
        $this->assertStringContainsString('deckey', (string) $station->decryptKeyPem('demo'));
        $this->assertTrue($station->isConfigured('demo'));

        $raw = PlatformSetting::query()->where('key', 'platform_as2.demo.signing_key_pem')->firstOrFail();
        $this->assertStringNotContainsString('signkey', (string) $raw->value);
        $this->assertStringContainsString('signkey', Crypt::decryptString((string) $raw->value));
    }

    #[Test]
    public function as2_save_leaves_absent_keys_untouched_and_clears_empty_values(): void
    {
        $station = app(PlatformAs2Station::class);
        $station->save('demo', ['station_id' => 'TRACEPHARMA-DEMO', 'signing_cert_pem' => 'cert-one']);

        // Absent keys are untouched (write-only UI fields submit blank when unchanged).
        $station->save('demo', ['station_id' => 'TRACEPHARMA-DEMO-2']);
        $this->assertSame('TRACEPHARMA-DEMO-2', $station->stationId('demo'));
        $this->assertSame('cert-one', $station->signingCertPem('demo'));

        $station->save('demo', ['signing_cert_pem' => '']);
        $this->assertNull($station->signingCertPem('demo'));
    }

    #[Test]
    public function as2_sender_registry_normalizes_and_looks_up_by_as2_id(): void
    {
        $station = app(PlatformAs2Station::class);

        $station->setSenders('demo', [
            ['label' => 'Acme QA', 'as2_id' => 'ACME-TEST', 'signing_cert_pem' => 'cert-acme'],
            ['label' => null, 'as2_id' => '', 'signing_cert_pem' => 'cert-incomplete'],
            ['label' => 'No cert', 'as2_id' => 'NOCERT', 'signing_cert_pem' => ' '],
        ]);

        $senders = $station->senders('demo');

        $this->assertCount(1, $senders);
        $this->assertSame('ACME-TEST', $senders[0]['as2_id']);
        $this->assertSame('cert-acme', $station->senderCertificate('demo', 'ACME-TEST'));
        $this->assertNull($station->senderCertificate('demo', 'UNKNOWN'));

        // Registry is encrypted at rest.
        $raw = PlatformSetting::query()->where('key', 'platform_as2.demo.senders')->firstOrFail();
        $this->assertStringNotContainsString('cert-acme', (string) $raw->value);

        $station->setSenders('demo', []);
        $this->assertSame([], $station->senders('demo'));
    }

    #[Test]
    public function as2_public_certificate_prefers_decrypt_cert_and_builds_station_url(): void
    {
        $station = app(PlatformAs2Station::class);

        $station->save('demo', ['signing_cert_pem' => 'signing-cert']);
        $this->assertSame('signing-cert', $station->publicCertificatePem('demo'));

        $station->save('demo', ['decrypt_cert_pem' => 'decrypt-cert']);
        $this->assertSame('decrypt-cert', $station->publicCertificatePem('demo'));

        $this->assertSame(
            'https://admin2.internal.vatengi.com/api/webhooks/as2/hub',
            $station->as2Url('demo'),
        );
    }

    #[Test]
    public function sftp_config_round_trips_with_defaults_and_encrypts_secrets(): void
    {
        $sftp = app(PlatformSftpConfig::class);

        $this->assertFalse($sftp->isConfigured('demo'));
        $this->assertSame(22, $sftp->port('demo'));
        $this->assertSame('/', $sftp->inboundPath('demo'));
        $this->assertSame('processed', $sftp->processedPath('demo'));
        $this->assertSame('outbound', $sftp->outboundPath('demo'));

        $sftp->save('demo', [
            'host' => 'sftp.tracepharma.io',
            'port' => '2222',
            'username' => 'tracepharma',
            'private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nkey\n-----END OPENSSH PRIVATE KEY-----",
            'inbound_path' => '/drops/inbound',
        ]);

        $this->assertTrue($sftp->isConfigured('demo'));
        $this->assertSame('sftp.tracepharma.io', $sftp->host('demo'));
        $this->assertSame(2222, $sftp->port('demo'));
        $this->assertSame('/drops/inbound', $sftp->inboundPath('demo'));

        $raw = PlatformSetting::query()->where('key', 'platform_sftp.demo.private_key')->firstOrFail();
        $this->assertStringNotContainsString('OPENSSH', (string) $raw->value);
        $this->assertStringContainsString('OPENSSH', Crypt::decryptString((string) $raw->value));
    }

    #[Test]
    public function sftp_is_configured_requires_host_username_and_one_credential(): void
    {
        $sftp = app(PlatformSftpConfig::class);

        $sftp->save('demo', ['host' => 'sftp.example.com']);
        $this->assertFalse($sftp->isConfigured('demo'));

        $sftp->save('demo', ['username' => 'tracepharma']);
        $this->assertFalse($sftp->isConfigured('demo'));

        $sftp->save('demo', ['password' => 'secret']);
        $this->assertTrue($sftp->isConfigured('demo'));
    }
}
