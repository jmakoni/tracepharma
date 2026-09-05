<?php

namespace Tests\Unit\Support\Epcis;

use App\Actions\Epcis\ResolveGlnToMasterData;
use App\Enums\TenantProfile;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Support\Gs1\Gtin;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ResolveGlnToMasterDataDeaTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $tenantSiteIds = [];

    /** @var list<int> */
    private array $tenantPartnerIds = [];

    #[Test]
    public function dea_prefixed_token_resolves_to_site_gln(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $site = Site::factory()->create([
                'gln' => '0614141000005',
                'dea_number' => 'AB1234567',
                'is_organization_facility' => false,
            ]);
            $this->tenantSiteIds[] = (int) $site->getKey();

            $resolved = app(ResolveGlnToMasterData::class)->handle('DEA:AB1234567');

            $this->assertSame((int) $site->getKey(), $resolved['site_id']);
            $this->assertSame('0614141000005', $resolved['gln']);
            $this->assertStringNotContainsString('DEA', $resolved['gln']);
            $this->assertStringNotContainsString('AB1234567', $resolved['gln']);
        } finally {
            $this->cleanupFixtures();
        }
    }

    #[Test]
    public function thirteen_digit_valid_gln_does_not_use_dea_ladder(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $body12 = fake()->unique()->numerify('############');
            $gln = $body12.Gtin::checkDigit($body12);

            $site = Site::factory()->create([
                'gln' => $gln,
                'dea_number' => 'XY9999999',
                'is_organization_facility' => false,
            ]);
            $this->tenantSiteIds[] = (int) $site->getKey();

            Site::factory()->create([
                'gln' => fake()->unique()->numerify('#############'),
                'dea_number' => 'AB1234567',
                'is_organization_facility' => false,
            ]);
            $this->tenantSiteIds[] = (int) Site::query()->orderByDesc('id')->value('id');

            $resolved = app(ResolveGlnToMasterData::class)->handle($gln);

            $this->assertSame((int) $site->getKey(), $resolved['site_id']);
            $this->assertSame($gln, $resolved['gln']);
        } finally {
            $this->cleanupFixtures();
        }
    }

    #[Test]
    public function dea_prefixed_token_resolves_to_trading_partner_gln_when_no_site_match(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $partner = TradingPartner::factory()->create([
                'gln' => '0301160000016',
                'dea_number' => 'AB1234567',
            ]);
            $this->tenantPartnerIds[] = (int) $partner->getKey();

            $resolved = app(ResolveGlnToMasterData::class)->handle('DEA:AB1234567');

            $this->assertNull($resolved['site_id']);
            $this->assertSame((int) $partner->getKey(), $resolved['trading_partner_id']);
            $this->assertSame('0301160000016', $resolved['gln']);
        } finally {
            $this->cleanupFixtures();
        }
    }

    #[Test]
    public function hyphenated_stored_dea_number_matches_prefixed_token(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $site = Site::factory()->create([
                'gln' => '0614141000005',
                'dea_number' => 'AB-1234567',
                'is_organization_facility' => false,
            ]);
            $this->tenantSiteIds[] = (int) $site->getKey();

            $resolved = app(ResolveGlnToMasterData::class)->handle('dea/ab1234567');

            $this->assertSame((int) $site->getKey(), $resolved['site_id']);
            $this->assertSame('0614141000005', $resolved['gln']);
        } finally {
            $this->cleanupFixtures();
        }
    }

    #[Test]
    public function thirteen_digit_invalid_check_digit_resolves_via_dea_when_gln_unmatched(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $body12 = '123456789012';
            $invalidGln = $body12.(string) (((int) Gtin::checkDigit($body12) + 1) % 10);

            $site = Site::factory()->create([
                'gln' => '0614141000005',
                'dea_number' => $invalidGln,
                'is_organization_facility' => false,
            ]);
            $this->tenantSiteIds[] = (int) $site->getKey();

            $resolved = app(ResolveGlnToMasterData::class)->handle($invalidGln);

            $this->assertSame((int) $site->getKey(), $resolved['site_id']);
            $this->assertSame('0614141000005', $resolved['gln']);
            $this->assertNotSame($invalidGln, $resolved['gln']);
        } finally {
            $this->cleanupFixtures();
        }
    }

    #[Test]
    public function unmatched_dea_token_never_returns_dea_in_gln_field(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $resolved = app(ResolveGlnToMasterData::class)->handle('DEA:ZZ9999999');

            $this->assertNull($resolved['site_id']);
            $this->assertNull($resolved['trading_partner_id']);
            $this->assertNotSame('ZZ9999999', $resolved['gln']);
            $this->assertStringNotContainsString('DEA', (string) $resolved['gln']);
        } finally {
            $this->cleanupFixtures();
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

    private function cleanupFixtures(): void
    {
        if (tenancy()->initialized) {
            if ($this->tenantSiteIds !== []) {
                Site::query()->whereIn('id', $this->tenantSiteIds)->delete();
            }

            if ($this->tenantPartnerIds !== []) {
                TradingPartner::query()->whereIn('id', $this->tenantPartnerIds)->delete();
            }

            tenancy()->end();
        }

        $this->tenantSiteIds = [];
        $this->tenantPartnerIds = [];
    }
}
