<?php

namespace Tests\Unit\Support\Gs1;

use App\Enums\PartnerType;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Support\Gs1\AssertOrganizationSsccIdentity;
use App\Support\TenantSettings;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AssertOrganizationSsccIdentityTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    /** @var list<int> */
    private array $partnerIds = [];

    /** @var list<int> */
    private array $siteIds = [];

    #[Test]
    public function it_rejects_organization_gln_that_matches_a_trading_partner(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            tenancy()->initialize($tenant);

            $partner = TradingPartner::query()->create([
                'name' => 'Collision Partner '.Str::random(6),
                'gln' => '0388881000006',
                'partner_type' => PartnerType::Manufacturer,
                'is_active' => true,
            ]);
            $this->partnerIds[] = (int) $partner->id;

            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('Organization GLN matches trading partner');

            app(AssertOrganizationSsccIdentity::class)->handle('0388881000006', '0388881');
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function it_allows_company_prefix_shared_with_trading_partner_gln_when_assignment_enabled(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            tenancy()->initialize($tenant);

            TenantSettings::forTenant($tenant)
                ->setAllowAssignPartnerGlnsFromPrefix(true);
            $tenant->save();

            $partner = TradingPartner::query()->create([
                'name' => 'Prefix Partner Allowed '.Str::random(6),
                'gln' => '0377771000004',
                'partner_type' => PartnerType::Manufacturer,
                'is_active' => true,
            ]);
            $this->partnerIds[] = (int) $partner->id;

            app(AssertOrganizationSsccIdentity::class)->handle('0377771999995', '0377771');

            $this->assertTrue(true);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function it_rejects_company_prefix_shared_with_trading_partner_gln(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            tenancy()->initialize($tenant);

            TenantSettings::forTenant($tenant)
                ->setAllowAssignPartnerGlnsFromPrefix(false);
            $tenant->save();

            $partner = TradingPartner::query()->create([
                'name' => 'Prefix Partner '.Str::random(6),
                'gln' => '0377771000004',
                'partner_type' => PartnerType::Manufacturer,
                'is_active' => true,
            ]);
            $this->partnerIds[] = (int) $partner->id;

            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('Organization company prefix');

            // Tenant GLN differs, but GCP matches partner GLN body start.
            app(AssertOrganizationSsccIdentity::class)->handle('0377771999995', '0377771');
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function it_rejects_organization_gln_that_matches_an_active_partner_site(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            tenancy()->initialize($tenant);

            $partner = TradingPartner::query()->create([
                'name' => 'Site Collision Partner '.Str::random(6),
                'gln' => '0366661000001',
                'partner_type' => PartnerType::Manufacturer,
                'is_active' => true,
            ]);
            $this->partnerIds[] = (int) $partner->id;

            // The partner's dock is just as much theirs as their corporate GLN.
            $site = Site::query()->create([
                'trading_partner_id' => $partner->getKey(),
                'name' => 'Site Collision Dock '.Str::random(6),
                'gln' => '0366662000008',
                'is_active' => true,
                'is_organization_facility' => false,
                'country_code' => 'US',
            ]);
            $this->siteIds[] = (int) $site->id;

            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('Organization GLN matches trading partner');

            app(AssertOrganizationSsccIdentity::class)->handle('0366662000008', null);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function it_ignores_sites_of_inactive_partners(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            tenancy()->initialize($tenant);

            $partner = TradingPartner::query()->create([
                'name' => 'Inactive Site Partner '.Str::random(6),
                'gln' => '0355551000004',
                'partner_type' => PartnerType::Manufacturer,
                'is_active' => false,
            ]);
            $this->partnerIds[] = (int) $partner->id;

            $site = Site::query()->create([
                'trading_partner_id' => $partner->getKey(),
                'name' => 'Inactive Site Dock '.Str::random(6),
                'gln' => '0355552000001',
                'is_active' => true,
                'is_organization_facility' => false,
                'country_code' => 'US',
            ]);
            $this->siteIds[] = (int) $site->id;

            app(AssertOrganizationSsccIdentity::class)->handle('0355552000001', null);

            $this->assertTrue(true);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function it_allows_tenant_owned_identity_that_does_not_match_partners(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            tenancy()->initialize($tenant);

            app(AssertOrganizationSsccIdentity::class)->handle('0399991000008', '0399991');
            TenantSettings::assertValidCompanyPrefix('0399991', '0399991000008');

            $this->assertTrue(true);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function it_lets_identity_through_when_the_tenant_database_does_not_exist_yet(): void
    {
        // A tenant created in the admin panel has no database until it is provisioned,
        // and initializing a tenancy against one only swaps connection config — the
        // absence surfaces on the first query. There are no partners to collide with,
        // and commissioning re-runs this check once the database is real.
        $id = (string) Str::uuid();
        $tenant = Tenant::withoutEvents(fn (): Tenant => Tenant::query()->create([
            'id' => $id,
            'name' => 'Unprovisioned '.$id,
            'status' => 'active',
            'tenancy_db_name' => 'tenant_missing_'.str_replace('-', '', $id),
        ]));

        try {
            app(AssertOrganizationSsccIdentity::class)->forTenant($tenant, '0366159000010', '036615');

            $this->assertFalse(tenancy()->initialized, 'The failed tenancy is not left open.');
        } finally {
            tenancy()->end();
            Tenant::withoutEvents(fn () => $tenant->delete());
        }
    }

    private function initializeDemo2Tenant(): Tenant
    {
        $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);

        if ($tenant === null) {
            $tenant = Tenant::withoutEvents(fn () => Tenant::query()->create([
                'id' => self::DEMO2_TENANT_ID,
                'name' => 'Demo Organization',
                'status' => 'active',
                'tenancy_db_name' => self::DEMO2_DATABASE,
            ]));
            $tenant->domains()->create(['domain' => self::DEMO2_DOMAIN]);
        }

        return $tenant;
    }

    private function cleanup(Tenant $tenant): void
    {
        if (tenancy()->initialized) {
            if ($this->siteIds !== []) {
                Site::query()->whereIn('id', $this->siteIds)->delete();
            }

            if ($this->partnerIds !== []) {
                TradingPartner::query()->whereIn('id', $this->partnerIds)->delete();
            }
        }

        $this->siteIds = [];
        $this->partnerIds = [];

        tenancy()->end();
    }
}
