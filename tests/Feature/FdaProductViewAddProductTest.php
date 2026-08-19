<?php

namespace Tests\Feature;

use App\Actions\MasterData\AddFdaPackagesToTradingPartner;
use App\Enums\PartnerType;
use App\Enums\TenantProfile;
use App\Filament\App\Resources\FdaProducts\Actions\AddFdaProductPackagesAction;
use App\Filament\App\Resources\FdaProducts\Pages\ListFdaProducts;
use App\Filament\App\Resources\FdaProducts\Pages\ViewFdaProduct;
use App\Filament\App\Resources\FdaProducts\Tables\FdaProductsTable;
use App\Models\Fda\FdaOrganization;
use App\Models\Fda\FdaProduct;
use App\Models\Fda\FdaProductPackaging;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Support\Gs1\Gtin;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use Tests\TestCase;

class FdaProductViewAddProductTest extends TestCase
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

    #[Test]
    public function view_fda_product_exposes_add_product_header_action(): void
    {
        $page = new ViewFdaProduct;
        $method = new \ReflectionMethod(ViewFdaProduct::class, 'getHeaderActions');
        $method->setAccessible(true);

        $actions = $method->invoke($page);

        $this->assertCount(1, $actions);
        $this->assertSame('addProduct', $actions[0]->getName());
        $this->assertSame('Add Product', $actions[0]->getLabel());
        $this->assertSame('Add product packages', $actions[0]->getModalHeading());
        $this->assertSame('Add products', $actions[0]->getModalSubmitActionLabel());
    }

    #[Test]
    public function fda_products_table_exposes_add_product_header_action_only(): void
    {
        $table = FdaProductsTable::configure(Table::make(new ListFdaProducts));

        $headerActions = $table->getHeaderActions();
        $this->assertCount(1, $headerActions);
        $this->assertSame('addProduct', $headerActions[0]->getName());
        $this->assertSame('Add Product', $headerActions[0]->getLabel());

        $recordActions = $table->getRecordActions();
        $this->assertCount(1, $recordActions);
        $this->assertInstanceOf(ActionGroup::class, $recordActions[0]);

        $rowActionNames = collect($recordActions[0]->getActions())
            ->map(fn ($action) => $action->getName())
            ->all();

        $this->assertSame(['view'], $rowActionNames);
    }

    #[Test]
    public function add_product_form_includes_fda_product_select_without_record(): void
    {
        $selects = $this->getFormSelects(null)
            ->keyBy(fn (Select $select) => $select->getName());

        $this->assertArrayHasKey('fda_product_id', $selects->all());
        $this->assertArrayHasKey('trading_partner_id', $selects->all());
        $this->assertArrayHasKey('packaging_ids', $selects->all());
        $this->assertTrue($selects->get('fda_product_id')->isSearchable());
        $this->assertSame(500, $selects->get('fda_product_id')->getSearchDebounce());
    }

    #[Test]
    public function add_product_form_omits_fda_product_select_when_record_present(): void
    {
        $fda = new FdaProduct([
            'product_id' => 'TEST-BOUND-FDA',
            'product_ndc' => '11111-222-33',
            'brand_name' => 'Bound Brand',
        ]);

        $selectNames = $this->getFormSelects($fda)
            ->map(fn (Select $select) => $select->getName())
            ->all();

        $this->assertSame(['trading_partner_id', 'packaging_ids'], $selectNames);
    }

    #[Test]
    public function add_product_flow_creates_tenant_product_via_wholesaler_partner(): void
    {
        [$packaging, $fda, $org] = $this->createRxPackaging('SSOR-CUT2-FDA-VIEW-ADD');

        $this->initializeDemo2Tenant();

        try {
            $manufacturer = TradingPartner::query()->create([
                'fda_organization_id' => $org->id,
                'name' => 'SSOR CUT2 Tenant Mfg FDA View '.uniqid(),
                'gln' => fake()->unique()->numerify('#############'),
                'partner_type' => PartnerType::Manufacturer,
                'country_code' => 'US',
                'is_active' => true,
            ]);

            $wholesaler = TradingPartner::query()->create([
                'name' => 'SSOR CUT2 Tenant Wholesaler FDA View '.uniqid(),
                'gln' => fake()->unique()->numerify('#############'),
                'partner_type' => PartnerType::Wholesaler,
                'country_code' => 'US',
                'is_active' => true,
            ]);
            $this->tenantPartnerIds = [$manufacturer->id, $wholesaler->id];

            $result = app(AddFdaPackagesToTradingPartner::class)->handle($wholesaler, [$packaging->id]);

            $this->assertSame([
                'added' => 1,
                'attached' => 0,
                'skipped' => 0,
                'manufacturer_pending' => 0,
                'manufacturer_added' => 0,
            ], $result);

            $product = Product::query()->where('fda_product_packaging_id', $packaging->id)->first();
            $this->assertNotNull($product);
            $this->tenantProductIds = [$product->id];

            $this->assertSame($fda->id, $product->fda_product_id);
            $this->assertSame($manufacturer->id, $product->trading_partner_id);
            $this->assertTrue($wholesaler->products()->where('products.id', $product->id)->exists());
        } finally {
            $this->cleanupIntegrationFixtures();
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

    /**
     * @return Collection<int, Select>
     */
    private function getFormSelects(?FdaProduct $record): Collection
    {
        $action = AddFdaProductPackagesAction::make();

        if ($record !== null) {
            $action->record($record);
        }

        $property = new ReflectionProperty($action, 'schema');
        $property->setAccessible(true);

        /** @var \Closure(?FdaProduct): array<int, mixed> $formClosure */
        $formClosure = $property->getValue($action);

        return $this->collectSelects($formClosure($record));
    }

    /**
     * @param  array<int, mixed>  $components
     * @return Collection<int, Select>
     */
    private function collectSelects(array $components): Collection
    {
        return collect($components)->flatMap(function ($component) {
            if ($component instanceof Select) {
                return [$component];
            }

            if (method_exists($component, 'getDefaultChildComponents')) {
                $children = $component->getDefaultChildComponents();

                return $this->collectSelects(is_array($children) ? $children : $children->getComponents());
            }

            return [];
        });
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
    }
}
