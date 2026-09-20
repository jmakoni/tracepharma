<?php

namespace Tests\Feature\Shipping;

use App\Actions\Epcis\IngestEpcisXmlDocument;
use App\Actions\Receiving\ConfirmReceivingScan;
use App\Actions\Receiving\GenerateReceivingEpcisEvents;
use App\Actions\Receiving\OpenReceivingSessionFromDocument;
use App\Actions\Shipping\CompleteOutboundShippingSession;
use App\Actions\Shipping\ConfirmOutboundShippingScan;
use App\Actions\Shipping\OpenOutboundShippingSession;
use App\Actions\Shipping\UpdateOutboundShippingParty;
use App\Actions\Shipping\UpdateOutboundShippingReferences;
use App\Enums\PartnerType;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Resources\OutboundShippingSessions\OutboundShippingSessionResource;
use App\Filament\App\Resources\OutboundShippingSessions\Pages\MobileViewOutboundShippingSession;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Quarantine\QuarantineHold;
use App\Models\Receiving\ReceivingSession;
use App\Models\Shipping\OutboundShippingScanLine;
use App\Models\Shipping\OutboundShippingSession;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\Shipping\ShipLayout;
use App\Support\TenantSettings;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MobileFloorShippingTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private const SSCC_URI = 'urn:epc:id:sscc:030116.01001227052';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $siteIds = [];

    /** @var list<int> */
    private array $sessionIds = [];

    /** @var list<int> */
    private array $receivingSessionIds = [];

    /** @var list<int> */
    private array $documentIds = [];

    /** @var list<int> */
    private array $epcIds = [];

    private ?int $priorDefaultShipFromSiteId = null;

    private ?int $priorDefaultReceiveSiteId = null;

    private ?TenantProfile $priorProfile = null;

    #[Test]
    public function floor_page_is_registered_and_can_access(): void
    {
        $tenant = $this->initializeWholesalerTenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $this->assertTrue(OutboundShippingSessionResource::canAccess());
            $this->assertArrayHasKey('floor', OutboundShippingSessionResource::getPages());

            $user = $this->createOwnerUser();
            $this->actingAs($user);

            $site = $this->createShipSite($tenant);
            $session = app(OpenOutboundShippingSession::class)->handle((int) $site->getKey());
            $this->sessionIds[] = (int) $session->getKey();

            $this->assertTrue(MobileViewOutboundShippingSession::canAccess(['record' => $session->getKey()]));

            $floorUrl = OutboundShippingSessionResource::getUrl('floor', ['record' => $session], panel: 'app');
            $this->assertStringContainsString('/floor', $floorUrl);
            $this->assertSame(
                $floorUrl,
                ShipLayout::floorUrl($session),
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function floor_page_mounts_for_demo2_session_and_confirm_scan_works(): void
    {
        $tenant = $this->initializeWholesalerTenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $user = $this->createOwnerUser();
            $this->actingAs($user);

            $site = $this->createShipSite($tenant);
            $this->makeEpcShippableAtSite($site);

            $session = app(OpenOutboundShippingSession::class)->handle((int) $site->getKey());
            $this->sessionIds[] = (int) $session->getKey();

            $component = Livewire::test(MobileViewOutboundShippingSession::class, ['record' => $session->getKey()])
                ->assertSuccessful()
                ->assertSeeHtml('id="floor-scan-input"')
                ->assertDontSeeHtml('tp-floor-receive__cart-fab')
                ->assertSeeHtml('tp-floor-receive__footer')
                ->assertSeeHtml('tp-staged-scan-panel')
                ->assertSeeHtml('tp-floor-ship')
                ->assertSeeHtml('tp-floor-receive__progress-stats')
                ->assertSeeHtml('tp-floor-receive__camera-overlay')
                ->assertSeeHtml('tp-floor-camera-counts')
                ->assertSee('Confirmed')
                ->assertSee('Back to ship orders')
                ->assertSee('Customer & send')
                ->assertSee('Scanned items will appear here')
                ->assertSee('Just scanned')
                ->assertDontSee('Send shipment')
                ->assertDontSee('Customer PO')
                ->set('scan', self::SSCC_URI)
                ->callAction('confirmScan')
                ->assertHasNoActionErrors();

            $this->assertContains($component->get('lastScanTone'), ['ok', 'warn']);

            $session->refresh();
            $this->assertSame(1, (int) $session->confirmed_count);

            $component->assertSee('1')
                ->assertSee('Customer & send');
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function floor_blade_has_live_blur_and_enter_stage_scan_binding(): void
    {
        $blade = File::get(resource_path(
            'views/filament/app/resources/outbound-shipping-sessions/pages/mobile-view-outbound-shipping-session.blade.php',
        ));

        $this->assertStringContainsString('wire:model.live.blur="scan"', $blade);
        $this->assertStringContainsString('keydown.enter.prevent="$wire.stageScan($refs.scanInput.value)"', $blade);
        $this->assertStringContainsString('wire:submit.prevent="stageScan"', $blade);
        $this->assertStringContainsString("tpFloorReceiveConfig('stageScan')", $blade);
        $this->assertStringNotContainsString('wire:model="scan"', $blade);
        $this->assertStringNotContainsString("mountAction('confirmScan')", $blade);
    }

    #[Test]
    public function desktop_blade_has_live_blur_and_enter_stage_scan_binding(): void
    {
        $blade = File::get(resource_path(
            'views/filament/app/partials/outbound-ship-wizard-step-scan.blade.php',
        ));

        $this->assertStringContainsString('wire:model.live.blur="scan"', $blade);
        $this->assertStringContainsString('keydown.enter.prevent="$wire.stageScan($refs.scanInput.value)"', $blade);
        $this->assertStringContainsString('wire:submit.prevent="stageScan"', $blade);
        $this->assertStringNotContainsString('wire:model="scan"', $blade);
        $this->assertStringNotContainsString("mountAction('confirmScan')", $blade);
    }

    #[Test]
    public function desktop_ship_blades_expose_send_and_void_macros(): void
    {
        $view = File::get(resource_path(
            'views/filament/app/resources/outbound-shipping-sessions/pages/view-outbound-shipping-session.blade.php',
        ));
        $this->assertStringContainsString("mountAction('voidShipOrder')", $view);
        $this->assertStringContainsString('badge-error', $view);

        $send = File::get(resource_path(
            'views/filament/app/partials/outbound-ship-wizard-step-send.blade.php',
        ));
        $this->assertStringContainsString("mountAction('sendShipment')", $send);
    }

    #[Test]
    public function floor_hardware_scan_enter_confirms_dom_value_without_wire_property(): void
    {
        $tenant = $this->initializeWholesalerTenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $user = $this->createOwnerUser();
            $this->actingAs($user);

            $site = $this->createShipSite($tenant);
            $this->makeEpcShippableAtSite($site);

            $session = app(OpenOutboundShippingSession::class)->handle((int) $site->getKey());
            $this->sessionIds[] = (int) $session->getKey();

            $component = Livewire::test(MobileViewOutboundShippingSession::class, ['record' => $session->getKey()])
                ->assertSuccessful()
                ->assertSet('scan', '')
                ->call('stageScan', self::SSCC_URI)
                ->assertSet('scan', '');

            $this->assertContains($component->get('lastScanTone'), ['ok', 'warn']);

            $session->refresh();
            $this->assertSame(1, (int) $session->confirmed_count);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function floor_completed_sent_order_can_void_and_shows_warning_banner(): void
    {
        $tenant = $this->initializeWholesalerTenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            Storage::fake((string) config('tracepharma.epcis.payload_disk', 'local'));
            Storage::fake((string) config('tracepharma.epcis.authored_payload_disk', 'local'));
            config(['tracepharma.epcis.enforce_atp_outbound_gate' => false]);

            $user = $this->createOwnerUser();
            $this->actingAs($user);

            $site = $this->createShipSite($tenant);
            $user->syncSites([(int) $site->getKey()], (int) $site->getKey());

            $ssccUri = $this->makeUniqueSsccShippableAtSite($site);

            $session = app(OpenOutboundShippingSession::class)->handle((int) $site->getKey());
            $this->sessionIds[] = (int) $session->getKey();

            $confirmed = app(ConfirmOutboundShippingScan::class)->handle($session, $ssccUri);
            $this->assertTrue($confirmed['ok'], $confirmed['message'] ?? 'ship confirm failed');

            $partner = TradingPartner::query()->updateOrCreate(
                ['gln' => '0614141000005'],
                [
                    'name' => 'Floor Void Customer',
                    'sgln' => 'urn:epc:id:sgln:0614141.00000.0',
                    'partner_type' => PartnerType::Pharmacy,
                    'is_active' => true,
                ],
            );

            app(UpdateOutboundShippingParty::class)->handle($session->fresh(), [
                'trading_partner_id' => (int) $partner->getKey(),
            ]);
            app(UpdateOutboundShippingReferences::class)->handle($session->fresh(), [
                'asn_number' => 'ASN-FLOOR-VOID',
                'customer_po' => 'PO-FLOOR-VOID',
                'dscsa_affirm' => true,
            ]);

            $completed = app(CompleteOutboundShippingSession::class)->handle($session->fresh());
            $this->assertNotNull($completed->epcis_document_id);
            $this->documentIds[] = (int) $completed->epcis_document_id;
            $completed->epcisDocument?->forceFill(['transmission_status' => 'sent'])->save();

            $this->assertTrue($completed->fresh()->canVoid());

            $page = Livewire::test(MobileViewOutboundShippingSession::class, ['record' => $completed->getKey()])
                ->assertSuccessful()
                ->assertSee('Void shipment')
                ->assertSeeHtml('tp-floor-receive__complete')
                ->assertActionVisible('voidShipOrder')
                ->mountAction('voidShipOrder')
                ->callMountedAction()
                ->assertHasNoActionErrors();

            $completed->refresh();
            $this->assertNotNull($completed->voided_at);
            if ($completed->void_epcis_document_id !== null) {
                $this->documentIds[] = (int) $completed->void_epcis_document_id;
            }

            $page->assertSee('Shipment voided')
                ->assertSeeHtml('tp-floor-receive__complete--warning');
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function mobile_remove_recent_scan_line_unconfirms_scan(): void
    {
        $tenant = $this->initializeWholesalerTenant();

        try {
            // Ingest uses payload_disk (phpunit: local). Fake after tenancy so writes
            // are not blocked by an unwritable tenant-suffixed storage root.
            Storage::fake((string) config('tracepharma.epcis.payload_disk', 'local'));
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $user = $this->createOwnerUser();
            $this->actingAs($user);

            $site = $this->createShipSite($tenant);
            $this->makeEpcShippableAtSite($site);

            $session = app(OpenOutboundShippingSession::class)->handle((int) $site->getKey());
            $this->sessionIds[] = (int) $session->getKey();

            $component = Livewire::test(MobileViewOutboundShippingSession::class, ['record' => $session->getKey()])
                ->call('stageScan', self::SSCC_URI);

            $line = OutboundShippingScanLine::query()
                ->where('outbound_shipping_session_id', $session->getKey())
                ->where('status', 'confirmed')
                ->first();

            $this->assertNotNull($line);

            $component->call('removeRecentScanLine', (int) $line->getKey());

            $this->assertSame(
                0,
                OutboundShippingScanLine::query()
                    ->where('outbound_shipping_session_id', $session->getKey())
                    ->where('status', 'confirmed')
                    ->count(),
            );
            $this->assertSame(0, (int) $session->fresh()->confirmed_count);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function ship_layout_session_url_prefers_floor_when_cookie_set(): void
    {
        $tenant = $this->initializeWholesalerTenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $site = $this->createShipSite($tenant);
            $session = app(OpenOutboundShippingSession::class)->handle((int) $site->getKey());
            $this->sessionIds[] = (int) $session->getKey();

            $desktop = ShipLayout::sessionUrl($session);
            $this->assertStringNotContainsString('/floor', parse_url($desktop, PHP_URL_PATH) ?? $desktop);

            request()->cookies->set(ShipLayout::COOKIE, ShipLayout::FLOOR);
            $floor = ShipLayout::sessionUrl($session);
            $this->assertStringContainsString('/floor', $floor);
        } finally {
            $this->cleanup($tenant);
        }
    }

    private function createOwnerUser(): User
    {
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::DrugWholesaler);

        $user = User::factory()->create([
            'email' => 'ship-floor-'.uniqid('', true).'@example.test',
        ]);
        $user->assignRole(TenantRole::Owner->value);

        return $user;
    }

    private function initializeWholesalerTenant(): Tenant
    {
        $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);

        if ($tenant === null) {
            $tenant = Tenant::withoutEvents(fn () => Tenant::query()->create([
                'id' => self::DEMO2_TENANT_ID,
                'name' => 'Demo Wholesaler',
                'profile' => TenantProfile::DrugWholesaler,
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

        $tenant->forceFill(['profile' => TenantProfile::DrugWholesaler])->save();

        if (! self::$demo2TenantReady) {
            $this->artisan('tenants:migrate', [
                '--tenants' => [self::DEMO2_TENANT_ID],
                '--force' => true,
            ])->assertSuccessful();

            self::$demo2TenantReady = true;
        }

        tenancy()->initialize($tenant->fresh());

        return $tenant;
    }

    private function createShipSite(Tenant $tenant): Site
    {
        $liveTenant = tenant() instanceof Tenant ? tenant() : $tenant;
        $settings = TenantSettings::forTenant($liveTenant);
        if ($this->priorDefaultShipFromSiteId === null) {
            $this->priorDefaultShipFromSiteId = $settings->defaultShipFromSiteId();
        }
        if ($this->priorDefaultReceiveSiteId === null) {
            $this->priorDefaultReceiveSiteId = $settings->defaultReceiveSiteId();
        }

        $companyPrefix = '036615';
        $siteGln = $this->uniqueOrgGln($companyPrefix);

        $site = Site::query()->create([
            'name' => 'Ship Site '.Str::random(6),
            'gln' => $siteGln,
            'is_active' => true,
            'is_headquarters' => true,
            'is_organization_facility' => true,
            'trading_partner_id' => null,
        ]);
        $this->siteIds[] = (int) $site->getKey();

        $settings->saveOrganization([
            'gln' => $siteGln,
            'company_prefix' => $companyPrefix,
            'default_ship_from_site_id' => (int) $site->getKey(),
            'default_receive_site_id' => (int) $site->getKey(),
        ]);

        return $site;
    }

    private function makeUniqueSsccShippableAtSite(Site $site): string
    {
        do {
            $serial = '0'.str_pad((string) random_int(0, 9_999_999_999), 10, '0', STR_PAD_LEFT);
            $uri = 'urn:epc:id:sscc:030116.'.$serial;
        } while (Epc::query()->where('epc_uri', $uri)->exists());

        $document = $this->ingestMinimalFixtureWithSscc($uri);
        $this->documentIds[] = (int) $document->getKey();

        $session = app(OpenReceivingSessionFromDocument::class)->handle($document);
        $this->receivingSessionIds[] = (int) $session->getKey();
        $session->forceFill(['site_id' => (int) $site->getKey()])->save();

        app(ConfirmReceivingScan::class)->handle(
            $session->fresh(),
            $uri,
            userId: null,
            autoConfirmChildren: true,
        );

        $session = $session->fresh();
        $session->forceFill([
            'status' => 'completed',
            'completed_at' => now(),
            'receiving_events_generated_at' => $session->receiving_events_generated_at ?? now(),
        ])->save();

        if ($session->receiving_epcis_document_id === null) {
            app(GenerateReceivingEpcisEvents::class)->handle($session->fresh());
            $session = $session->fresh();
        }

        $this->assertNotNull(
            $session->receiving_epcis_document_id,
            'Expected receiving EPCIS events so the unique SSCC is on-hand for ship.',
        );

        if ($session->receiving_epcis_document_id !== null) {
            $this->documentIds[] = (int) $session->receiving_epcis_document_id;
        }

        $epc = Epc::query()->where('epc_uri', $uri)->first();
        if ($epc !== null) {
            $this->epcIds[] = (int) $epc->getKey();
        }

        return $uri;
    }

    private function ingestMinimalFixtureWithSscc(string $ssccUri): EpcisDocument
    {
        $fixture = base_path('tests/Fixtures/epcis/minimal_object_shipping.xml');
        $this->assertFileExists($fixture);

        $tmp = tempnam(sys_get_temp_dir(), 'epcis_');
        $this->assertNotFalse($tmp);
        $xml = file_get_contents($fixture);
        $this->assertNotFalse($xml);
        $xml = str_replace('11111111-2222-3333-4444-555555555555', (string) Str::uuid(), $xml);
        $xml = str_replace(self::SSCC_URI, $ssccUri, $xml);
        file_put_contents($tmp, $xml);

        try {
            return app(IngestEpcisXmlDocument::class)->handle($tmp, [
                'direction' => 'inbound',
                'original_filename' => basename($fixture),
            ]);
        } finally {
            @unlink($tmp);
        }
    }

    private function makeEpcShippableAtSite(Site $site): int
    {
        Storage::fake((string) config('tracepharma.epcis.payload_disk', 'local'));

        $document = $this->ingestMinimalFixture();
        $this->documentIds[] = (int) $document->getKey();

        $session = app(OpenReceivingSessionFromDocument::class)->handle($document);
        $this->receivingSessionIds[] = (int) $session->getKey();
        $session->forceFill(['site_id' => (int) $site->getKey()])->save();

        app(ConfirmReceivingScan::class)->handle(
            $session->fresh(),
            self::SSCC_URI,
            userId: null,
            autoConfirmChildren: true,
        );

        $session = $session->fresh();
        $session->forceFill([
            'status' => 'completed',
            'completed_at' => now(),
            'receiving_events_generated_at' => $session->receiving_events_generated_at ?? now(),
        ])->save();

        // Author receiving events so fixture stock lands on-hand at the site.
        if ($session->receiving_epcis_document_id === null) {
            app(GenerateReceivingEpcisEvents::class)->handle($session->fresh());
            $session = $session->fresh();
        }

        $this->assertNotNull(
            $session->receiving_epcis_document_id,
            'Expected receiving EPCIS events so the SSCC is on-hand for ship.',
        );

        // Fixture SSCC is shared across runs — release any leftover exclusive receives
        // that still claim the same EPC so ship confirms are not blocked.
        ReceivingSession::query()
            ->whereKeyNot($session->getKey())
            ->where(function ($exclusive): void {
                $exclusive
                    ->whereIn('status', ['open', 'in_progress'])
                    ->orWhere(function ($pending): void {
                        $pending
                            ->where('status', 'completed')
                            ->whereNull('receiving_events_generated_at');
                    });
            })
            ->whereHas('scanLines', function ($lines): void {
                $lines->whereIn('status', ['confirmed', 'unexpected'])
                    ->whereHas('epc', fn ($epc) => $epc->where('sscc18', '003011610012270529'));
            })
            ->update([
                'status' => 'completed',
                'completed_at' => now(),
                'receiving_events_generated_at' => now(),
            ]);

        if ($session->receiving_epcis_document_id !== null) {
            $this->documentIds[] = (int) $session->receiving_epcis_document_id;
        }

        return (int) $session->getKey();
    }

    private function ingestMinimalFixture(): EpcisDocument
    {
        $fixture = base_path('tests/Fixtures/epcis/minimal_object_shipping.xml');
        $this->assertFileExists($fixture);

        $tmp = tempnam(sys_get_temp_dir(), 'epcis_');
        $this->assertNotFalse($tmp);
        $xml = file_get_contents($fixture);
        $this->assertNotFalse($xml);
        $xml = str_replace('11111111-2222-3333-4444-555555555555', (string) Str::uuid(), $xml);
        file_put_contents($tmp, $xml);

        try {
            return app(IngestEpcisXmlDocument::class)->handle($tmp, [
                'direction' => 'inbound',
                'original_filename' => basename($fixture),
            ]);
        } finally {
            @unlink($tmp);
        }
    }

    private function uniqueOrgGln(string $companyPrefix): string
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $body12 = $companyPrefix.str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $gln = $body12.$this->gs1CheckDigit($body12);

            if (! Site::query()->where('gln', $gln)->exists()) {
                return $gln;
            }
        }

        throw new \RuntimeException('Unable to allocate a unique site GLN for the test.');
    }

    private function gs1CheckDigit(string $bodyWithoutCheck): string
    {
        $sum = 0;
        $digits = str_split(strrev($bodyWithoutCheck));

        foreach ($digits as $index => $digit) {
            $sum += ((int) $digit) * ($index % 2 === 0 ? 3 : 1);
        }

        return (string) ((10 - ($sum % 10)) % 10);
    }

    private function cleanup(Tenant $tenant): void
    {
        if (tenancy()->initialized) {
            if ($this->sessionIds !== []) {
                OutboundShippingScanLine::query()
                    ->whereIn('outbound_shipping_session_id', $this->sessionIds)
                    ->delete();
                OutboundShippingSession::query()->whereIn('id', $this->sessionIds)->delete();
                $this->sessionIds = [];
            }

            if ($this->epcIds !== []) {
                QuarantineHold::query()->whereIn('epc_id', $this->epcIds)->delete();
            }

            if ($this->receivingSessionIds !== []) {
                ReceivingSession::query()->whereIn('id', $this->receivingSessionIds)->delete();
                $this->receivingSessionIds = [];
            }

            if ($this->documentIds !== []) {
                DB::table('event_epcs')
                    ->whereIn('event_id', function ($query): void {
                        $query->select('id')
                            ->from('epcis_events')
                            ->whereIn('document_id', $this->documentIds);
                    })
                    ->delete();
                DB::table('epcis_events')->whereIn('document_id', $this->documentIds)->delete();
                DB::table('document_epcs')->whereIn('document_id', $this->documentIds)->delete();
                EpcisDocument::query()->whereIn('id', $this->documentIds)->delete();
                $this->documentIds = [];
            }

            if ($this->epcIds !== []) {
                DB::table('document_epcs')->whereIn('epc_id', $this->epcIds)->delete();
                DB::table('event_epcs')->whereIn('epc_id', $this->epcIds)->delete();
                DB::table('aggregation_links')
                    ->where(function ($query): void {
                        $query->whereIn('parent_epc_id', $this->epcIds)
                            ->orWhereIn('child_epc_id', $this->epcIds);
                    })
                    ->delete();
                Epc::query()->whereIn('id', $this->epcIds)->delete();
                $this->epcIds = [];
            }

            if ($this->siteIds !== []) {
                Site::query()->whereIn('id', $this->siteIds)->delete();
                $this->siteIds = [];
            }

            if ($this->priorDefaultShipFromSiteId !== null || $this->priorDefaultReceiveSiteId !== null) {
                $settings = TenantSettings::forTenant(tenant());
                $settings->setDefaultShipFromSiteId($this->priorDefaultShipFromSiteId);
                $settings->setDefaultReceiveSiteId($this->priorDefaultReceiveSiteId);
                tenant()->save();
                $this->priorDefaultShipFromSiteId = null;
                $this->priorDefaultReceiveSiteId = null;
            }

            if ($this->priorProfile !== null) {
                $tenant->forceFill(['profile' => $this->priorProfile])->save();
                $this->priorProfile = null;
            }

            tenancy()->end();
        }
    }
}
