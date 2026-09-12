<?php

namespace Tests\Feature\Shipping;

use App\Actions\Epcis\IngestEpcisXmlDocument;
use App\Actions\Receiving\ConfirmReceivingScan;
use App\Actions\Receiving\OpenReceivingSessionFromDocument;
use App\Actions\Shipping\OpenOutboundShippingSession;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Resources\OutboundShippingSessions\OutboundShippingSessionResource;
use App\Filament\App\Resources\OutboundShippingSessions\Pages\MobileViewOutboundShippingSession;
use App\Models\Epcis\EpcisDocument;
use App\Models\Receiving\ReceivingSession;
use App\Models\Shipping\OutboundShippingScanLine;
use App\Models\Shipping\OutboundShippingSession;
use App\Models\Site;
use App\Models\Tenant;
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
                ->assertSeeHtml('tp-floor-receive__cart-fab')
                ->assertSeeHtml('tp-floor-ship')
                ->assertSeeHtml('tp-floor-receive__progress-stats')
                ->assertSee('Confirmed')
                ->assertSee('Back to ship orders')
                ->assertSee('Open desktop ship order')
                ->assertSee('Scanned items will appear here')
                ->assertSee('Recent scans')
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
        $this->assertStringNotContainsString('wire:model="scan"', $blade);
        $this->assertStringNotContainsString("mountAction('confirmScan')", $blade);
    }

    #[Test]
    public function desktop_blade_has_live_blur_and_enter_stage_scan_binding(): void
    {
        $blade = File::get(resource_path(
            'views/filament/app/resources/outbound-shipping-sessions/pages/view-outbound-shipping-session.blade.php',
        ));

        $this->assertStringContainsString('wire:model.live.blur="scan"', $blade);
        $this->assertStringContainsString('keydown.enter.prevent="$wire.stageScan($refs.scanInput.value)"', $blade);
        $this->assertStringContainsString('wire:submit.prevent="stageScan"', $blade);
        $this->assertStringNotContainsString('wire:model="scan"', $blade);
        $this->assertStringNotContainsString("mountAction('confirmScan')", $blade);
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

    private function makeEpcShippableAtSite(Site $site): int
    {
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
        ])->save();

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
                OutboundShippingSession::query()->whereIn('id', $this->sessionIds)->delete();
                $this->sessionIds = [];
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
                EpcisDocument::query()->whereIn('id', $this->documentIds)->delete();
                $this->documentIds = [];
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
