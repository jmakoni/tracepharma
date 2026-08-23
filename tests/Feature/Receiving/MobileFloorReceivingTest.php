<?php

namespace Tests\Feature\Receiving;

use App\Actions\Epcis\IngestEpcisXmlDocument;
use App\Actions\Receiving\OpenReceivingSessionFromDocument;
use App\Actions\Receiving\OpenScanFirstReceivingSession;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Resources\ReceivingSessions\Pages\MobileViewReceivingSession;
use App\Filament\App\Resources\ReceivingSessions\Pages\ViewReceivingSession;
use App\Filament\App\Resources\ReceivingSessions\ReceivingSessionResource;
use App\Models\Epcis\AggregationLink;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Receiving\ReceivingScanLine;
use App\Models\Receiving\ReceivingSession;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\Receiving\ReceiveLayout;
use App\Support\Receiving\ReceivingPolicy;
use App\Support\Receiving\ReceivingScanLevel;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MobileFloorReceivingTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private const SSCC_URI = 'urn:epc:id:sscc:030116.01001227052';

    private const SGTIN_URI = 'urn:epc:id:sgtin:030116.0200116.10000082001560';

    private static bool $demo2TenantReady = false;

    private ?int $sessionId = null;

    private ?int $documentId = null;

    private ?int $epcId = null;

    private ?int $extraChildEpcId = null;

    private ?string $epcUri = null;

    #[Test]
    public function floor_page_is_registered_and_can_access(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $this->assertTrue(ReceivingSessionResource::canAccess());
            $this->assertArrayHasKey('floor', ReceivingSessionResource::getPages());

            $user = $this->createOwnerUser();
            $this->actingAs($user);

            $session = app(OpenScanFirstReceivingSession::class)->handle();
            $this->sessionId = (int) $session->getKey();

            $this->assertTrue(MobileViewReceivingSession::canAccess(['record' => $session->getKey()]));

            $floorUrl = ReceivingSessionResource::getUrl('floor', ['record' => $session], panel: 'app');
            $this->assertStringContainsString('/floor', $floorUrl);
            $this->assertSame(
                $floorUrl,
                ReceiveLayout::floorUrl($session),
            );
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function floor_page_mounts_for_demo2_session_and_confirm_scan_works(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $user = $this->createOwnerUser();
            $this->actingAs($user);

            $suffix = (string) random_int(10000000, 99999999);
            $uri = 'urn:epc:id:sgtin:030116.3'.substr($suffix, 0, 6).'.FL'.$suffix;
            $this->epcUri = $uri;

            $epc = Epc::query()->create(Epc::materializeAttributesFromUri($uri));
            $this->epcId = (int) $epc->getKey();

            $session = app(OpenScanFirstReceivingSession::class)->handle();
            $this->sessionId = (int) $session->getKey();

            $component = Livewire::test(MobileViewReceivingSession::class, ['record' => $session->getKey()])
                ->assertSuccessful()
                ->assertSeeHtml('id="floor-scan-input"')
                ->assertSeeHtml('tp-floor-receive__cart-fab')
                ->assertSeeHtml('tp-floor-receive__progress-stats')
                ->assertSeeHtml('stats-horizontal')
                ->assertSeeHtml('bg-base-200')
                ->assertSee($this->expectedParentProgressLabel())
                ->assertSee($this->expectedChildProgressLabel())
                ->assertDontSeeHtml('tp-floor-receive__site-chip')
                ->assertSeeHtml('tp-floor-receive__mode-chip')
                ->assertSee(ReceivingPolicy::forTenant(tenant())->edgeMode()->chipLabel())
                ->assertSee('Attach invoice')
                ->assertDontSee('0/0')
                ->assertSee('Complete Receive')
                ->assertSee('Scan at least one item to complete')
                ->assertSee('Back to receives')
                ->assertSee('Open desktop receive')
                ->assertSee('Scanned items will appear here')
                ->assertSee('Recent scans')
                ->assertDontSee('Tap to Scan')
                ->assertDontSee('Prefer floor layout')
                ->assertDontSee('Break hierarchy after receive')
                ->assertDontSee('Shortage')
                ->assertDontSee('Overage')
                ->assertDontSee('Damaged')
                ->assertSee('Staged scans')
                ->assertSee('Confirm staged')
                ->set('scan', $uri)
                ->call('stageScan')
                ->assertSet('stagedScans', [$uri])
                ->call('confirmStagedScans')
                ->assertSet('stagedScans', []);

            if (ReceivingPolicy::forTenant(tenant())->canUnpackAtReceive()) {
                $component->assertSee('Open cases after receive');
            }
            // Soft TI warning yields warn/ok; staged confirm must not fail hard for a valid scan-first EPC.
            $this->assertContains($component->get('lastScanTone'), ['ok', 'warn']);

            $session->refresh();
            $this->assertGreaterThan(0, (int) $session->confirmed_child_count + (int) $session->confirmed_parent_count);

            // Level-aware parent/child chips for the active tenant profile.
            $component->assertSee((string) (int) $session->confirmed_parent_count)
                ->assertSee((string) (int) $session->confirmed_child_count)
                ->assertSee($this->expectedParentProgressLabel())
                ->assertSee($this->expectedChildProgressLabel());
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function desktop_view_still_mounts_and_floor_blade_has_no_discrepancy_labels(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $user = $this->createOwnerUser();
            $this->actingAs($user);

            $session = app(OpenScanFirstReceivingSession::class)->handle();
            $this->sessionId = (int) $session->getKey();

            Livewire::test(ViewReceivingSession::class, ['record' => $session->getKey()])
                ->assertSuccessful()
                ->assertSee('Scan barcode')
                ->assertSee('Use floor view')
                ->assertSee($this->expectedParentProgressLabel())
                ->assertSee($this->expectedChildProgressLabel());

            $blade = File::get(resource_path(
                'views/filament/app/resources/receiving-sessions/pages/mobile-view-receiving-session.blade.php',
            ));

            foreach (['Shortage', 'Overage', 'Damaged'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $blade);
            }
            $this->assertStringContainsString('floor-scan-input', $blade);
            $this->assertStringContainsString('autofocus', $blade);
            $this->assertStringContainsString('Complete Receive', $blade);
            $this->assertStringContainsString('Open cases after receive', $blade);
            $this->assertStringContainsString('Back to receives', $blade);
            $this->assertStringContainsString('Open desktop receive', $blade);
            $this->assertStringContainsString('tp-floor-receive__cart-fab', $blade);
            $this->assertStringContainsString('tp-floor-receive__progress-stats', $blade);
            $this->assertStringNotContainsString('tp-floor-receive__site-chip', $blade);
            $this->assertStringContainsString('tp-floor-receive__mode-chip', $blade);
            $this->assertStringContainsString('Attach invoice', $blade);
            $this->assertStringContainsString('tp-floor-receive__sheet', $blade);
            $this->assertStringContainsString('tp-floor-receive__camera-overlay', $blade);
            $this->assertStringContainsString('tpFloorReceive(', $blade);
            $this->assertStringContainsString('tp-floor-receive.js', $blade);
            $this->assertStringContainsString('wire:model.live.blur="scan"', $blade);
            $this->assertStringContainsString('keydown.enter.prevent="$wire.stageScan($refs.scanInput.value)"', $blade);
            $this->assertStringContainsString('wire:submit.prevent="stageScan"', $blade);
            $this->assertStringContainsString('staged-scan-panel', $blade);
            $this->assertStringContainsString('confirmStagedScans', File::get(resource_path(
                'views/components/staged-scan-panel.blade.php',
            )));
            $this->assertStringContainsString('Confirm staged', File::get(resource_path(
                'views/components/staged-scan-panel.blade.php',
            )));
            $this->assertStringNotContainsString('wire:model="scan"', $blade);
            $this->assertStringNotContainsString('[tabindex=\\"-1\\"]', $blade);
            $this->assertStringNotContainsString('Tap to Scan', $blade);
            $this->assertStringNotContainsString('Break hierarchy after receive', $blade);
            $this->assertStringContainsString('receive-layout-switch', $blade);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function receive_layout_session_url_prefers_floor_when_cookie_set(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $session = app(OpenScanFirstReceivingSession::class)->handle();
            $this->sessionId = (int) $session->getKey();

            $desktop = ReceiveLayout::sessionUrl($session);
            $this->assertStringNotContainsString('/floor', parse_url($desktop, PHP_URL_PATH) ?? $desktop);

            request()->cookies->set(ReceiveLayout::COOKIE, ReceiveLayout::FLOOR);
            $floor = ReceiveLayout::sessionUrl($session);
            $this->assertStringContainsString('/floor', $floor);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function floor_pallet_scan_updates_units_progress_chip(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $user = $this->createOwnerUser();
            $this->actingAs($user);

            $document = $this->ingestMinimalFixture();
            $this->documentId = (int) $document->getKey();

            $parent = Epc::query()->where('epc_uri', self::SSCC_URI)->firstOrFail();
            $child = Epc::query()->where('epc_uri', self::SGTIN_URI)->firstOrFail();
            $existingLink = AggregationLink::query()
                ->where('parent_epc_id', $parent->getKey())
                ->where('child_epc_id', $child->getKey())
                ->whereNull('valid_to')
                ->firstOrFail();

            $extraUri = 'urn:epc:id:sgtin:030116.0200116.'.(string) random_int(90000082000000, 99999982999999);
            $extraChild = Epc::query()->firstOrCreate(
                ['epc_uri' => $extraUri],
                Epc::materializeAttributesFromUri($extraUri),
            );
            $this->extraChildEpcId = (int) $extraChild->getKey();

            AggregationLink::query()->firstOrCreate(
                [
                    'parent_epc_id' => $parent->getKey(),
                    'child_epc_id' => $extraChild->getKey(),
                    'established_by_event_id' => $existingLink->established_by_event_id,
                ],
                [
                    'link_type' => 'aggregation',
                    'valid_from' => now(),
                    'valid_to' => null,
                ],
            );

            $session = app(OpenReceivingSessionFromDocument::class)->handle($document);
            $this->sessionId = (int) $session->getKey();

            $component = Livewire::test(MobileViewReceivingSession::class, ['record' => $session->getKey()])
                ->assertSuccessful()
                ->assertSeeHtml('tp-floor-receive__progress-stats')
                ->assertSee($this->expectedParentProgressLabel())
                ->assertSee($this->expectedChildProgressLabel())
                ->assertSee('0/0')
                ->set('autoConfirmChildren', false)
                ->set('scan', self::SSCC_URI)
                ->callAction('confirmScan')
                ->assertHasNoActionErrors();

            $this->assertContains($component->get('lastScanTone'), ['ok', 'warn']);
            $this->assertTrue($component->get('autoConfirmChildren'));

            $session->refresh();
            $this->assertSame(1, (int) $session->confirmed_parent_count);
            $this->assertGreaterThan(1, (int) $session->expected_child_count);
            $this->assertSame(
                (int) $session->expected_child_count,
                (int) $session->confirmed_child_count,
            );

            // ASN: ratio under profile-aware parent/child titles.
            $unitsQty = $session->confirmed_child_count.'/'.$session->expected_child_count;
            $component->assertSee('1/1')
                ->assertSee($unitsQty)
                ->assertSee($this->expectedParentProgressLabel())
                ->assertSee($this->expectedChildProgressLabel())
                ->assertDontSeeHtml('tp-floor-receive__site-chip');
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function floor_stages_two_barcodes_then_batch_confirms(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $user = $this->createOwnerUser();
            $this->actingAs($user);

            $uriA = 'urn:epc:id:sgtin:030116.3'.substr((string) random_int(100000, 999999), 0, 6).'.SA'.random_int(10000000, 99999999);
            $uriB = 'urn:epc:id:sgtin:030116.3'.substr((string) random_int(100000, 999999), 0, 6).'.SB'.random_int(10000000, 99999999);

            $epcA = Epc::query()->create(Epc::materializeAttributesFromUri($uriA));
            $epcB = Epc::query()->create(Epc::materializeAttributesFromUri($uriB));
            $this->epcId = (int) $epcA->getKey();
            $this->extraChildEpcId = (int) $epcB->getKey();

            $session = app(OpenScanFirstReceivingSession::class)->handle();
            $this->sessionId = (int) $session->getKey();

            $component = Livewire::test(MobileViewReceivingSession::class, ['record' => $session->getKey()])
                ->assertSuccessful()
                ->call('stageScan', $uriA)
                ->call('stageScan', $uriB)
                ->assertSet('stagedScans', [$uriA, $uriB])
                ->call('confirmStagedScans')
                ->assertSet('stagedScans', []);

            $this->assertContains($component->get('lastScanTone'), ['ok', 'warn']);

            $session->refresh();
            $this->assertGreaterThanOrEqual(2, (int) $session->confirmed_child_count + (int) $session->confirmed_parent_count);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function floor_keeps_failed_barcode_in_staged_queue(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $user = $this->createOwnerUser();
            $this->actingAs($user);

            $goodUri = 'urn:epc:id:sgtin:030116.3'.substr((string) random_int(100000, 999999), 0, 6).'.SG'.random_int(10000000, 99999999);
            $badUri = 'INVALID-BARCODE-NOT-GS1-'.random_int(100000, 999999);

            $epc = Epc::query()->create(Epc::materializeAttributesFromUri($goodUri));
            $this->epcId = (int) $epc->getKey();

            $session = app(OpenScanFirstReceivingSession::class)->handle();
            $this->sessionId = (int) $session->getKey();

            $component = Livewire::test(MobileViewReceivingSession::class, ['record' => $session->getKey()])
                ->assertSuccessful()
                ->call('stageScan', $goodUri)
                ->call('stageScan', $badUri)
                ->assertCount('stagedScans', 2)
                ->call('confirmStagedScans');

            $staged = $component->get('stagedScans');
            $this->assertIsArray($staged);
            $this->assertContains($badUri, $staged);
            $this->assertNotContains($goodUri, $staged);
            $this->assertSame('error', $component->get('lastScanTone'));

            $session->refresh();
            $this->assertGreaterThan(0, (int) $session->confirmed_child_count + (int) $session->confirmed_parent_count);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function floor_hardware_scan_enter_stages_dom_value_without_wire_property(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $user = $this->createOwnerUser();
            $this->actingAs($user);

            $uri = 'urn:epc:id:sgtin:030116.3'.substr((string) random_int(100000, 999999), 0, 6).'.HW'.random_int(10000000, 99999999);

            $epc = Epc::query()->create(Epc::materializeAttributesFromUri($uri));
            $this->epcId = (int) $epc->getKey();

            $session = app(OpenScanFirstReceivingSession::class)->handle();
            $this->sessionId = (int) $session->getKey();

            Livewire::test(MobileViewReceivingSession::class, ['record' => $session->getKey()])
                ->assertSuccessful()
                ->assertSet('scan', '')
                ->call('stageScan', $uri)
                ->assertSet('stagedScans', [$uri])
                ->assertSet('scan', '');
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function desktop_view_still_confirms_immediately_without_staging(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $user = $this->createOwnerUser();
            $this->actingAs($user);

            $uri = 'urn:epc:id:sgtin:030116.3'.substr((string) random_int(100000, 999999), 0, 6).'.DT'.random_int(10000000, 99999999);
            $epc = Epc::query()->create(Epc::materializeAttributesFromUri($uri));
            $this->epcId = (int) $epc->getKey();

            $session = app(OpenScanFirstReceivingSession::class)->handle();
            $this->sessionId = (int) $session->getKey();

            $component = Livewire::test(ViewReceivingSession::class, ['record' => $session->getKey()])
                ->assertSuccessful()
                ->assertSet('stagedScans', [])
                ->set('scan', $uri)
                ->callAction('confirmScan');

            $this->assertContains($component->get('lastScanTone'), ['ok', 'warn']);
            $this->assertSame([], $component->get('stagedScans'));

            $session->refresh();
            $this->assertGreaterThan(0, (int) $session->confirmed_child_count + (int) $session->confirmed_parent_count);
        } finally {
            $this->cleanup();
        }
    }

    private function ingestMinimalFixture(): EpcisDocument
    {
        $fixture = base_path('tests/Fixtures/epcis/minimal_object_shipping.xml');
        $this->assertFileExists($fixture);

        $tmp = tempnam(sys_get_temp_dir(), 'epcis_');
        $this->assertNotFalse($tmp);
        $xml = file_get_contents($fixture);
        $this->assertNotFalse($xml);
        $uuid = (string) str()->uuid();
        $xml = str_replace('11111111-2222-3333-4444-555555555555', $uuid, $xml);
        file_put_contents($tmp, $xml);

        try {
            return app(IngestEpcisXmlDocument::class)->handle($tmp, [
                'direction' => 'inbound',
                'original_filename' => 'minimal_object_shipping.xml',
            ]);
        } finally {
            @unlink($tmp);
        }
    }

    private function expectedParentProgressLabel(): string
    {
        return match (ReceivingPolicy::forTenant(tenant())->preferredScanLevel()) {
            ReceivingScanLevel::Pallet => 'Pallets',
            ReceivingScanLevel::Case, ReceivingScanLevel::ToteOrCase => 'Cases',
        };
    }

    private function expectedChildProgressLabel(): string
    {
        return match (ReceivingPolicy::forTenant(tenant())->preferredScanLevel()) {
            ReceivingScanLevel::Pallet => 'Cases',
            ReceivingScanLevel::Case, ReceivingScanLevel::ToteOrCase => 'Units',
        };
    }

    private function createOwnerUser(): User
    {
        $profile = tenant()?->profile instanceof TenantProfile
            ? tenant()->profile
            : TenantProfile::Pharmacy;

        app(TenantRoleSeeder::class)->seedForProfile($profile);

        $user = User::factory()->create([
            'email' => 'floor-'.uniqid('', true).'@example.test',
        ]);
        $user->assignRole(TenantRole::Owner->value);

        return $user;
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
                ReceivingScanLine::query()->where('receiving_session_id', $this->sessionId)->delete();
                ReceivingSession::query()->whereKey($this->sessionId)->delete();
                $this->sessionId = null;
            }

            if ($this->documentId !== null) {
                $documentId = $this->documentId;
                ReceivingSession::query()->where('epcis_document_id', $documentId)->delete();
                AggregationLink::query()
                    ->whereIn('established_by_event_id', function ($query) use ($documentId): void {
                        $query->select('id')
                            ->from('epcis_events')
                            ->where('document_id', $documentId);
                    })
                    ->delete();
                DB::table('event_epcs')
                    ->whereIn('event_id', function ($query) use ($documentId): void {
                        $query->select('id')
                            ->from('epcis_events')
                            ->where('document_id', $documentId);
                    })
                    ->delete();
                DB::table('epcis_events')->where('document_id', $documentId)->delete();
                EpcisDocument::query()->whereKey($documentId)->delete();
                $this->documentId = null;
            }

            if ($this->extraChildEpcId !== null) {
                AggregationLink::query()->where('child_epc_id', $this->extraChildEpcId)->delete();
                $epc = Epc::query()->find($this->extraChildEpcId);
                if ($epc !== null && ! DB::table('event_epcs')->where('epc_id', $epc->id)->exists()) {
                    $epc->delete();
                }
                $this->extraChildEpcId = null;
            }

            if ($this->epcId !== null) {
                Epc::query()->whereKey($this->epcId)->delete();
                $this->epcId = null;
            }

            $this->epcUri = null;

            tenancy()->end();
        }
    }
}
