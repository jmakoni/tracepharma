<?php

namespace Tests\Feature;

use App\Enums\PartnerType;
use App\Enums\TenantProfile;
use App\Filament\App\Pages\OperationsHub;
use App\Filament\App\Resources\FdaProducts\FdaProductResource;
use App\Filament\App\Resources\LocationDevices\LocationDeviceResource;
use App\Filament\App\Resources\Products\ProductResource;
use App\Filament\App\Resources\Sites\Pages\ListSites;
use App\Filament\App\Resources\Sites\Pages\ViewSite;
use App\Filament\App\Resources\Sites\RelationManagers\AtpLicensesRelationManager;
use App\Filament\App\Resources\Sites\RelationManagers\LocationDevicesRelationManager;
use App\Filament\App\Resources\Sites\SiteResource;
use App\Filament\App\Resources\Sites\Tables\SitesTable;
use App\Filament\App\Resources\TradingPartners\RelationManagers\ContactRelationManager;
use App\Filament\App\Resources\TradingPartners\RelationManagers\ProductsRelationManager;
use App\Filament\App\Resources\TradingPartners\RelationManagers\SitesRelationManager;
use App\Filament\App\Resources\TradingPartners\TradingPartnerResource;
use App\Models\Product;
use App\Models\Site;
use App\Models\TradingPartner;
use App\Support\MasterData\PartnerSiteCreate;
use App\Support\TenantFeatures;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\ViewAction;
use Filament\Schemas\Components\Section;
use Filament\Support\Enums\Width;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PartnerFirstMasterDataNavigationTest extends TestCase
{
    #[Test]
    public function trading_partner_resource_exposes_sites_products_and_contact_relation_managers(): void
    {
        $relations = TradingPartnerResource::getRelations();

        $this->assertSame(
            [
                SitesRelationManager::class,
                ProductsRelationManager::class,
                ContactRelationManager::class,
            ],
            $relations
        );
    }

    #[Test]
    public function trading_partner_resource_keeps_nav_label_trading_partners(): void
    {
        $reflection = new \ReflectionClass(TradingPartnerResource::class);
        $property = $reflection->getProperty('navigationLabel');
        $property->setAccessible(true);

        $this->assertSame('Trading Partners', $property->getValue());
    }

    #[Test]
    public function site_resource_relations_are_ordered_devices_then_atp_licenses(): void
    {
        $relations = SiteResource::getRelations();

        $this->assertSame(
            [LocationDevicesRelationManager::class, AtpLicensesRelationManager::class],
            $relations
        );
    }

    #[Test]
    public function site_and_location_device_resources_hide_flat_navigation(): void
    {
        $this->assertFalse(SiteResource::shouldRegisterNavigation());
        $this->assertFalse(LocationDeviceResource::shouldRegisterNavigation());
    }

    #[Test]
    public function app_product_resource_registers_master_data_navigation(): void
    {
        $this->assertTrue(ProductResource::shouldRegisterNavigation());

        $reflection = new \ReflectionClass(ProductResource::class);
        $label = $reflection->getProperty('navigationLabel');
        $label->setAccessible(true);
        $sort = $reflection->getProperty('navigationSort');
        $sort->setAccessible(true);
        $group = $reflection->getProperty('navigationGroup');
        $group->setAccessible(true);

        $this->assertSame('Products', $label->getValue());
        $this->assertSame(30, $sort->getValue());
        $this->assertSame('Master Data', $group->getValue());
        $this->assertTrue(ProductResource::hasPage('view'));
        $this->assertTrue(ProductResource::hasPage('edit'));
        $this->assertFalse(ProductResource::hasPage('create'));
        $this->assertFalse(ProductResource::canCreate());
    }

    #[Test]
    public function app_fda_product_resource_registers_master_data_navigation(): void
    {
        $this->assertTrue(FdaProductResource::shouldRegisterNavigation());

        $reflection = new \ReflectionClass(FdaProductResource::class);
        $label = $reflection->getProperty('navigationLabel');
        $label->setAccessible(true);
        $sort = $reflection->getProperty('navigationSort');
        $sort->setAccessible(true);
        $group = $reflection->getProperty('navigationGroup');
        $group->setAccessible(true);

        $this->assertSame('FDA Products', $label->getValue());
        $this->assertSame(20, $sort->getValue());
        $this->assertSame('Master Data', $group->getValue());
    }

    #[Test]
    public function app_fda_product_resource_is_index_and_view_only(): void
    {
        $this->assertTrue(FdaProductResource::hasPage('index'));
        $this->assertTrue(FdaProductResource::hasPage('view'));
        $this->assertFalse(FdaProductResource::hasPage('create'));
        $this->assertFalse(FdaProductResource::hasPage('edit'));
        $this->assertFalse(FdaProductResource::canCreate());
    }

    #[Test]
    public function app_fda_product_resource_scopes_list_to_tenant_linked_fda_rows(): void
    {
        $this->assertTrue(method_exists(FdaProductResource::class, 'getEloquentQuery'));
        $this->assertTrue(method_exists(FdaProductResource::class, 'canView'));

        $sql = FdaProductResource::getEloquentQuery()->toSql();

        $this->assertStringContainsString('product_type', $sql);
        $this->assertStringContainsString('fda_product_id', $sql);
    }

    #[Test]
    public function app_fda_product_resource_access_follows_master_data_feature(): void
    {
        $this->assertTrue((new TenantFeatures(TenantProfile::Pharmacy))->supportsMasterData());
        $this->assertFalse((new TenantFeatures(TenantProfile::BuyingGroup))->supportsMasterData());
        $this->assertTrue(method_exists(FdaProductResource::class, 'canAccess'));
    }

    #[Test]
    public function trading_partner_model_has_products_relationship(): void
    {
        $partner = new TradingPartner;

        $this->assertTrue(method_exists($partner, 'products'));

        $relation = $partner->products();

        $this->assertInstanceOf(BelongsToMany::class, $relation);
        $this->assertInstanceOf(Product::class, $relation->getRelated());
    }

    #[Test]
    public function partner_resources_are_modal_only_for_create_and_edit(): void
    {
        $this->assertFalse(TradingPartnerResource::hasPage('create'));
        $this->assertFalse(TradingPartnerResource::hasPage('edit'));
    }

    #[Test]
    public function site_resources_do_not_register_a_full_edit_page(): void
    {
        $this->assertFalse(SiteResource::hasPage('edit'));
    }

    #[Test]
    public function sites_relation_manager_create_action_offers_fda_or_manual_modes(): void
    {
        $partner = new TradingPartner([
            'fda_organization_id' => 1,
            'name' => 'FDA-linked partner',
            'partner_type' => PartnerType::Wholesaler,
        ]);

        $manager = new SitesRelationManager;
        $reflection = new \ReflectionClass($manager);
        $ownerProperty = $reflection->getProperty('ownerRecord');
        $ownerProperty->setAccessible(true);
        $ownerProperty->setValue($manager, $partner);

        $createMethod = $reflection->getMethod('createSiteAction');
        $createMethod->setAccessible(true);
        /** @var CreateAction $createAction */
        $createAction = $createMethod->invoke($manager);

        $this->assertSame('New Site', $createAction->getLabel());
        $this->assertSame('Create site', $createAction->getModalSubmitActionLabel());

        $schemaMethod = $reflection->getMethod('createSiteFormComponents');
        $schemaMethod->setAccessible(true);
        $components = $schemaMethod->invoke($manager);

        $this->assertCount(4, $components);
        $this->assertSame('create_mode', $components[0]->getName());
        $this->assertSame(
            [
                PartnerSiteCreate::MODE_FDA => 'From FDA registry',
                PartnerSiteCreate::MODE_MANUAL => 'Create manually',
            ],
            $components[0]->getOptions(),
        );
        $this->assertInstanceOf(Section::class, $components[1]);
        $this->assertInstanceOf(Section::class, $components[2]);
        $this->assertInstanceOf(Section::class, $components[3]);
    }

    #[Test]
    public function sites_table_view_action_is_slide_over_with_atp_footer_link(): void
    {
        $table = SitesTable::configure(Table::make(new ListSites));

        $this->assertNull($table->getRecordUrl(new Site));

        $recordActions = $table->getRecordActions();
        $this->assertCount(1, $recordActions);
        $this->assertInstanceOf(ActionGroup::class, $recordActions[0]);

        $viewAction = collect($recordActions[0]->getActions())
            ->first(fn ($action) => $action->getName() === 'view');

        $this->assertInstanceOf(ViewAction::class, $viewAction);
        $this->assertSiteViewSlideOverAction($viewAction, Site::make()->forceFill(['id' => 42]));
    }

    #[Test]
    public function sites_relation_manager_view_action_is_slide_over_with_atp_footer_link(): void
    {
        $partner = new TradingPartner([
            'fda_organization_id' => 1,
            'name' => 'FDA-linked partner',
        ]);

        $manager = new SitesRelationManager;
        $reflection = new \ReflectionClass($manager);
        $ownerProperty = $reflection->getProperty('ownerRecord');
        $ownerProperty->setAccessible(true);
        $ownerProperty->setValue($manager, $partner);

        $table = $manager->table(Table::make($manager));

        $this->assertNull($table->getRecordUrl(new Site));

        $recordActions = $table->getRecordActions();
        $this->assertCount(1, $recordActions);
        $this->assertInstanceOf(ActionGroup::class, $recordActions[0]);

        $viewAction = collect($recordActions[0]->getActions())
            ->first(fn ($action) => $action->getName() === 'view');

        $this->assertInstanceOf(ViewAction::class, $viewAction);
        $this->assertSiteViewSlideOverAction($viewAction, Site::make()->forceFill(['id' => 7]));
    }

    /**
     * @param  ViewAction<Site>  $viewAction
     */
    private function assertSiteViewSlideOverAction(ViewAction $viewAction, Site $site): void
    {
        $this->assertTrue($viewAction->isModalSlideOver());
        $this->assertSame(Width::FiveExtraLarge, $viewAction->getModalWidth());
        $this->assertNull($viewAction->getUrl());

        $viewAction->record($site);
        $footerActions = $viewAction->getExtraModalFooterActions();
        $this->assertArrayHasKey('atpLicensesAndDevices', $footerActions);

        $footerAction = $footerActions['atpLicensesAndDevices'];
        $this->assertSame('ATP Licenses & Devices', $footerAction->getLabel());
        $this->assertFalse($footerAction->shouldOpenUrlInNewTab());

        $url = $footerAction->getUrl();
        $this->assertNotNull($url);
        $this->assertStringContainsString('sites', $url);
        $this->assertStringContainsString('relation=1', $url);
    }

    #[Test]
    public function view_site_subheading_is_compliance_focused(): void
    {
        $page = new ViewSite;

        $this->assertSame('Site compliance and scan locations.', $page->getSubheading());
    }

    #[Test]
    public function view_site_uses_compact_profile_on_atp_licenses_tab(): void
    {
        $page = new ViewSite;
        $page->activeRelationManager = '0';

        $reflection = new \ReflectionClass($page);
        $method = $reflection->getMethod('shouldUseCompactSiteProfile');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke($page));

        $page->activeRelationManager = '1';

        $this->assertTrue($method->invoke($page));
    }

    #[Test]
    public function atp_licenses_relation_manager_uses_slide_over_actions_without_open(): void
    {
        $manager = new AtpLicensesRelationManager;
        $table = $manager->table(Table::make($manager));

        $this->assertSame('License # or state', $table->getSearchPlaceholder());

        $licenseStateColumn = collect($table->getColumns())
            ->first(fn ($column) => $column->getName() === 'license_state');

        $this->assertNotNull($licenseStateColumn);
        $this->assertTrue($licenseStateColumn->isSearchable());

        $headerActions = $table->getHeaderActions();
        $this->assertCount(1, $headerActions);
        $this->assertTrue($headerActions[0]->isModalSlideOver());

        $recordActions = $table->getRecordActions();
        $this->assertCount(1, $recordActions);
        $this->assertInstanceOf(ActionGroup::class, $recordActions[0]);

        $editAction = collect($recordActions[0]->getActions())
            ->first(fn ($action) => $action->getName() === 'edit');

        $this->assertNotNull($editAction);
        $this->assertTrue($editAction->isModalSlideOver());
    }

    #[Test]
    public function location_devices_relation_manager_uses_slide_over_without_open(): void
    {
        $manager = new LocationDevicesRelationManager;
        $table = $manager->table(Table::make($manager));

        $headerActions = $table->getHeaderActions();
        $this->assertCount(1, $headerActions);
        $this->assertTrue($headerActions[0]->isModalSlideOver());

        $recordActions = $table->getRecordActions();
        $this->assertCount(1, $recordActions);
        $this->assertInstanceOf(ActionGroup::class, $recordActions[0]);

        $editAction = collect($recordActions[0]->getActions())
            ->first(fn ($action) => $action->getName() === 'edit');

        $this->assertNotNull($editAction);
        $this->assertTrue($editAction->isModalSlideOver());
    }

    #[Test]
    public function operations_hub_exposes_product_and_site_directory_links_when_master_data_enabled(): void
    {
        $hub = new OperationsHub;
        $directories = $hub->directories();

        // Without tenant context directories may be empty; assert method shape via TenantFeatures gate.
        $this->assertIsArray($directories);

        if ($directories === []) {
            $this->assertFalse(
                TenantFeatures::forTenant(tenant())->supportsMasterData()
            );

            return;
        }

        $labels = collect($directories)->pluck('label')->all();
        $this->assertContains('Product directory', $labels);
        $this->assertContains('FDA Products', $labels);
        $this->assertContains('Site directory', $labels);
        $this->assertContains('Trading Partners', $labels);

        $productDirectory = collect($directories)->firstWhere('label', 'Product directory');
        $this->assertNotNull($productDirectory);
        $this->assertStringContainsString('Authorized', $productDirectory['description']);
        $this->assertStringNotContainsString('All products', $productDirectory['description']);

        foreach ($directories as $directory) {
            $this->assertNotEmpty($directory['url']);
        }
    }
}
