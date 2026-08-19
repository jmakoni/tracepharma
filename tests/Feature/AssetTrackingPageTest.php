<?php

namespace Tests\Feature;

use App\Actions\Receiving\OpenScanFirstReceivingSession;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Pages\AssetTracking;
use App\Filament\App\Resources\ReceivingSessions\ReceivingSessionResource;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcIlmd;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Product;
use App\Models\Receiving\ReceivingScanLine;
use App\Models\Receiving\ReceivingSession;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\Gs1\ElementString;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AssetTrackingPageTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    private ?int $documentId = null;

    private ?int $epcId = null;

    private ?int $productId = null;

    private ?int $sessionId = null;

    private ?int $siteId = null;

    #[Test]
    public function pharmacy_tenant_can_access_asset_tracking_page(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->assertTrue(AssetTracking::canAccess());
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function asset_tracking_page_loads_map_script_before_a_scan(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            $user = User::factory()->create();
            $user->assignRole(TenantRole::Owner->value);
            $this->actingAs($user);

            $page = file_get_contents(resource_path('views/filament/app/pages/asset-tracking.blade.php'));

            $this->assertStringContainsString('tp-asset-tracking-map.js', $page);
            $this->assertStringContainsString('vendor/leaflet/leaflet.js', $page);
            $this->assertFileExists(public_path('js/tp-asset-tracking-map.js'));
            $this->assertFileExists(public_path('vendor/leaflet/leaflet.js'));

            Livewire::test(AssetTracking::class)->assertSuccessful();
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function scanning_a_known_sgtin_shows_identity_and_status(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            $user = User::factory()->create();
            $user->assignRole(TenantRole::Owner->value);
            $user->givePermissionTo(\App\Support\Auth\Permissions::SitesAccessAll);
            $this->actingAs($user);

            [$scan, $gtin14, $serial] = $this->seedTracedSgtin();

            // Render once first (mirrors browser page load caching `content`).
            $component = Livewire::test(AssetTracking::class);
            $component->html();

            $component
                ->set('scan', $scan)
                ->call('runTrace')
                ->assertSet('trace.found', true)
                ->assertSet('trace.status', 'Not in custody')
                ->assertSee('Not in custody')
                ->assertSee($gtin14.' · '.$serial)
                ->assertSee('Amoxicillin 500mg')
                ->assertSee('LOT-A1')
                ->assertSee('Tracking')
                ->assertSee('EPCIS')
                ->assertSee('Children')
                ->assertSee('Transactions');
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function first_trace_of_sscc_shows_results_without_second_click(): void
    {
        $this->initializeDemo2Tenant();

        try {
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            $user = User::factory()->create();
            $user->assignRole(TenantRole::Owner->value);
            $user->givePermissionTo(\App\Support\Auth\Permissions::SitesAccessAll);
            $this->actingAs($user);

            $suffix = (string) random_int(10000000, 99999999);
            $sscc = Epc::fromUri('urn:epc:id:sscc:030116.01'.$suffix.'0');
            $sscc->save();
            $this->epcId = (int) $sscc->getKey();
            $scan = (string) $sscc->ai_00;
            $sscc18 = (string) ($sscc->sscc18 ?: $sscc->ai_00);

            $component = Livewire::test(AssetTracking::class);
            $component->html();

            $component
                ->set('scan', $scan)
                ->call('runTrace')
                ->assertSet('trace.found', true)
                ->assertSee($sscc18)
                ->assertSee('Tracking')
                ->assertSee('EPCIS')
                ->assertSee('Children')
                ->assertSee('Transactions');
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function scanning_an_unresolvable_identifier_shows_not_found_state(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Livewire::test(AssetTracking::class)
                ->set('scan', 'UNKNOWN-LABEL-XYZ')
                ->call('runTrace')
                ->assertSet('trace.found', false)
                ->assertSee('No asset found for this scan')
                ->assertSee('Find / Recall')
                ->assertDontSee('Try Find / Recall or Verify product from the links above');
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function mounting_with_scan_query_parameter_auto_traces(): void
    {
        $this->initializeDemo2Tenant();

        try {
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            $user = User::factory()->create();
            $user->assignRole(TenantRole::Owner->value);
            $user->givePermissionTo(\App\Support\Auth\Permissions::SitesAccessAll);
            $this->actingAs($user);

            [$scan, $gtin14, $serial] = $this->seedTracedSgtin();

            Livewire::withQueryParams(['scan' => $scan])
                ->test(AssetTracking::class)
                ->assertSet('trace.found', true)
                ->assertSee($gtin14.' · '.$serial);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function unauthorized_scan_query_does_not_change_site_coordinates(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Http::fake([
                'nominatim.openstreetmap.org/*' => Http::response([
                    ['lat' => '34.052235', 'lon' => '-118.243683'],
                ], 200),
            ]);

            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            $user = User::factory()->create();
            $user->assignRole(TenantRole::ReceivingTechnician->value);
            $this->actingAs($user);

            $site = Site::factory()->owned()->create([
                'name' => 'Restricted warehouse',
                'street_address' => '123 Sunset Blvd',
                'city' => 'Los Angeles',
                'state' => 'CA',
                'zipcode' => '90012',
                'country_code' => 'US',
                'latitude' => null,
                'longitude' => null,
            ]);
            $this->siteId = (int) $site->getKey();

            [$scan] = $this->seedTracedSgtin();
            EpcisEvent::query()->where('document_id', $this->documentId)->update([
                'read_point_gln' => $site->gln,
                'biz_location_gln' => $site->gln,
            ]);

            Livewire::withQueryParams(['scan' => $scan])
                ->test(AssetTracking::class)
                ->assertSet('trace', null);

            $site->refresh();
            $this->assertNull($site->latitude);
            $this->assertNull($site->longitude);
            Http::assertSentCount(0);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function open_receive_action_appears_when_epc_is_on_open_session_and_redirects(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            $user = User::factory()->create();
            $user->assignRole(TenantRole::Owner->value);
            $this->actingAs($user);

            [$scan] = $this->seedTracedSgtin();

            $session = app(OpenScanFirstReceivingSession::class)->handle();
            $this->sessionId = (int) $session->getKey();

            ReceivingScanLine::query()->create([
                'receiving_session_id' => $session->getKey(),
                'epc_id' => $this->epcId,
                'parent_epc_id' => null,
                'line_role' => 'child',
                'status' => 'expected',
            ]);

            $expectedUrl = ReceivingSessionResource::getUrl('view', [
                'record' => $session,
                'scan' => ElementString::normalize($scan),
            ], panel: 'app');

            Livewire::test(AssetTracking::class)
                ->set('scan', $scan)
                ->call('runTrace')
                ->assertSet('trace.found', true)
                ->assertActionVisible('open_receive')
                ->callAction('open_receive')
                ->assertRedirect($expectedUrl);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function open_receive_action_hidden_when_no_receive_context(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            $user = User::factory()->create();
            $user->assignRole(TenantRole::Owner->value);
            $this->actingAs($user);

            [$scan] = $this->seedTracedSgtin();

            // Block unique ASN match so Open receive stays hidden without scan-line context.
            if ($this->documentId !== null) {
                EpcisDocument::query()->whereKey($this->documentId)->update([
                    'direction' => 'outbound',
                ]);
            }

            Livewire::test(AssetTracking::class)
                ->set('scan', $scan)
                ->call('runTrace')
                ->assertSet('trace.found', true)
                ->assertActionHidden('open_receive');
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function verify_product_header_action_uses_context_link_key(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            $user = User::factory()->create();
            $user->assignRole(TenantRole::Owner->value);
            $this->actingAs($user);

            [$scan] = $this->seedTracedSgtin();

            if ($this->documentId !== null) {
                EpcisDocument::query()->whereKey($this->documentId)->update([
                    'direction' => 'outbound',
                ]);
            }

            $component = Livewire::test(AssetTracking::class)
                ->set('scan', $scan)
                ->call('runTrace')
                ->assertSet('trace.found', true);

            $keys = collect($component->instance()->contextLinks())->pluck('key')->all();
            $this->assertContains('verify_product', $keys);
            $component->assertActionVisible('verify_product');
        } finally {
            $this->cleanup();
        }
    }

    /**
     * @return array{0: string, 1: string, 2: string} [scan, gtin14, serial]
     */
    private function seedTracedSgtin(): array
    {
        $suffix = (string) random_int(10000000, 99999999);
        $itemRef = substr($suffix, 0, 6);
        $serial = 'SN-'.$suffix;
        $uri = "urn:epc:id:sgtin:030116.3{$itemRef}.{$serial}";

        $document = EpcisDocument::query()->create([
            'document_uuid' => (string) str()->uuid(),
            'direction' => 'inbound',
            'creation_date' => now()->subHour(),
            'received_at' => now()->subHour(),
            'sender_gln' => '0301160000009',
            'receiver_gln' => '0096295000009',
            'ship_from_name' => 'Acme Wholesale',
            'ship_from_gln' => '0301160000009',
            'ship_to_name' => 'Demo Pharmacy',
            'ship_to_gln' => '0096295000009',
        ]);
        $this->documentId = (int) $document->getKey();

        $epc = Epc::fromUri($uri);
        $epc->save();
        $this->epcId = (int) $epc->getKey();
        $gtin14 = (string) $epc->gtin14;

        $ndc11 = '99'.$suffix.'0';

        $product = Product::query()->create([
            'gtin' => $gtin14,
            'name' => 'Amoxicillin 500mg',
            'dosage_form' => 'Capsule',
            'strength' => '500 mg',
            'ndc' => $ndc11,
            'ndc11' => $ndc11,
        ]);
        $this->productId = (int) $product->getKey();

        $epc->product_id = $product->getKey();
        $epc->save();

        EpcIlmd::query()->create([
            'epc_id' => $epc->getKey(),
            'lot_number' => 'LOT-A1',
            'expiry_date' => '2026-12-31',
            'manufacturing_date' => '2026-01-15',
        ]);

        $event = EpcisEvent::query()->create([
            'document_id' => $document->getKey(),
            'event_type' => 'ObjectEvent',
            'event_time' => now()->subMinutes(10),
            'action' => 'OBSERVE',
            'biz_step' => 'urn:epcglobal:cbv:bizstep:shipping',
            'disposition' => 'urn:epcglobal:cbv:disp:in_transit',
            'read_point_gln' => '0301160000009',
            'biz_location_gln' => '0301160000009',
        ]);

        DB::table('event_epcs')->insert([
            'event_id' => $event->getKey(),
            'epc_id' => $epc->getKey(),
            'role' => 'epcList',
        ]);

        return ["(01){$gtin14}(21){$serial}", $gtin14, $serial];
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

        Filament::setCurrentPanel(Filament::getPanel('app'));

        return $tenant;
    }

    private function cleanup(): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        if ($this->sessionId !== null) {
            ReceivingScanLine::query()->where('receiving_session_id', $this->sessionId)->delete();
            ReceivingSession::query()->whereKey($this->sessionId)->delete();
        }

        if ($this->epcId !== null) {
            ReceivingScanLine::query()->where('epc_id', $this->epcId)->delete();
            DB::table('event_epcs')->where('epc_id', $this->epcId)->delete();
            EpcIlmd::query()->where('epc_id', $this->epcId)->delete();
            Epc::query()->whereKey($this->epcId)->delete();
        }

        if ($this->documentId !== null) {
            EpcisEvent::query()->where('document_id', $this->documentId)->delete();
            EpcisDocument::query()->whereKey($this->documentId)->delete();
        }

        if ($this->productId !== null) {
            Product::query()->whereKey($this->productId)->delete();
        }

        if ($this->siteId !== null) {
            Site::query()->whereKey($this->siteId)->delete();
        }

        $this->sessionId = null;
        $this->epcId = null;
        $this->documentId = null;
        $this->productId = null;
        $this->siteId = null;

        tenancy()->end();
    }
}
