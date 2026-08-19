<?php

namespace Tests\Feature\MasterData;

use App\Enums\PartnerType;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Resources\TradingPartners\Pages\ViewTradingPartner;
use App\Filament\App\Resources\TradingPartners\RelationManagers\ProductsRelationManager;
use App\Filament\App\Resources\TradingPartners\RelationManagers\SitesRelationManager;
use App\Models\Product;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\Gs1\Gtin;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The partner tabs write master data, so they answer to SitePolicy and ProductPolicy
 * rather than being open to every signed-in user.
 *
 * GLNs are prefixed 094224 so rows stay traceable in the shared demo2 tenant.
 */
class PartnerRelationManagerAuthorizationTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private const GLN_PREFIX = '094224';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $partnerIds = [];

    /** @var list<int> */
    private array $siteIds = [];

    /** @var list<int> */
    private array $productIds = [];

    /** @var list<int> */
    private array $userIds = [];

    #[Test]
    public function only_master_data_personas_may_add_sites_to_a_partner(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $partner = $this->createPartner();
            $site = $this->createSiteFor($partner);

            $this->actAs(TenantRole::Owner);

            $this->sitesTab($partner)
                ->assertActionVisible(TestAction::make('create')->table())
                ->assertActionVisible(TestAction::make('delete')->table($site));

            $this->actAs(TenantRole::ReceivingTechnician);

            $this->sitesTab($partner)
                ->assertActionHidden(TestAction::make('create')->table())
                ->assertActionHidden(TestAction::make('delete')->table($site));
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function only_master_data_personas_may_change_the_partner_assortment(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $partner = $this->createPartner();
            $product = $this->createProductFor($partner);

            $this->actAs(TenantRole::Owner);

            $this->productsTab($partner)
                ->assertActionVisible(TestAction::make('receiveProducts')->table())
                ->assertActionVisible(TestAction::make('detach')->table($product));

            $this->actAs(TenantRole::ReceivingTechnician);

            $this->productsTab($partner)
                ->assertActionHidden(TestAction::make('receiveProducts')->table())
                ->assertActionHidden(TestAction::make('detach')->table($product));
        } finally {
            $this->cleanup();
        }
    }

    private function sitesTab(TradingPartner $partner): Testable
    {
        return Livewire::test(SitesRelationManager::class, [
            'ownerRecord' => $partner,
            'pageClass' => ViewTradingPartner::class,
        ]);
    }

    private function productsTab(TradingPartner $partner): Testable
    {
        return Livewire::test(ProductsRelationManager::class, [
            'ownerRecord' => $partner,
            'pageClass' => ViewTradingPartner::class,
        ]);
    }

    private function actAs(TenantRole $role): User
    {
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);

        $user = User::factory()->create();
        $user->assignRole($role->value);
        $this->userIds[] = (int) $user->getKey();

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('app'));

        return $user;
    }

    private function createPartner(): TradingPartner
    {
        $partner = TradingPartner::factory()->create([
            'name' => 'Relation Auth Partner '.uniqid(),
            'partner_type' => PartnerType::Wholesaler,
            'gln' => $this->uniqueGln('10'),
            'is_active' => true,
        ]);

        $this->partnerIds[] = (int) $partner->getKey();

        return $partner;
    }

    private function createSiteFor(TradingPartner $partner): Site
    {
        $site = Site::factory()->create([
            'trading_partner_id' => $partner->getKey(),
            'name' => 'Relation Auth Site '.uniqid(),
            'gln' => $this->uniqueGln('20'),
            'is_organization_facility' => false,
        ]);

        $this->siteIds[] = (int) $site->getKey();

        return $site;
    }

    private function createProductFor(TradingPartner $partner): Product
    {
        $product = Product::factory()->create([
            'trading_partner_id' => $partner->getKey(),
        ]);

        $this->productIds[] = (int) $product->getKey();

        $partner->products()->attach($product->getKey());

        return $product;
    }

    private function uniqueGln(string $marker): string
    {
        $body12 = self::GLN_PREFIX.$marker.str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);

        return $body12.Gtin::checkDigit($body12);
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

    private function cleanup(): void
    {
        if (tenancy()->initialized) {
            if ($this->productIds !== []) {
                DB::table('trading_partner_product')->whereIn('product_id', $this->productIds)->delete();
                DB::table('products')->whereIn('id', $this->productIds)->delete();
            }

            if ($this->siteIds !== []) {
                DB::table('sites')->whereIn('id', $this->siteIds)->delete();
            }

            if ($this->partnerIds !== []) {
                DB::table('sites')->whereIn('trading_partner_id', $this->partnerIds)->delete();
                DB::table('trading_partners')->whereIn('id', $this->partnerIds)->delete();
            }

            if ($this->userIds !== []) {
                DB::table('model_has_roles')
                    ->where('model_type', User::class)
                    ->whereIn('model_id', $this->userIds)
                    ->delete();
                User::query()->whereIn('id', $this->userIds)->delete();
            }

            tenancy()->end();
        }

        $this->productIds = [];
        $this->siteIds = [];
        $this->partnerIds = [];
        $this->userIds = [];
    }
}
