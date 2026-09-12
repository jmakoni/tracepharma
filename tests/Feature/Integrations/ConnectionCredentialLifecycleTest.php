<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Enums\InboundTransport;
use App\Enums\OutboundTransport;
use App\Enums\SerializationProvider;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Models\InboundConnection;
use App\Models\OutboundConnection;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\ComplianceAlertNotification;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\Integrations\CredentialExpiry;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use OpenSSLCertificate;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ConnectionCredentialLifecycleTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $inboundConnectionIds = [];

    /** @var list<int> */
    private array $outboundConnectionIds = [];

    /** @var list<int> */
    private array $userIds = [];

    #[Test]
    public function credential_expiry_states_follow_the_warning_window(): void
    {
        $this->assertSame(CredentialExpiry::STATE_UNKNOWN, CredentialExpiry::state(null));
        $this->assertSame(CredentialExpiry::STATE_EXPIRED, CredentialExpiry::state(now()->subDay()));
        $this->assertSame(CredentialExpiry::STATE_EXPIRING, CredentialExpiry::state(now()->addDays(10)));
        $this->assertSame(CredentialExpiry::STATE_OK, CredentialExpiry::state(now()->addDays(120)));
    }

    #[Test]
    public function outbound_as2_cert_expiry_is_mirrored_onto_the_connection_on_save(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $connection = OutboundConnection::query()->create([
                'name' => 'AS2 expiry '.Str::random(4),
                'serialization_provider' => SerializationProvider::CustomAs2,
                'transport' => OutboundTransport::As2,
                'is_active' => false,
                'credentials' => [
                    'signing_cert_pem' => $this->generateSelfSignedCertPem(10),
                ],
                'settings' => ['as2_url' => 'https://partner.example/as2'],
            ]);
            $this->outboundConnectionIds[] = (int) $connection->getKey();

            $expires = $connection->fresh()->credentials_expire_at;

            $this->assertNotNull($expires);
            $this->assertTrue($expires->gt(now()->addDays(8)));
            $this->assertTrue($expires->lt(now()->addDays(12)));
            $this->assertSame(CredentialExpiry::STATE_EXPIRING, CredentialExpiry::state($expires));
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function credential_expiry_report_notifies_tenant_owners(): void
    {
        Notification::fake();
        $this->initializeDemo2Tenant();

        try {
            $owner = $this->createOwner();

            $expiring = InboundConnection::query()->create([
                'name' => 'Expiring inbound '.Str::random(4),
                'serialization_provider' => SerializationProvider::CustomHttps,
                'transport' => InboundTransport::Https,
                'is_active' => true,
                'credentials_expire_at' => now()->addDays(5),
            ]);
            $this->inboundConnectionIds[] = (int) $expiring->getKey();

            $healthy = InboundConnection::query()->create([
                'name' => 'Healthy inbound '.Str::random(4),
                'serialization_provider' => SerializationProvider::CustomHttps,
                'transport' => InboundTransport::Https,
                'is_active' => true,
                'credentials_expire_at' => now()->addDays(120),
            ]);
            $this->inboundConnectionIds[] = (int) $healthy->getKey();

            tenancy()->end();

            $this->artisan('connections:credential-expiry-report', [
                '--tenant' => self::DEMO2_TENANT_ID,
                '--days' => 30,
            ])->assertSuccessful();

            Notification::assertSentTo(
                $owner,
                ComplianceAlertNotification::class,
                fn (ComplianceAlertNotification $n): bool => str_contains($n->message, $expiring->name)
                    && ! str_contains($n->message, $healthy->name),
            );
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function rotate_token_regenerates_inbound_tokens_with_instant_cutover(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $connection = InboundConnection::query()->create([
                'name' => 'Rotate me '.Str::random(4),
                'serialization_provider' => SerializationProvider::CustomHttps,
                'transport' => InboundTransport::Https,
                'is_active' => true,
            ]);
            $this->inboundConnectionIds[] = (int) $connection->getKey();
            $oldToken = $connection->inbound_token;

            tenancy()->end();

            $this->artisan('connections:rotate-token', [
                'tenant' => self::DEMO2_TENANT_ID,
                'connection' => $connection->getKey(),
                '--direction' => 'inbound',
                '--show' => true,
            ])->assertSuccessful();

            tenancy()->initialize(Tenant::query()->findOrFail(self::DEMO2_TENANT_ID));

            $newToken = $connection->fresh()->inbound_token;
            $this->assertNotSame($oldToken, $newToken);
            $this->assertNotEmpty($newToken);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function rotate_token_for_outbound_requires_a_partner_issued_token(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $connection = OutboundConnection::query()->create([
                'name' => 'Outbound token '.Str::random(4),
                'serialization_provider' => SerializationProvider::CustomHttps,
                'transport' => OutboundTransport::Https,
                'is_active' => true,
                'settings' => ['endpoint_url' => 'https://partner.example/epcis'],
            ]);
            $this->outboundConnectionIds[] = (int) $connection->getKey();

            tenancy()->end();

            $this->artisan('connections:rotate-token', [
                'tenant' => self::DEMO2_TENANT_ID,
                'connection' => $connection->getKey(),
                '--direction' => 'outbound',
            ])->assertFailed();

            $this->artisan('connections:rotate-token', [
                'tenant' => self::DEMO2_TENANT_ID,
                'connection' => $connection->getKey(),
                '--direction' => 'outbound',
                '--set-token' => 'partner-issued-secret',
            ])->assertSuccessful();

            tenancy()->initialize(Tenant::query()->findOrFail(self::DEMO2_TENANT_ID));

            $this->assertSame(
                'partner-issued-secret',
                $connection->fresh()->credentials['webhook_token'] ?? null,
            );
        } finally {
            $this->cleanup();
        }
    }

    private function initializeDemo2Tenant(): Tenant
    {
        $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);

        if ($tenant === null) {
            $tenant = Tenant::withoutEvents(fn () => Tenant::query()->create([
                'id' => self::DEMO2_TENANT_ID,
                'name' => 'Demo Pharmacy',
                'profile' => TenantProfile::Pharmacy,
                'status' => 'active',
                'tenancy_db_name' => self::DEMO2_DATABASE,
            ]));

            $tenant->domains()->create(['domain' => self::DEMO2_DOMAIN]);
        } else {
            $tenant->domains()->firstOrCreate(['domain' => self::DEMO2_DOMAIN]);
        }

        if (! self::$demo2TenantReady) {
            $this->artisan('tenants:migrate', [
                '--tenants' => [self::DEMO2_TENANT_ID],
                '--force' => true,
            ])->assertSuccessful();

            self::$demo2TenantReady = true;
        }

        tenancy()->initialize($tenant);

        return $tenant;
    }

    private function createOwner(): User
    {
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
        $user = User::factory()->create([
            'email' => 'owner-cred-'.Str::uuid().'@example.com',
        ]);
        $user->assignRole(TenantRole::Owner->value);
        $this->userIds[] = (int) $user->getKey();

        return $user;
    }

    private function generateSelfSignedCertPem(int $validDays): string
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        $this->assertNotFalse($key);

        $csr = openssl_csr_new(['commonName' => 'Credential Lifecycle Test'], $key, ['digest_alg' => 'sha256']);
        $this->assertNotFalse($csr);

        $cert = openssl_csr_sign($csr, null, $key, $validDays);
        $this->assertInstanceOf(OpenSSLCertificate::class, $cert);

        $pem = '';
        $this->assertTrue(openssl_x509_export($cert, $pem));

        return $pem;
    }

    private function cleanup(): void
    {
        $hasTenantArtifacts = $this->inboundConnectionIds !== []
            || $this->outboundConnectionIds !== []
            || $this->userIds !== [];

        if ($hasTenantArtifacts && ! tenancy()->initialized) {
            $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);

            if ($tenant !== null) {
                tenancy()->initialize($tenant);
            }
        }

        if (tenancy()->initialized) {
            if ($this->inboundConnectionIds !== []) {
                InboundConnection::query()->whereIn('id', $this->inboundConnectionIds)->delete();
                $this->inboundConnectionIds = [];
            }

            if ($this->outboundConnectionIds !== []) {
                OutboundConnection::query()->whereIn('id', $this->outboundConnectionIds)->delete();
                $this->outboundConnectionIds = [];
            }

            if ($this->userIds !== []) {
                User::query()->whereIn('id', $this->userIds)->delete();
                $this->userIds = [];
            }

            tenancy()->end();
        }
    }
}
