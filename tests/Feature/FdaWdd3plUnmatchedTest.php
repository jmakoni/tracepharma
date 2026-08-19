<?php

namespace Tests\Feature;

use App\Actions\Fda\ImportFdaWdd3plStaging;
use App\Actions\Fda\UpsertFdaWdd3plUnmatched;
use App\Actions\MasterData\CreateHqSiteForTradingPartner;
use App\Enums\PartnerType;
use App\Enums\TenantProfile;
use App\Models\Fda\FdaOrganization;
use App\Models\Fda\FdaWdd3plStaging;
use App\Models\Fda\FdaWdd3plUnmatched;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Support\Fda\CompanyNameNormalizer;
use App\Support\Fda\FdaWdd3plDataset;
use App\Support\PartnerSlug;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FdaWdd3plUnmatchedTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private const ALPHA_NAME = 'Test WDD Unmatched Alpha';

    private const BETA_NAME = 'Test WDD Unmatched Beta';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $orgIds = [];

    /** @var list<int> */
    private array $tenantPartnerIds = [];

    /** @var list<int> */
    private array $tenantSiteIds = [];

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    #[Test]
    public function import_upserts_unmatched_facilities_for_triage(): void
    {
        $this->createFixtureOrganizations();

        $path = app(FdaWdd3plDataset::class)->resolvePath(
            base_path('tests/fixtures/fda/wdd_3pl_sample.txt'),
            false
        );

        app(ImportFdaWdd3plStaging::class)->handle($path);

        $unmatched = FdaWdd3plUnmatched::query()
            ->where('facility_name', 'Totally Unknown Facility Co')
            ->first();

        $this->assertNotNull($unmatched);
        $this->assertSame(1, $unmatched->row_count);
        $this->assertSame(PartnerSlug::from('Totally Unknown Facility Co'), $unmatched->slug_attempt);
        $this->assertNull($unmatched->resolved_at);
        $this->assertNull($unmatched->fda_organization_id);
        $this->assertNotNull($unmatched->last_seen_at);

        $firstSeen = $unmatched->last_seen_at;

        Carbon::setTestNow(now()->addHour());
        app(ImportFdaWdd3plStaging::class)->handle($path);

        $unmatched->refresh();
        $this->assertSame(1, $unmatched->row_count);
        $this->assertTrue($unmatched->last_seen_at->gt($firstSeen));
        $this->assertNull($unmatched->resolved_at);

        Carbon::setTestNow();
    }

    #[Test]
    public function resolved_unmatched_rows_keep_resolution_on_reimport(): void
    {
        $org = $this->createOrganization('Resolved Unknown Partner', PartnerType::Wholesaler);
        $resolvedAt = now()->subDay();

        FdaWdd3plUnmatched::query()->create([
            'facility_name' => 'Totally Unknown Facility Co',
            'slug_attempt' => PartnerSlug::from('Totally Unknown Facility Co'),
            'row_count' => 1,
            'last_seen_at' => now()->subWeek(),
            'resolved_at' => $resolvedAt,
            'fda_organization_id' => $org->id,
        ]);

        app(UpsertFdaWdd3plUnmatched::class)->handle([
            'Totally Unknown Facility Co' => 3,
        ]);

        $unmatched = FdaWdd3plUnmatched::query()
            ->where('facility_name', 'Totally Unknown Facility Co')
            ->first();

        $this->assertNotNull($unmatched);
        $this->assertSame(3, $unmatched->row_count);
        $this->assertSame($org->id, $unmatched->fda_organization_id);
        $this->assertSame(
            $resolvedAt->toDateTimeString(),
            $unmatched->resolved_at?->toDateTimeString(),
        );
    }

    #[Test]
    public function creating_tenant_partner_from_unmatched_sets_resolved_at(): void
    {
        $this->initializeDemo2Tenant();

        $unmatched = FdaWdd3plUnmatched::query()->create([
            'facility_name' => 'New Partner From Unmatched',
            'slug_attempt' => PartnerSlug::from('New Partner From Unmatched'),
            'row_count' => 2,
            'last_seen_at' => now(),
        ]);

        $org = $this->createOrganization($unmatched->facility_name, PartnerType::Wholesaler);

        $partner = TradingPartner::query()->create([
            'fda_organization_id' => $org->id,
            'name' => $unmatched->facility_name,
            'gln' => fake()->unique()->numerify('#############'),
            'partner_type' => PartnerType::Wholesaler,
            'country_code' => 'US',
            'is_active' => true,
        ]);
        $this->tenantPartnerIds[] = $partner->id;

        $site = app(CreateHqSiteForTradingPartner::class)->handle($partner);
        if ($site !== null) {
            $this->tenantSiteIds[] = $site->id;
        }

        $unmatched->update([
            'fda_organization_id' => $org->id,
            'resolved_at' => now(),
        ]);

        $unmatched->refresh();

        $this->assertNotNull($unmatched->resolved_at);
        $this->assertSame($org->id, $unmatched->fda_organization_id);
    }

    #[Test]
    public function staging_scopes_filter_unpromoted_and_missing_promote_fields(): void
    {
        $org = $this->createOrganization(self::ALPHA_NAME, PartnerType::Wholesaler);

        FdaWdd3plStaging::query()->insert([
            [
                'fda_organization_id' => $org->id,
                'facility_name' => 'Unpromoted Complete',
                'facility_type' => 'wdd',
                'license_number' => 'LIC-001',
                'license_state' => 'TX',
                'reporting_year' => 2026,
            ],
            [
                'fda_organization_id' => $org->id,
                'facility_name' => 'Promoted Complete',
                'facility_type' => '3pl',
                'license_number' => 'LIC-002',
                'license_state' => 'TX',
                'reporting_year' => 2026,
            ],
            [
                'fda_organization_id' => $org->id,
                'facility_name' => 'Missing License',
                'facility_type' => 'wdd',
                'license_number' => null,
                'license_state' => 'TX',
                'reporting_year' => 2026,
            ],
            [
                'fda_organization_id' => $org->id,
                'facility_name' => 'Invalid Facility Type',
                'facility_type' => 'invalid',
                'license_number' => 'LIC-003',
                'license_state' => 'TX',
                'reporting_year' => 2026,
            ],
        ]);

        $this->assertSame(4, FdaWdd3plStaging::query()->unpromoted()->count());
        $this->assertSame(2, FdaWdd3plStaging::query()->missingPromoteFields()->count());

        $missingNames = FdaWdd3plStaging::query()->missingPromoteFields()->pluck('facility_name')->all();
        $this->assertContains('Missing License', $missingNames);
        $this->assertContains('Invalid Facility Type', $missingNames);
        $this->assertNotContains('Promoted Complete', $missingNames);
    }

    private function createFixtureOrganizations(): void
    {
        foreach ([self::ALPHA_NAME, self::BETA_NAME] as $name) {
            $this->createOrganization($name, PartnerType::Logistics3pl);
        }
    }

    private function createOrganization(string $name, PartnerType $type): FdaOrganization
    {
        $org = FdaOrganization::query()->create([
            'original_name' => $name,
            'canonical_name' => CompanyNameNormalizer::canonical($name),
            'name' => $name,
            'partner_type' => $type,
            'country_code' => 'US',
            'is_active' => true,
        ]);
        $this->orgIds[] = $org->id;

        return $org;
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
        if (tenancy()->initialized) {
            if ($this->tenantSiteIds !== []) {
                Site::query()->whereIn('id', $this->tenantSiteIds)->delete();
            }
            if ($this->tenantPartnerIds !== []) {
                TradingPartner::query()->whereIn('id', $this->tenantPartnerIds)->delete();
            }
            tenancy()->end();
        }

        FdaWdd3plStaging::query()->truncate();
        FdaWdd3plUnmatched::query()->truncate();

        if ($this->orgIds !== []) {
            FdaOrganization::query()->whereIn('id', $this->orgIds)->delete();
            $this->orgIds = [];
        }

        $this->tenantPartnerIds = [];
        $this->tenantSiteIds = [];
    }
}
