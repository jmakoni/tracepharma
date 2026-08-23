<?php

namespace Tests\Feature\Transferring;

use App\Actions\Transferring\OpenTransferringSession;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Resources\TransferringSessions\Pages\MobileViewTransferringSession;
use App\Filament\App\Resources\TransferringSessions\TransferringSessionResource;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\Transferring\TransferringScanLine;
use App\Models\Transferring\TransferringSession;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\Gs1\Gtin;
use App\Support\TenantSettings;
use App\Support\Transferring\TransferLayout;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MobileFloorTransferringTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $siteIds = [];

    private ?int $sessionId = null;

    private ?int $epcId = null;

    /** @var list<int> */
    private array $custodyDocumentIds = [];

    /** @var list<int> */
    private array $custodyEventIds = [];

    private ?int $priorDefaultShipFromSiteId = null;

    private ?int $priorDefaultReceiveSiteId = null;

    #[Test]
    public function floor_page_is_registered_and_can_access(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $this->assertTrue(TransferringSessionResource::canAccess());
            $this->assertArrayHasKey('floor', TransferringSessionResource::getPages());

            $user = $this->createOwnerUser();
            $this->actingAs($user);

            [$fromSite, $toSite] = $this->createTransferSites($tenant);

            $session = app(OpenTransferringSession::class)->handle(
                fromSiteId: (int) $fromSite->getKey(),
                toSiteId: (int) $toSite->getKey(),
            );
            $this->sessionId = (int) $session->getKey();

            $this->assertTrue(MobileViewTransferringSession::canAccess(['record' => $session->getKey()]));

            $floorUrl = TransferringSessionResource::getUrl('floor', ['record' => $session], panel: 'app');
            $this->assertStringContainsString('/floor', $floorUrl);
            $this->assertSame(
                $floorUrl,
                TransferLayout::floorUrl($session),
            );
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function floor_page_mounts_for_demo2_session_and_confirm_scan_works(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $user = $this->createOwnerUser();
            $this->actingAs($user);

            [$fromSite, $toSite] = $this->createTransferSites($tenant);

            $suffix = (string) random_int(10000000, 99999999);
            $uri = 'urn:epc:id:sgtin:030116.3'.substr($suffix, 0, 6).'.FL'.$suffix;

            $epc = Epc::query()->create(Epc::materializeAttributesFromUri($uri));
            $this->epcId = (int) $epc->getKey();
            $this->receiveAtSite($fromSite, $epc);

            $session = app(OpenTransferringSession::class)->handle(
                fromSiteId: (int) $fromSite->getKey(),
                toSiteId: (int) $toSite->getKey(),
            );
            $this->sessionId = (int) $session->getKey();

            $component = Livewire::test(MobileViewTransferringSession::class, ['record' => $session->getKey()])
                ->assertSuccessful()
                ->assertSeeHtml('id="floor-scan-input"')
                ->assertSeeHtml('tp-floor-receive__cart-fab')
                ->assertSeeHtml('tp-floor-transfer')
                ->assertSeeHtml('tp-floor-receive__progress-stats')
                ->assertSee('Confirmed')
                ->assertSee('Ship transfer')
                ->assertSee('Scan at least one item to ship')
                ->assertSee('Back to transfers')
                ->assertSee('Open desktop transfer')
                ->assertSee('Scanned items will appear here')
                ->assertSee('Recent scans')
                ->assertDontSee('Staged scans')
                ->assertDontSee('Confirm staged')
                ->set('scan', $uri)
                ->callAction('confirmScan')
                ->assertHasNoActionErrors();

            $this->assertContains($component->get('lastScanTone'), ['ok', 'warn']);

            $session->refresh();
            $this->assertSame(1, (int) $session->confirmed_count);

            $component->assertSee('1')
                ->assertSee('Ship transfer');
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function floor_blade_has_live_blur_and_enter_stage_scan_binding(): void
    {
        $blade = File::get(resource_path(
            'views/filament/app/resources/transferring-sessions/pages/mobile-view-transferring-session.blade.php',
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
            'views/filament/app/resources/transferring-sessions/pages/view-transferring-session.blade.php',
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
        $tenant = $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $user = $this->createOwnerUser();
            $this->actingAs($user);

            [$fromSite, $toSite] = $this->createTransferSites($tenant);

            $suffix = (string) random_int(10000000, 99999999);
            $uri = 'urn:epc:id:sgtin:030116.3'.substr($suffix, 0, 6).'.HW'.random_int(10000000, 99999999);

            $epc = Epc::query()->create(Epc::materializeAttributesFromUri($uri));
            $this->epcId = (int) $epc->getKey();
            $this->receiveAtSite($fromSite, $epc);

            $session = app(OpenTransferringSession::class)->handle(
                fromSiteId: (int) $fromSite->getKey(),
                toSiteId: (int) $toSite->getKey(),
            );
            $this->sessionId = (int) $session->getKey();

            $component = Livewire::test(MobileViewTransferringSession::class, ['record' => $session->getKey()])
                ->assertSuccessful()
                ->assertSet('scan', '')
                ->call('stageScan', $uri)
                ->assertSet('scan', '');

            $this->assertContains($component->get('lastScanTone'), ['ok', 'warn']);

            $session->refresh();
            $this->assertSame(1, (int) $session->confirmed_count);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function transfer_layout_session_url_prefers_floor_when_cookie_set(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));

            [$fromSite, $toSite] = $this->createTransferSites($tenant);

            $session = app(OpenTransferringSession::class)->handle(
                fromSiteId: (int) $fromSite->getKey(),
                toSiteId: (int) $toSite->getKey(),
            );
            $this->sessionId = (int) $session->getKey();

            $desktop = TransferLayout::sessionUrl($session);
            $this->assertStringNotContainsString('/floor', parse_url($desktop, PHP_URL_PATH) ?? $desktop);

            request()->cookies->set(TransferLayout::COOKIE, TransferLayout::FLOOR);
            $floor = TransferLayout::sessionUrl($session);
            $this->assertStringContainsString('/floor', $floor);
        } finally {
            $this->cleanup();
        }
    }

    /**
     * @return array{0: Site, 1: Site}
     */
    private function createTransferSites(Tenant $tenant): array
    {
        $fromGln = $this->uniqueGln();
        $toGln = $this->uniqueGln();

        $fromSite = Site::query()->create([
            'name' => 'Transfer From '.Str::random(6),
            'gln' => $fromGln,
            'is_active' => true,
            'is_headquarters' => true,
            'trading_partner_id' => null,
            'is_organization_facility' => true,
        ]);
        $this->siteIds[] = (int) $fromSite->getKey();

        $toSite = Site::query()->create([
            'name' => 'Transfer To '.Str::random(6),
            'gln' => $toGln,
            'is_active' => true,
            'is_headquarters' => false,
            'trading_partner_id' => null,
            'is_organization_facility' => true,
        ]);
        $this->siteIds[] = (int) $toSite->getKey();

        $settings = TenantSettings::forTenant($tenant);
        $this->priorDefaultShipFromSiteId = $settings->defaultShipFromSiteId();
        $this->priorDefaultReceiveSiteId = $settings->defaultReceiveSiteId();
        $settings->setDefaultShipFromSiteId((int) $fromSite->getKey());
        $settings->setDefaultReceiveSiteId((int) $toSite->getKey());
        $tenant->save();

        return [$fromSite, $toSite];
    }

    private function createOwnerUser(): User
    {
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);

        $user = User::factory()->create([
            'email' => 'transfer-floor-'.uniqid('', true).'@example.test',
        ]);
        $user->assignRole(TenantRole::Owner->value);

        return $user;
    }

    private function receiveAtSite(Site $site, Epc $epc): void
    {
        $document = EpcisDocument::query()->create([
            'document_uuid' => (string) Str::uuid(),
            'schema_version' => '1.2',
            'creation_date' => now(),
            'received_at' => now(),
            'direction' => 'outbound',
            'status' => 'parsed',
            'original_filename' => 'transfer-floor-custody-receipt.xml',
        ]);
        $this->custodyDocumentIds[] = (int) $document->getKey();

        $event = EpcisEvent::query()->create([
            'document_id' => $document->getKey(),
            'event_id' => 'urn:uuid:'.(string) Str::uuid(),
            'event_type' => 'ObjectEvent',
            'event_time' => now()->subMinute(),
            'record_time' => now()->subMinute(),
            'event_timezone_offset' => '+00:00',
            'action' => 'OBSERVE',
            'biz_step' => 'urn:epcglobal:cbv:bizstep:receiving',
            'disposition' => 'urn:epcglobal:cbv:disp:in_progress',
            'read_point_gln' => (string) $site->gln,
            'biz_location_gln' => (string) $site->gln,
        ]);
        $this->custodyEventIds[] = (int) $event->getKey();

        DB::table('event_epcs')->insertOrIgnore([[
            'event_id' => $event->getKey(),
            'epc_id' => $epc->getKey(),
            'role' => 'epcList',
        ]]);
    }

    private function uniqueGln(): string
    {
        $prefix = TenantSettings::forTenant(tenant())->companyPrefix() ?: '03';
        $fill = max(1, 12 - strlen($prefix));

        do {
            $body = substr($prefix.str_pad((string) random_int(0, (int) str_repeat('9', $fill)), $fill, '0', STR_PAD_LEFT), 0, 12);
            $gln = $body.Gtin::checkDigit($body);
        } while (Site::query()->where('gln', $gln)->exists());

        return $gln;
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
            if ($this->sessionId !== null) {
                TransferringScanLine::query()->where('transferring_session_id', $this->sessionId)->delete();
                TransferringSession::query()->whereKey($this->sessionId)->delete();
                $this->sessionId = null;
            }

            foreach ($this->custodyDocumentIds as $documentId) {
                DB::table('event_epcs')
                    ->whereIn('event_id', function ($query) use ($documentId): void {
                        $query->select('id')
                            ->from('epcis_events')
                            ->where('document_id', $documentId);
                    })
                    ->delete();
                DB::table('epcis_events')->where('document_id', $documentId)->delete();
                EpcisDocument::query()->whereKey($documentId)->delete();
            }
            $this->custodyDocumentIds = [];
            $this->custodyEventIds = [];

            if ($this->epcId !== null) {
                Epc::query()->whereKey($this->epcId)->delete();
                $this->epcId = null;
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

            tenancy()->end();
        }
    }
}
