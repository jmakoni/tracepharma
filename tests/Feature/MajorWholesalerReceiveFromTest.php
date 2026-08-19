<?php

namespace Tests\Feature;

use App\Actions\MasterData\EnsureOrganizationPartnerFromFda;
use App\Enums\PartnerType;
use App\Enums\TenantProfile;
use App\Filament\App\Resources\FdaProducts\Actions\AddFdaProductPackagesAction;
use App\Models\Fda\FdaOrganization;
use App\Models\Fda\FdaProduct;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Support\MasterData\MajorWholesalers;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

class MajorWholesalerReceiveFromTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $orgIds = [];

    /** @var list<int> */
    private array $fdaProductIds = [];

    /** @var list<int> */
    private array $tenantPartnerIds = [];

    /** @var list<int> */
    private array $deactivatedPartnerIds = [];

    /** @var list<int> */
    private array $preexistingMajorIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Seeding the majors is what these tests are about, but the rows outlive the test:
        // a later McKesson import then meets a partner already holding the slug.
        $this->preexistingMajorIds = MajorWholesalers::fdaOrganizations()
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    #[Test]
    public function receive_from_options_include_sentinels_when_no_major_authorized(): void
    {
        $this->ensureFdaMajorOrganizations();

        $fda = $this->createFdaProductWithoutLabelerPartner();

        $this->initializeDemo2Tenant();

        try {
            $activeIds = TradingPartner::query()
                ->where('is_active', true)
                ->pluck('id')
                ->all();

            TradingPartner::query()
                ->whereIn('id', $activeIds)
                ->update(['is_active' => false]);

            $this->deactivatedPartnerIds = $activeIds;

            $options = $this->invokeReceiveFromPartnerOptions($fda);

            $sentinelKeys = array_filter(
                array_keys($options),
                fn (mixed $key): bool => MajorWholesalers::isSentinel($key),
            );

            $this->assertCount(6, $sentinelKeys);

            foreach ($sentinelKeys as $key) {
                $this->assertStringContainsString('not set up', $options[$key]);
            }
        } finally {
            $this->cleanupIntegrationFixtures();
        }
    }

    #[Test]
    public function receive_from_options_hide_sentinels_when_any_major_authorized(): void
    {
        $orgs = $this->ensureFdaMajorOrganizations();
        $mckesson = $orgs->first();
        $this->assertNotNull($mckesson);

        $fda = $this->createFdaProductWithoutLabelerPartner();

        $this->initializeDemo2Tenant();

        try {
            $activeIds = TradingPartner::query()
                ->where('is_active', true)
                ->pluck('id')
                ->all();

            TradingPartner::query()
                ->whereIn('id', $activeIds)
                ->update(['is_active' => false]);

            $this->deactivatedPartnerIds = $activeIds;

            $partner = TradingPartner::query()->create([
                'name' => 'McKesson Tenant '.uniqid(),
                'gln' => fake()->unique()->numerify('#############'),
                'partner_type' => PartnerType::Wholesaler,
                'country_code' => 'US',
                'is_active' => true,
                'fda_organization_id' => $mckesson->id,
            ]);
            $this->tenantPartnerIds = [$partner->id];

            $options = $this->invokeReceiveFromPartnerOptions($fda);

            $sentinelKeys = array_filter(
                array_keys($options),
                fn (mixed $key): bool => MajorWholesalers::isSentinel($key),
            );

            $this->assertSame([], array_values($sentinelKeys));
            $this->assertArrayHasKey((string) $partner->id, $options);
        } finally {
            $this->cleanupIntegrationFixtures();
        }
    }

    #[Test]
    public function ensure_organization_partner_from_fda_creates_tenant_partner(): void
    {
        $orgs = $this->ensureFdaMajorOrganizations();
        $cardinal = $orgs->first(
            fn (FdaOrganization $org): bool => $org->gln === '0300000000002',
        );
        $this->assertNotNull($cardinal);

        $this->initializeDemo2Tenant();

        try {
            if (filled($cardinal->gln)) {
                TradingPartner::query()
                    ->where('gln', $cardinal->gln)
                    ->where(function ($query) use ($cardinal): void {
                        $query->whereNull('fda_organization_id')
                            ->orWhere('fda_organization_id', '!=', $cardinal->id);
                    })
                    ->each(function (TradingPartner $leftover): void {
                        $leftover->sites()->each(function ($site): void {
                            $site->atpLicenses()->delete();
                            $site->delete();
                        });
                        $leftover->delete();
                    });
            }

            $this->assertFalse(
                TradingPartner::query()
                    ->where('fda_organization_id', $cardinal->id)
                    ->exists(),
            );

            $partner = app(EnsureOrganizationPartnerFromFda::class)->handle($cardinal, PartnerType::Wholesaler);
            $this->assertNotNull($partner);
            $this->tenantPartnerIds = [$partner->id];

            $this->assertSame($cardinal->id, $partner->fda_organization_id);
            $this->assertSame(PartnerType::Wholesaler, $partner->partner_type);
            $this->assertTrue($partner->is_active);
        } finally {
            $this->cleanupIntegrationFixtures();
        }
    }

    #[Test]
    public function can_show_product_form_when_catalog_majors_exist_without_active_partners(): void
    {
        $this->ensureFdaMajorOrganizations();

        $this->initializeDemo2Tenant();

        try {
            $activeIds = TradingPartner::query()
                ->where('is_active', true)
                ->pluck('id')
                ->all();

            TradingPartner::query()
                ->whereIn('id', $activeIds)
                ->update(['is_active' => false]);

            $this->deactivatedPartnerIds = $activeIds;

            $canShow = new ReflectionMethod(AddFdaProductPackagesAction::class, 'canShowProductForm');
            $canShow->setAccessible(true);

            $this->assertTrue($canShow->invoke(null));
        } finally {
            $this->cleanupIntegrationFixtures();
        }
    }

    /**
     * @return \Illuminate\Support\Collection<int, FdaOrganization>
     */
    private function ensureFdaMajorOrganizations()
    {
        $orgs = collect();

        foreach (MajorWholesalers::definitions() as $definition) {
            $org = FdaOrganization::query()->firstOrCreate(
                ['gln' => $definition['gln']],
                [
                    'original_name' => $definition['name'],
                    'canonical_name' => strtoupper($definition['name']),
                    'name' => $definition['name'],
                    'partner_type' => PartnerType::Wholesaler,
                    'country_code' => 'US',
                    'is_active' => true,
                ],
            );

            if (! in_array($org->id, $this->preexistingMajorIds, true)) {
                $this->orgIds[] = $org->id;
            }

            $orgs->push($org);
        }

        return $orgs;
    }

    private function createFdaProductWithoutLabelerPartner(): FdaProduct
    {
        $fda = FdaProduct::query()->create([
            'product_id' => 'TEST-MAJOR-WHOLESALER-'.uniqid(),
            'product_ndc' => fake()->unique()->numerify('#####-###'),
            'brand_name' => 'Major Wholesaler Test Product',
            'product_type' => FdaProduct::PRODUCT_TYPE_HUMAN_PRESCRIPTION,
            'finished' => true,
        ]);
        $this->fdaProductIds[] = $fda->id;

        return $fda;
    }

    /**
     * @return array<int|string, string>
     */
    private function invokeReceiveFromPartnerOptions(FdaProduct $fda): array
    {
        $method = new ReflectionMethod(AddFdaProductPackagesAction::class, 'receiveFromPartnerOptions');
        $method->setAccessible(true);

        /** @var array<int|string, string> $options */
        $options = $method->invoke(null, $fda);

        return $options;
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

    private function cleanupIntegrationFixtures(): void
    {
        if (tenancy()->initialized) {
            if ($this->tenantPartnerIds !== []) {
                foreach ($this->tenantPartnerIds as $partnerId) {
                    $partner = TradingPartner::query()->find($partnerId);
                    if ($partner === null) {
                        continue;
                    }

                    $partner->sites()->each(function ($site): void {
                        $site->atpLicenses()->delete();
                        $site->delete();
                    });
                    $partner->products()->detach();
                    $partner->delete();
                }
            }

            if ($this->deactivatedPartnerIds !== []) {
                TradingPartner::query()
                    ->whereIn('id', $this->deactivatedPartnerIds)
                    ->update(['is_active' => true]);
            }

            tenancy()->end();
        }

        if ($this->fdaProductIds !== []) {
            FdaProduct::query()->whereIn('id', $this->fdaProductIds)->delete();
        }

        if ($this->orgIds !== []) {
            FdaOrganization::query()->whereIn('id', $this->orgIds)->delete();
        }

        $this->orgIds = [];
        $this->fdaProductIds = [];
        $this->tenantPartnerIds = [];
        $this->deactivatedPartnerIds = [];
    }
}
