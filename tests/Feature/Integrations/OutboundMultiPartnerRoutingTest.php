<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Actions\Shipping\OpenOutboundShippingSession;
use App\Actions\Shipping\UpdateOutboundShippingParty;
use App\Enums\OutboundTransport;
use App\Enums\PartnerType;
use App\Enums\SerializationProvider;
use App\Enums\TenantProfile;
use App\Models\OutboundConnection;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Services\Epcis\OutboundConnectionResolver;
use App\Support\Integrations\OutboundConnectionDefaultSync;
use DomainException;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OutboundMultiPartnerRoutingTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $connectionIds = [];

    /** @var list<int> */
    private array $partnerIds = [];

    /** @var list<int> */
    private array $sessionIds = [];

    /** @var list<int> */
    private array $siteIds = [];

    #[Test]
    public function resolver_prefers_is_default_connection_for_partner(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $partner = $this->createPartner('Resolver default partner');

            $lowerId = OutboundConnection::query()->create([
                'name' => 'Partner HTTPS lower id',
                'serialization_provider' => SerializationProvider::CustomHttps,
                'transport' => OutboundTransport::Https,
                'trading_partner_id' => $partner->getKey(),
                'is_active' => true,
                'is_default' => false,
                'settings' => ['endpoint_url' => 'https://lower.example/epcis'],
            ]);
            $this->connectionIds[] = (int) $lowerId->getKey();

            $default = OutboundConnection::query()->create([
                'name' => 'Partner HTTPS default',
                'serialization_provider' => SerializationProvider::CustomHttps,
                'transport' => OutboundTransport::Https,
                'trading_partner_id' => $partner->getKey(),
                'is_active' => true,
                'is_default' => true,
                'settings' => ['endpoint_url' => 'https://default.example/epcis'],
            ]);
            $this->connectionIds[] = (int) $default->getKey();

            $resolved = app(OutboundConnectionResolver::class)->resolve((int) $partner->getKey());

            $this->assertNotNull($resolved);
            $this->assertSame($default->getKey(), $resolved->getKey());
        } finally {
            $this->cleanup();
            tenancy()->end();
        }
    }

    #[Test]
    public function resolver_falls_back_to_lowest_id_when_no_default(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $partner = $this->createPartner('Resolver lowest id partner');

            $first = OutboundConnection::query()->create([
                'name' => 'Partner HTTPS first',
                'serialization_provider' => SerializationProvider::CustomHttps,
                'transport' => OutboundTransport::Https,
                'trading_partner_id' => $partner->getKey(),
                'is_active' => true,
                'settings' => ['endpoint_url' => 'https://first.example/epcis'],
            ]);
            $this->connectionIds[] = (int) $first->getKey();

            $second = OutboundConnection::query()->create([
                'name' => 'Partner HTTPS second',
                'serialization_provider' => SerializationProvider::CustomHttps,
                'transport' => OutboundTransport::Https,
                'trading_partner_id' => $partner->getKey(),
                'is_active' => true,
                'settings' => ['endpoint_url' => 'https://second.example/epcis'],
            ]);
            $this->connectionIds[] = (int) $second->getKey();

            $resolved = app(OutboundConnectionResolver::class)->resolve((int) $partner->getKey());

            $this->assertNotNull($resolved);
            $this->assertSame($first->getKey(), $resolved->getKey());
        } finally {
            $this->cleanup();
            tenancy()->end();
        }
    }

    #[Test]
    public function update_outbound_shipping_party_rejects_partner_mismatched_connection(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $partnerA = $this->createPartner('Customer A');
            $partnerB = $this->createPartner('Customer B');

            $connectionForB = OutboundConnection::query()->create([
                'name' => 'Customer B HTTPS',
                'serialization_provider' => SerializationProvider::CustomHttps,
                'transport' => OutboundTransport::Https,
                'trading_partner_id' => $partnerB->getKey(),
                'is_active' => true,
                'settings' => ['endpoint_url' => 'https://customer-b.example/epcis'],
            ]);
            $this->connectionIds[] = (int) $connectionForB->getKey();

            $site = Site::query()->create([
                'name' => 'Ship site '.Str::random(6),
                'gln' => '0366159000010',
                'is_active' => true,
                'is_organization_facility' => true,
            ]);
            $this->siteIds[] = (int) $site->getKey();

            $session = app(OpenOutboundShippingSession::class)->handle((int) $site->getKey());
            $this->sessionIds[] = (int) $session->getKey();

            $this->expectException(DomainException::class);
            $this->expectExceptionMessage('not scoped to this customer');

            app(UpdateOutboundShippingParty::class)->handle($session->fresh(), [
                'trading_partner_id' => (int) $partnerA->getKey(),
                'outbound_connection_id' => (int) $connectionForB->getKey(),
            ]);
        } finally {
            $this->cleanup();
            tenancy()->end();
        }
    }

    #[Test]
    public function update_outbound_shipping_party_accepts_global_connection_for_any_partner(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $partner = $this->createPartner('Global route partner');

            $globalConnection = OutboundConnection::query()->create([
                'name' => 'Global HTTPS',
                'serialization_provider' => SerializationProvider::CustomHttps,
                'transport' => OutboundTransport::Https,
                'trading_partner_id' => null,
                'is_active' => true,
                'settings' => ['endpoint_url' => 'https://global.example/epcis'],
            ]);
            $this->connectionIds[] = (int) $globalConnection->getKey();

            $site = Site::query()->create([
                'name' => 'Ship site '.Str::random(6),
                'gln' => '0366159000010',
                'is_active' => true,
                'is_organization_facility' => true,
            ]);
            $this->siteIds[] = (int) $site->getKey();

            $session = app(OpenOutboundShippingSession::class)->handle((int) $site->getKey());
            $this->sessionIds[] = (int) $session->getKey();

            $updated = app(UpdateOutboundShippingParty::class)->handle($session->fresh(), [
                'trading_partner_id' => (int) $partner->getKey(),
                'outbound_connection_id' => (int) $globalConnection->getKey(),
            ]);

            $this->assertSame((int) $partner->getKey(), (int) $updated->trading_partner_id);
            $this->assertSame((int) $globalConnection->getKey(), (int) $updated->outbound_connection_id);
        } finally {
            $this->cleanup();
            tenancy()->end();
        }
    }

    #[Test]
    public function setting_default_clears_other_defaults_in_same_partner_scope(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $partner = $this->createPartner('Default sync partner');

            $existingDefault = OutboundConnection::query()->create([
                'name' => 'Existing default',
                'serialization_provider' => SerializationProvider::CustomHttps,
                'transport' => OutboundTransport::Https,
                'trading_partner_id' => $partner->getKey(),
                'is_active' => true,
                'is_default' => true,
                'settings' => ['endpoint_url' => 'https://existing.example/epcis'],
            ]);
            $this->connectionIds[] = (int) $existingDefault->getKey();

            $newDefault = OutboundConnection::query()->create([
                'name' => 'New default',
                'serialization_provider' => SerializationProvider::CustomHttps,
                'transport' => OutboundTransport::Https,
                'trading_partner_id' => $partner->getKey(),
                'is_active' => true,
                'is_default' => true,
                'settings' => ['endpoint_url' => 'https://new.example/epcis'],
            ]);
            $this->connectionIds[] = (int) $newDefault->getKey();

            OutboundConnectionDefaultSync::ensureSingleDefault($newDefault->fresh());

            $existingDefault->refresh();
            $newDefault->refresh();

            $this->assertFalse($existingDefault->is_default);
            $this->assertTrue($newDefault->is_default);
        } finally {
            $this->cleanup();
            tenancy()->end();
        }
    }

    private function createPartner(string $name): TradingPartner
    {
        $partner = TradingPartner::query()->create([
            'name' => $name.' '.uniqid(),
            'gln' => $this->uniqueGln(),
            'partner_type' => PartnerType::Pharmacy,
            'country_code' => 'US',
            'is_active' => true,
        ]);
        $this->partnerIds[] = (int) $partner->getKey();

        return $partner;
    }

    private function uniqueGln(): string
    {
        $base = str_pad((string) random_int(100000000000, 899999999999), 12, '0', STR_PAD_LEFT);

        return $base.$this->checkDigit($base);
    }

    private function checkDigit(string $base12): string
    {
        $sum = 0;

        for ($i = 0; $i < 12; $i++) {
            $digit = (int) $base12[$i];
            $sum += ($i % 2 === 0) ? $digit * 3 : $digit;
        }

        return (string) ((10 - ($sum % 10)) % 10);
    }

    private function initializeDemo2Tenant(): Tenant
    {
        $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);

        if ($tenant === null) {
            $tenant = Tenant::withoutEvents(fn () => Tenant::query()->create([
                'id' => self::DEMO2_TENANT_ID,
                'name' => 'Demo Wholesaler',
                'profile' => TenantProfile::DrugWholesaler,
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

    private function cleanup(): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        if ($this->sessionIds !== []) {
            \App\Models\Shipping\OutboundShippingSession::query()->whereIn('id', $this->sessionIds)->delete();
            $this->sessionIds = [];
        }

        if ($this->connectionIds !== []) {
            OutboundConnection::query()->whereIn('id', $this->connectionIds)->delete();
            $this->connectionIds = [];
        }

        if ($this->siteIds !== []) {
            Site::query()->whereIn('id', $this->siteIds)->delete();
            $this->siteIds = [];
        }

        if ($this->partnerIds !== []) {
            TradingPartner::query()->whereIn('id', $this->partnerIds)->delete();
            $this->partnerIds = [];
        }
    }
}
