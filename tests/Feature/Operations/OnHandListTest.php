<?php

namespace Tests\Feature\Operations;

use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Pages\AssetTracking;
use App\Filament\App\Pages\OnHandList;
use App\Models\Epcis\AggregationLink;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcIlmd;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Quarantine\QuarantineHold;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\Floor\FloorTaskMenu;
use App\Support\Shipping\OnHandLotRollup;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\PlacesEpcOnHandAtSite;
use Tests\TestCase;

class OnHandListTest extends TestCase
{
    use PlacesEpcOnHandAtSite;

    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $epcIds = [];

    /** @var list<int> */
    private array $documentIds = [];

    /** @var list<int> */
    private array $eventIds = [];

    /** @var list<int> */
    private array $siteIds = [];

    /** @var list<int> */
    private array $aggregationLinkIds = [];

    /** @var list<int> */
    private array $holdIds = [];

    #[Test]
    public function lots_tab_shows_rollup_and_serials_tab_lists_units(): void
    {
        $this->initializeDemo2Tenant();

        try {
            [$user, $site] = $this->loginOwnerWithSite();
            $made = $this->makeOnHandSgtin($site, now()->addDays(20)->toDateString(), 'OHLOT1');

            Filament::setCurrentPanel(Filament::getPanel('app'));
            $this->actingAs($user);

            Livewire::test(OnHandList::class)
                ->set('siteId', $site->getKey())
                ->assertSuccessful()
                ->assertSee('OHLOT1', false)
                ->assertSee('Lots', false)
                ->call('setActiveTab', 'serials')
                ->assertSet('activeTab', 'serials')
                ->assertSee($made['serial'], false);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function open_lot_serials_filters_serials_tab(): void
    {
        $this->initializeDemo2Tenant();

        try {
            [$user, $site] = $this->loginOwnerWithSite();
            $made = $this->makeOnHandSgtin($site, now()->addDays(15)->toDateString(), 'FILTERLOT');

            Filament::setCurrentPanel(Filament::getPanel('app'));
            $this->actingAs($user);

            Livewire::test(OnHandList::class)
                ->set('siteId', $site->getKey())
                ->call('openLotSerials', (string) $made['epc']->gtin14, 'FILTERLOT')
                ->assertSet('activeTab', 'serials')
                ->assertSet('filterGtin', (string) $made['epc']->gtin14)
                ->assertSet('filterLot', 'FILTERLOT')
                ->assertSee($made['serial'], false);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function scan_redirects_to_asset_tracking(): void
    {
        $this->initializeDemo2Tenant();

        try {
            [$user, $site] = $this->loginOwnerWithSite();

            Filament::setCurrentPanel(Filament::getPanel('app'));
            $this->actingAs($user);

            $scan = '(00)003061612345678901';
            $component = Livewire::test(OnHandList::class)
                ->set('siteId', $site->getKey())
                ->set('scanInput', $scan)
                ->call('goToAssetTracking');

            $component->assertRedirect(AssetTracking::getUrl(['scan' => $scan], isAbsolute: false, panel: 'app'));
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function investigate_classifies_on_hand_serial(): void
    {
        $this->initializeDemo2Tenant();

        try {
            [$user, $site] = $this->loginOwnerWithSite();
            $made = $this->makeOnHandSgtin($site, now()->addDays(10)->toDateString(), 'INVLOT');

            Filament::setCurrentPanel(Filament::getPanel('app'));
            $this->actingAs($user);

            Livewire::test(OnHandList::class)
                ->set('siteId', $site->getKey())
                ->call('setActiveTab', 'investigate')
                ->set('investigatePaste', $made['epc']->epc_uri)
                ->call('runInvestigate')
                ->assertSee('on hand', false);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function export_lots_csv_streams_when_site_selected(): void
    {
        $this->initializeDemo2Tenant();

        try {
            [$user, $site] = $this->loginOwnerWithSite();
            $this->makeOnHandSgtin($site, now()->addDays(20)->toDateString(), 'CSVLOT');

            Filament::setCurrentPanel(Filament::getPanel('app'));
            $this->actingAs($user);

            Livewire::test(OnHandList::class)
                ->set('siteId', $site->getKey())
                ->callAction('exportLots')
                ->assertHasNoActionErrors();
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function navigation_label_is_on_hand(): void
    {
        $this->assertSame('On-hand', OnHandList::getNavigationLabel());
        $this->assertSame('on-hand', OnHandList::getSlug());
    }

    #[Test]
    public function serials_default_shows_parent_only_until_show_contents(): void
    {
        $this->initializeDemo2Tenant();

        try {
            [$user, $site] = $this->loginOwnerWithSite();
            [$parent, $child] = $this->makeOnHandPalletWithChild($site);

            Filament::setCurrentPanel(Filament::getPanel('app'));
            $this->actingAs($user);

            $component = Livewire::test(OnHandList::class)
                ->set('siteId', $site->getKey())
                ->call('setActiveTab', 'serials')
                ->assertSet('showContents', false)
                ->assertSee($parent->sscc18, false)
                ->assertDontSee($child->serial_number, false);

            $component->set('showContents', true)
                ->assertSee($parent->sscc18, false)
                ->assertSee($child->serial_number, false);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function lot_rollup_splits_pickable_and_open_hold_counts(): void
    {
        $this->initializeDemo2Tenant();

        try {
            [$user, $site] = $this->loginOwnerWithSite();
            $lot = 'MIXHOLD'.random_int(1000, 9999);
            $sharedGtin = null;
            $held = [];
            for ($i = 0; $i < 12; $i++) {
                $made = $this->makeOnHandSgtin(
                    $site,
                    now()->addDays(40)->toDateString(),
                    $lot,
                    $sharedGtin,
                );
                $sharedGtin = (string) $made['epc']->gtin14;
                if ($i < 2) {
                    $held[] = $made['epc'];
                }
            }

            foreach ($held as $epc) {
                $hold = QuarantineHold::query()->create([
                    'epc_id' => $epc->getKey(),
                    'status' => 'open',
                    'reason' => 'On-hand pickable test hold',
                    'opened_at' => now(),
                ]);
                $this->holdIds[] = (int) $hold->getKey();
            }

            $row = app(OnHandLotRollup::class)->rows((int) $site->getKey())
                ->first(fn (array $r): bool => $r['gtin14'] === $sharedGtin && $r['lot_number'] === $lot);

            $this->assertNotNull($row);
            $this->assertSame(12, $row['total']);
            $this->assertSame(10, $row['pickable']);
            $this->assertSame(2, $row['hold_count']);
            $this->assertTrue($row['has_quarantine']);
            unset($user);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function floor_task_menu_has_no_on_hand_and_profile_has_find(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $keys = array_column(FloorTaskMenu::launcherTiles(), 'key');
            $this->assertNotContains('on-hand', $keys);
            $this->assertNotContains('onhand', $keys);

            $nav = file_get_contents(resource_path('views/filament/app/partials/floor-bottom-nav.blade.php'));
            $this->assertNotFalse($nav);
            $this->assertStringContainsString('>Find</a>', $nav);
            $this->assertStringContainsString('AssetTracking::getUrl', $nav);
        } finally {
            $this->cleanup();
        }
    }

    /**
     * @return array{0: Epc, 1: Epc}
     */
    private function makeOnHandPalletWithChild(Site $site): array
    {
        $serialRef = '0'.str_pad((string) random_int(0, 9_999_999_999), 10, '0', STR_PAD_LEFT);
        $parent = Epc::fromUri('urn:epc:id:sscc:030116.'.$serialRef);
        $parent->first_seen_at = now();
        $parent->save();
        $this->epcIds[] = (int) $parent->getKey();

        $child = $this->makeOnHandSgtin($site, now()->addDays(25)->toDateString(), 'PALLOT')['epc'];
        $placedParent = $this->placeEpcOnHandAtSite($site, $parent);
        $this->documentIds[] = (int) $placedParent['document']->getKey();
        $this->eventIds[] = (int) $placedParent['event']->getKey();

        $link = AggregationLink::query()->create([
            'parent_epc_id' => $parent->getKey(),
            'child_epc_id' => $child->getKey(),
            'link_type' => 'contains',
            'valid_from' => now(),
            'valid_to' => null,
        ]);
        $this->aggregationLinkIds[] = (int) $link->getKey();

        return [$parent->fresh(), $child->fresh()];
    }

    private function loginOwnerWithSite(): array
    {
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
        $user = User::factory()->create();
        $user->assignRole(TenantRole::Owner->value);

        $gln = '03'.str_pad((string) random_int(0, 99_999_999_999), 11, '0', STR_PAD_LEFT);
        $site = Site::query()->create([
            'name' => 'OnHand Site '.substr((string) str()->uuid(), 0, 8),
            'gln' => $gln,
            'is_active' => true,
            'is_headquarters' => false,
            'is_organization_facility' => true,
        ]);
        $this->siteIds[] = (int) $site->getKey();

        return [$user, $site];
    }

    /**
     * @return array{epc: Epc, serial: string}
     */
    private function makeOnHandSgtin(Site $site, string $expiry, string $lot, ?string $forceGtin14 = null): array
    {
        $suffix = (string) random_int(10_000_000, 99_999_999);
        $itemRef = substr($suffix, 0, 6);
        $serial = 'OH'.$suffix;
        $epc = Epc::fromUri("urn:epc:id:sgtin:030116.3{$itemRef}.{$serial}");
        $epc->first_seen_at = now();
        if ($forceGtin14 !== null && $forceGtin14 !== '') {
            $epc->gtin14 = $forceGtin14;
        }
        $epc->save();
        $this->epcIds[] = (int) $epc->getKey();

        EpcIlmd::query()->create([
            'epc_id' => $epc->getKey(),
            'gtin14' => $epc->gtin14,
            'lot_number' => $lot,
            'expiry_date' => $expiry,
        ]);

        $placed = $this->placeEpcOnHandAtSite($site, $epc);
        $this->documentIds[] = (int) $placed['document']->getKey();
        $this->eventIds[] = (int) $placed['event']->getKey();

        return ['epc' => $epc->fresh(), 'serial' => $serial];
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
        if (! tenancy()->initialized) {
            return;
        }

        foreach ($this->eventIds as $id) {
            DB::table('event_epcs')->where('event_id', $id)->delete();
            EpcisEvent::query()->whereKey($id)->delete();
        }
        $this->eventIds = [];

        foreach ($this->holdIds as $id) {
            QuarantineHold::query()->whereKey($id)->delete();
        }
        $this->holdIds = [];

        foreach ($this->aggregationLinkIds as $id) {
            AggregationLink::query()->whereKey($id)->delete();
        }
        $this->aggregationLinkIds = [];

        foreach ($this->documentIds as $id) {
            EpcisDocument::query()->whereKey($id)->delete();
        }
        $this->documentIds = [];

        if ($this->epcIds !== []) {
            EpcIlmd::query()->whereIn('epc_id', $this->epcIds)->delete();
            Epc::query()->whereIn('id', $this->epcIds)->delete();
            $this->epcIds = [];
        }

        foreach ($this->siteIds as $id) {
            Site::query()->whereKey($id)->delete();
        }
        $this->siteIds = [];

        tenancy()->end();
    }
}
