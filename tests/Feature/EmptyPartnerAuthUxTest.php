<?php

namespace Tests\Feature;

use App\Actions\MasterData\AddFdaPackagesToTradingPartner;
use App\Enums\AuthorizationStatus;
use App\Enums\PartnerType;
use App\Enums\TenantProfile;
use App\Filament\App\Resources\FdaProducts\Actions\AddFdaProductPackagesAction;
use App\Models\Fda\FdaOrganization;
use App\Models\Fda\FdaProduct;
use App\Models\Fda\FdaProductPackaging;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Support\Gs1\Gtin;
use App\Support\MasterData\MajorWholesalers;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

class EmptyPartnerAuthUxTest extends TestCase
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
    private array $packagingIds = [];

    /** @var list<int> */
    private array $tenantPartnerIds = [];

    /** @var list<int> */
    private array $tenantProductIds = [];

    /** @var list<int> */
    private array $deactivatedPartnerIds = [];

    #[Test]
    public function add_fda_product_action_stays_enabled_with_product_form_when_no_active_partners_but_catalog_majors_exist(): void
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

            $action = AddFdaProductPackagesAction::make();

            $this->assertFalse($action->isDisabled());

            $hasActive = new ReflectionMethod(AddFdaProductPackagesAction::class, 'hasActiveTradingPartners');
            $hasActive->setAccessible(true);
            $this->assertFalse($hasActive->invoke(null));

            $canShow = new ReflectionMethod(AddFdaProductPackagesAction::class, 'canShowProductForm');
            $canShow->setAccessible(true);
            $this->assertTrue($canShow->invoke(null));

            $property = new ReflectionProperty($action, 'schema');
            $property->setAccessible(true);

            /** @var \Closure(?FdaProduct): array<int, mixed> $formClosure */
            $formClosure = $property->getValue($action);
            $components = $formClosure(null);

            $placeholder = collect($components)->first(
                fn ($component) => $component instanceof Placeholder && $component->getName() === 'empty_partners',
            );

            $this->assertNull($placeholder);

            $receiveFromSelect = collect($components)->first(
                fn ($component) => $component instanceof Select && $component->getName() === 'trading_partner_id',
            );

            $this->assertInstanceOf(Select::class, $receiveFromSelect);
        } finally {
            $this->cleanupIntegrationFixtures();
        }
    }

    #[Test]
    public function receive_from_partner_options_includes_missing_manufacturer_option_when_labeler_not_set_up(): void
    {
        $labeler = FdaOrganization::query()->create([
            'original_name' => 'SSOR CUT2 Missing Labeler Corp',
            'canonical_name' => 'SSOR CUT2 MISSING LABELER CORP',
            'name' => 'SSOR CUT2 Missing Labeler Corp',
            'partner_type' => PartnerType::Manufacturer,
            'is_active' => true,
        ]);
        $this->orgIds[] = $labeler->id;

        $fda = FdaProduct::query()->create([
            'product_id' => 'SSOR-CUT2-MISSING-LABELER-'.uniqid(),
            'product_ndc' => fake()->unique()->numerify('#####-###'),
            'brand_name' => 'SSOR CUT2 Missing Labeler Product',
            'product_type' => FdaProduct::PRODUCT_TYPE_HUMAN_PRESCRIPTION,
            'fda_organization_id' => $labeler->id,
            'finished' => true,
        ]);
        $this->fdaProductIds[] = $fda->id;

        $this->initializeDemo2Tenant();

        try {
            $wholesaler = TradingPartner::query()->create([
                'name' => 'SSOR CUT2 Tenant Wholesaler Only '.uniqid(),
                'gln' => fake()->unique()->numerify('#############'),
                'partner_type' => PartnerType::Wholesaler,
                'country_code' => 'US',
                'is_active' => true,
            ]);
            $this->tenantPartnerIds = [$wholesaler->id];

            $method = new ReflectionMethod(AddFdaProductPackagesAction::class, 'receiveFromPartnerOptions');
            $method->setAccessible(true);

            /** @var array<string, string> $options */
            $options = $method->invoke(null, $fda);

            $this->assertArrayHasKey('__manufacturer_not_set_up__', $options);
            $this->assertStringContainsString('not set up', $options['__manufacturer_not_set_up__']);
            $this->assertStringContainsString('Missing Labeler Corp', $options['__manufacturer_not_set_up__']);
            $this->assertArrayHasKey((string) $wholesaler->id, $options);
        } finally {
            $this->cleanupIntegrationFixtures();
        }
    }

    #[Test]
    public function has_active_trading_partners_returns_false_when_all_inactive(): void
    {
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

            $method = new ReflectionMethod(AddFdaProductPackagesAction::class, 'hasActiveTradingPartners');
            $method->setAccessible(true);

            $this->assertFalse($method->invoke(null));
        } finally {
            $this->cleanupIntegrationFixtures();
        }
    }

    #[Test]
    public function auto_add_manufacturer_false_leaves_pending_manufacturer_authorization(): void
    {
        [$packaging] = $this->createRxPackaging('SSOR-CUT2-EMPTY-PENDING');

        $this->initializeDemo2Tenant();

        try {
            $wholesaler = TradingPartner::query()->create([
                'name' => 'SSOR CUT2 Tenant Wholesaler Pending '.uniqid(),
                'gln' => fake()->unique()->numerify('#############'),
                'partner_type' => PartnerType::Wholesaler,
                'country_code' => 'US',
                'is_active' => true,
            ]);
            $this->tenantPartnerIds = [$wholesaler->id];

            $result = app(AddFdaPackagesToTradingPartner::class)->handle(
                $wholesaler,
                [$packaging->id],
                autoAddManufacturer: false,
            );

            $this->assertSame([
                'added' => 1,
                'attached' => 0,
                'skipped' => 0,
                'manufacturer_pending' => 1,
                'manufacturer_added' => 0,
            ], $result);

            $product = Product::query()->where('fda_product_packaging_id', $packaging->id)->firstOrFail();
            $this->tenantProductIds = [$product->id];
            $this->assertNull($product->trading_partner_id);

            $pivot = $wholesaler->products()->where('products.id', $product->id)->first()?->pivot;
            $this->assertSame(AuthorizationStatus::PendingManufacturer->value, $pivot?->authorization_status);
        } finally {
            $this->cleanupIntegrationFixtures();
        }
    }

    #[Test]
    public function auto_add_manufacturer_true_creates_manufacturer_partner(): void
    {
        [$packaging, , $org] = $this->createRxPackaging('SSOR-CUT2-EMPTY-AUTO');

        $this->initializeDemo2Tenant();

        try {
            $wholesaler = TradingPartner::query()->create([
                'name' => 'SSOR CUT2 Tenant Wholesaler Auto '.uniqid(),
                'gln' => fake()->unique()->numerify('#############'),
                'partner_type' => PartnerType::Wholesaler,
                'country_code' => 'US',
                'is_active' => true,
            ]);
            $this->tenantPartnerIds = [$wholesaler->id];

            $result = app(AddFdaPackagesToTradingPartner::class)->handle(
                $wholesaler,
                [$packaging->id],
                autoAddManufacturer: true,
            );

            $this->assertSame([
                'added' => 1,
                'attached' => 0,
                'skipped' => 0,
                'manufacturer_pending' => 0,
                'manufacturer_added' => 1,
            ], $result);

            $product = Product::query()->where('fda_product_packaging_id', $packaging->id)->firstOrFail();
            $this->tenantProductIds = [$product->id];

            $manufacturer = TradingPartner::query()->findOrFail($product->trading_partner_id);
            $this->tenantPartnerIds[] = $manufacturer->id;
            $this->assertSame($org->id, $manufacturer->fda_organization_id);
            $this->assertSame(PartnerType::Manufacturer, $manufacturer->partner_type);
        } finally {
            $this->cleanupIntegrationFixtures();
        }
    }

    private function ensureFdaMajorOrganizations(): void
    {
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

            if ($org->wasRecentlyCreated) {
                $this->orgIds[] = $org->id;
            }
        }
    }

    /**
     * @return array{0: FdaProductPackaging, 1: FdaProduct, 2: FdaOrganization}
     */
    private function createRxPackaging(string $suffix): array
    {
        $org = FdaOrganization::query()->create([
            'original_name' => 'SSOR CUT2 '.$suffix,
            'canonical_name' => strtoupper('SSOR CUT2 '.$suffix),
            'name' => 'SSOR CUT2 '.$suffix,
            'partner_type' => PartnerType::Manufacturer,
            'is_active' => true,
        ]);
        $this->orgIds[] = $org->id;

        $packageNdc = fake()->unique()->numerify('#####-###-##');

        $fda = FdaProduct::query()->create([
            'product_id' => 'SSOR-CUT2-'.$suffix.'-'.uniqid(),
            'product_ndc' => substr($packageNdc, 0, 9),
            'brand_name' => 'Rx '.$suffix,
            'product_type' => FdaProduct::PRODUCT_TYPE_HUMAN_PRESCRIPTION,
            'fda_organization_id' => $org->id,
            'finished' => true,
            'is_active' => true,
        ]);
        $this->fdaProductIds[] = $fda->id;

        $packaging = FdaProductPackaging::query()->create([
            'fda_product_id' => $fda->id,
            'package_ndc' => $packageNdc,
            'gtin' => Gtin::fromPackageNdc(str_replace('-', '', $packageNdc)) ?? fake()->unique()->numerify('##############'),
            'ndc11' => preg_replace('/\D+/', '', $packageNdc),
            'is_active' => true,
        ]);
        $this->packagingIds[] = $packaging->id;

        return [$packaging, $fda, $org];
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
                    TradingPartner::query()->find($partnerId)?->products()->detach();
                }
            }

            if ($this->tenantProductIds !== []) {
                Product::query()->whereIn('id', $this->tenantProductIds)->delete();
            }

            if ($this->tenantPartnerIds !== []) {
                TradingPartner::query()->whereIn('id', $this->tenantPartnerIds)->delete();
            }

            if ($this->deactivatedPartnerIds !== []) {
                TradingPartner::query()
                    ->whereIn('id', $this->deactivatedPartnerIds)
                    ->update(['is_active' => true]);
            }

            tenancy()->end();
        }

        if ($this->packagingIds !== []) {
            FdaProductPackaging::query()->whereIn('id', $this->packagingIds)->delete();
        }

        if ($this->fdaProductIds !== []) {
            FdaProduct::query()->whereIn('id', $this->fdaProductIds)->delete();
        }

        if ($this->orgIds !== []) {
            FdaOrganization::query()->whereIn('id', $this->orgIds)->delete();
        }

        $this->orgIds = [];
        $this->fdaProductIds = [];
        $this->packagingIds = [];
        $this->tenantPartnerIds = [];
        $this->tenantProductIds = [];
        $this->deactivatedPartnerIds = [];
    }
}
