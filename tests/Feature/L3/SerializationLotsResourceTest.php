<?php

declare(strict_types=1);

namespace Tests\Feature\L3;

use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Resources\SerializationLots\Pages\ListSerializationLots;
use App\Filament\App\Resources\SerializationLots\Pages\ViewSerializationLot;
use App\Filament\App\Resources\SerializationLots\SerializationLotResource;
use App\Models\Epcis\EpcisDocument;
use App\Models\L3\SerializationLot;
use App\Models\L3\SerializationLotContainerField;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\Permissions;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\TenantFeatures;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SerializationLotsResourceTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    private ?TenantProfile $priorProfile = null;

    /** @var list<int> */
    private array $lotIds = [];

    /** @var list<int> */
    private array $documentIds = [];

    /** @var list<int> */
    private array $userIds = [];

    #[Test]
    public function manufacturer_can_see_lots_nav_list_and_view_a_seeded_lot(): void
    {
        $this->initializeDemo2Tenant(TenantProfile::Manufacturer);

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            $this->actingAs($this->createOwner(TenantProfile::Manufacturer));

            $this->assertTrue(TenantFeatures::forTenant(tenant())->supportsCommissioning());
            $this->assertTrue(SerializationLotResource::canAccess());
            $this->assertSame('serialization-lots', SerializationLotResource::getSlug());
            $this->assertSame('Serialization Lots', SerializationLotResource::getNavigationLabel());

            $lot = $this->createLot();

            Livewire::test(ListSerializationLots::class)
                ->assertSuccessful()
                ->assertCanSeeTableRecords([$lot])
                ->assertSee('608464T')
                ->assertSee('Guardian Demo Product');

            Livewire::test(ViewSerializationLot::class, ['record' => $lot->getKey()])
                ->assertSuccessful()
                ->assertSee('608464T')
                ->assertSee('Guardian Demo Product')
                ->assertSee('Lot Control Data')
                ->assertSee('Hierarchy')
                // lot_control_data has a null and a blank value alongside a real one.
                ->assertSee('N/A')
                ->assertSee('Guardian Demo Product LotControl');
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function pharmacy_cannot_access_serialization_lots_resource(): void
    {
        $this->initializeDemo2Tenant(TenantProfile::Pharmacy);

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            $this->actingAs($this->createOwner(TenantProfile::Pharmacy));

            $this->assertFalse(TenantFeatures::forTenant(tenant())->supportsCommissioning());
            $this->assertFalse(SerializationLotResource::canAccess());

            Livewire::test(ListSerializationLots::class)->assertForbidden();
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function drug_wholesaler_cannot_access_serialization_lots_even_with_commissioning(): void
    {
        $this->initializeDemo2Tenant(TenantProfile::DrugWholesaler);

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            $this->actingAs($this->createOwner(TenantProfile::DrugWholesaler));

            $this->assertTrue(TenantFeatures::forTenant(tenant())->supportsCommissioning());
            $this->assertFalse(SerializationLotResource::canAccess());

            Livewire::test(ListSerializationLots::class)->assertForbidden();
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function site_scoped_query_excludes_null_site_lots(): void
    {
        $this->initializeDemo2Tenant(TenantProfile::Manufacturer);

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Manufacturer);

            $site = Site::query()->ownedByOrganization()->first();
            if ($site === null) {
                $site = Site::query()->create([
                    'name' => 'Lot Site '.Str::uuid(),
                    'gln' => '030116'.random_int(100000, 999999).'0',
                    'is_organization_facility' => true,
                ]);
            }

            $user = User::factory()->create([
                'email' => 'serialization-lots-scoped-'.Str::uuid().'@example.test',
            ]);
            $user->assignRole(TenantRole::PackagingLineOperator->value);
            $user->syncSites([(int) $site->getKey()], (int) $site->getKey());
            $this->userIds[] = (int) $user->getKey();
            $this->assertFalse($user->can(Permissions::SitesAccessAll));
            $this->actingAs($user);

            $nullSiteLot = $this->createLot(['site_id' => null, 'lot_number' => 'NULL-SITE-LOT']);
            $scopedLot = $this->createLot([
                'site_id' => (int) $site->getKey(),
                'lot_number' => 'SCOPED-SITE-LOT',
            ]);

            $ids = SerializationLotResource::getEloquentQuery()->pluck('id')->all();

            $this->assertContains((int) $scopedLot->getKey(), $ids);
            $this->assertNotContains((int) $nullSiteLot->getKey(), $ids);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function sites_access_all_query_includes_null_site_lots(): void
    {
        $this->initializeDemo2Tenant(TenantProfile::Manufacturer);

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            $this->actingAs($this->createOwner(TenantProfile::Manufacturer));

            $nullSiteLot = $this->createLot(['site_id' => null, 'lot_number' => 'OWNER-NULL-LOT']);

            $ids = SerializationLotResource::getEloquentQuery()->pluck('id')->all();
            $this->assertContains((int) $nullSiteLot->getKey(), $ids);
        } finally {
            $this->cleanup();
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createLot(array $overrides = []): SerializationLot
    {
        $document = EpcisDocument::query()->create([
            'document_uuid' => (string) Str::uuid(),
            'direction' => 'inbound',
            'creation_date' => now(),
            'received_at' => now(),
            'status' => 'parsed',
            'original_filename' => 'guardian-lot-close.xml',
        ]);
        $this->documentIds[] = (int) $document->getKey();

        $lot = SerializationLot::query()->create(array_merge([
            'lot_number' => '608464T',
            'ndc' => '0301162001165',
            'unit_gtin14' => '00301162001165',
            'case_gtin14' => '10301162001162',
            'product_name' => 'Guardian Demo Product',
            'expire_date' => now()->addYear()->toDateString(),
            'mfg_date' => now()->subMonth()->toDateString(),
            'line_name' => 'Line 1',
            'lot_processed_at' => now(),
            'timezone_offset' => '-05:00',
            'lot_info_saved_at' => now(),
            'lot_control_data' => [
                'ItemDescription' => 'Guardian Demo Product LotControl',
                'EmptyField' => null,
                'BlankField' => '',
            ],
            'pallet_count' => 1,
            'case_count' => 2,
            'unit_count' => 6,
            'status' => 'accepted',
            'epcis_document_id' => $document->getKey(),
        ], $overrides));
        $this->lotIds[] = (int) $lot->getKey();

        SerializationLotContainerField::query()->create([
            'lot_id' => $lot->getKey(),
            'epc_uri' => 'urn:epc:id:sscc:030116.01001227967',
            'container_type' => 'Pallet',
            'parent_epc_uri' => null,
            'fields' => ['URI' => 'urn:epc:id:sscc:030116.01001227967'],
        ]);

        return $lot;
    }

    private function createOwner(TenantProfile $profile): User
    {
        app(TenantRoleSeeder::class)->seedForProfile($profile);
        $user = User::factory()->create([
            'email' => 'serialization-lots-'.Str::uuid().'@example.test',
        ]);
        $user->assignRole(TenantRole::Owner->value);
        $this->userIds[] = (int) $user->getKey();

        return $user;
    }

    private function initializeDemo2Tenant(TenantProfile $profile): Tenant
    {
        $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);

        if ($tenant === null) {
            $tenant = Tenant::withoutEvents(fn () => Tenant::query()->create([
                'id' => self::DEMO2_TENANT_ID,
                'name' => 'Demo Manufacturer',
                'profile' => $profile,
                'status' => 'active',
                'tenancy_db_name' => self::DEMO2_DATABASE,
            ]));
            $tenant->domains()->create(['domain' => self::DEMO2_DOMAIN]);
        } else {
            $tenant->domains()->firstOrCreate(['domain' => self::DEMO2_DOMAIN]);
        }

        $this->priorProfile = $tenant->profile instanceof TenantProfile
            ? $tenant->profile
            : TenantProfile::tryFrom((string) $tenant->profile);

        $tenant->forceFill(['profile' => $profile])->save();

        if (! self::$demo2TenantReady) {
            $this->artisan('tenants:migrate', [
                '--tenants' => [self::DEMO2_TENANT_ID],
                '--force' => true,
            ])->assertSuccessful();
            self::$demo2TenantReady = true;
        }

        tenancy()->initialize($tenant->fresh());

        return tenant() instanceof Tenant ? tenant() : $tenant;
    }

    private function cleanup(): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        $tenant = tenant();

        foreach ($this->lotIds as $id) {
            // Container fields cascade-delete with the lot (FK cascadeOnDelete).
            SerializationLot::query()->whereKey($id)->delete();
        }
        $this->lotIds = [];

        foreach ($this->documentIds as $id) {
            EpcisDocument::query()->whereKey($id)->delete();
        }
        $this->documentIds = [];

        foreach ($this->userIds as $id) {
            User::query()->whereKey($id)->delete();
        }
        $this->userIds = [];

        if ($this->priorProfile !== null && $tenant !== null) {
            $tenant->forceFill(['profile' => $this->priorProfile])->save();
            $this->priorProfile = null;
        }

        tenancy()->end();
    }
}
