<?php

namespace Tests\Feature\Receiving;

use App\Actions\Epcis\IngestEpcisXmlDocument;
use App\Actions\Receiving\OpenReceivingSessionFromDocument;
use App\Actions\Receiving\OpenScanFirstReceivingSession;
use App\Enums\ReceivingSessionKind;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Enums\TracingRequestorType;
use App\Enums\TracingRequestScope;
use App\Enums\TracingRequestStatus;
use App\Filament\App\Pages\ScanInWorkstation;
use App\Filament\App\Resources\ReceivingSessions\ReceivingSessionResource;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcIlmd;
use App\Models\Epcis\EpcisDocument;
use App\Models\Receiving\ReceivingScanLine;
use App\Models\Receiving\ReceivingSession;
use App\Models\Tenant;
use App\Models\TracingRequest;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\Receiving\EligibleReceiveSites;
use App\Support\Receiving\ReceivingPolicy;
use App\Support\TenantFeatures;
use App\Support\TenantSettings;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\PreparesDemo2ReceivingState;
use Tests\TestCase;

class ScanInWorkstationTest extends TestCase
{
    use PreparesDemo2ReceivingState;

    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $sessionIds = [];

    /** @var list<int> */
    private array $epcIds = [];

    /** @var list<int> */
    private array $requestIds = [];

    /** @var list<int> */
    private array $userIds = [];

    private ?bool $priorRequireTi = null;

    #[Test]
    public function page_is_visible_when_receiving_supported(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->actingAs($this->createOwnerUser());

            $this->assertTrue(TenantFeatures::forTenant(tenant())->supportsReceiving());
            $this->assertTrue(ScanInWorkstation::canAccess());
            $this->assertTrue(ScanInWorkstation::shouldRegisterNavigation());
            $this->assertSame('Scan In', ScanInWorkstation::getNavigationLabel());
            $this->assertSame('Receiving', ScanInWorkstation::getNavigationGroup());
            $this->assertSame('scan-in', ScanInWorkstation::getSlug());
            $this->assertSame(2, ScanInWorkstation::getNavigationSort());
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function picker_renders_without_session_and_does_not_use_receive_view_url(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            $this->actingAs($this->createOwnerUser());

            Livewire::test(ScanInWorkstation::class)
                ->assertSuccessful()
                ->assertSee('Start scan-first')
                ->assertDontSee('Scan barcode')
                ->assertDontSee('Attach invoice');

            $this->assertStringContainsString('scan-in', ScanInWorkstation::getUrl(panel: 'app'));
            $this->assertStringNotContainsString(
                'receiving-sessions/',
                ScanInWorkstation::getUrl(panel: 'app'),
            );
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function start_scan_first_stays_on_scan_in(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            $this->actingAs($this->createOwnerUser());

            $beforeIds = ReceivingSession::query()->pluck('id')->all();

            $component = Livewire::test(ScanInWorkstation::class)
                ->callAction('startScanFirst')
                ->assertHasNoActionErrors()
                ->assertNoRedirect();

            $session = ReceivingSession::query()
                ->whereNotIn('id', $beforeIds)
                ->latest('id')
                ->first();

            $this->assertNotNull($session);
            $this->sessionIds[] = (int) $session->getKey();
            $this->assertSame(ReceivingSessionKind::ScanFirst, $session->session_kind);
            $this->assertSame((int) $session->getKey(), (int) $component->get('sessionId'));

            $viewUrl = ReceivingSessionResource::getUrl('view', ['record' => $session], panel: 'app');
            $this->assertStringContainsString('receiving-sessions/'.$session->getKey(), $viewUrl);
            $this->assertStringContainsString(
                'session='.$session->getKey(),
                ScanInWorkstation::urlForSession((int) $session->getKey()),
            );
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function open_session_renders_desktop_scan_field(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            $this->actingAs($this->createOwnerUser());

            $session = app(OpenScanFirstReceivingSession::class)->handle();
            $this->sessionIds[] = (int) $session->getKey();

            Livewire::test(ScanInWorkstation::class, ['sessionId' => $session->getKey()])
                ->assertSuccessful()
                ->assertSee('Scan barcode')
                ->assertSee(ReceivingPolicy::forTenant(tenant())->edgeMode()->chipLabel())
                ->assertSee('Scan-first')
                ->assertDontSee('Attach invoice');

            $blade = File::get(resource_path('views/filament/app/pages/scan-in-workstation.blade.php'));
            $this->assertStringContainsString('scan-field', $blade);
            $this->assertStringContainsString('show-camera="false"', $blade);
            $this->assertStringContainsString('submit-action="confirmScan"', $blade);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function confirm_scan_creates_confirmed_line(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            $this->actingAs($this->createOwnerUser());

            $suffix = (string) random_int(10000000, 99999999);
            $uri = 'urn:epc:id:sgtin:030116.3'.substr($suffix, 0, 6).'.SI'.$suffix;
            $epc = Epc::query()->create(Epc::materializeAttributesFromUri($uri));
            $this->epcIds[] = (int) $epc->getKey();

            $session = app(OpenScanFirstReceivingSession::class)->handle();
            $this->sessionIds[] = (int) $session->getKey();

            Livewire::test(ScanInWorkstation::class, ['sessionId' => $session->getKey()])
                ->set('scan', $uri)
                ->callAction('confirmScan')
                ->assertHasNoActionErrors();

            $line = ReceivingScanLine::query()
                ->where('receiving_session_id', $session->getKey())
                ->where('epc_id', $epc->getKey())
                ->first();

            $this->assertNotNull($line);
            $this->assertSame('confirmed', $line->status);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function open_recall_blocks_confirm_on_scan_in_only(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            $this->actingAs($this->createOwnerUser());

            $suffix = (string) random_int(10000000, 99999999);
            $uri = 'urn:epc:id:sgtin:030116.3'.substr($suffix, 0, 6).'.RL'.$suffix;
            $epc = Epc::query()->create(Epc::materializeAttributesFromUri($uri));
            $this->epcIds[] = (int) $epc->getKey();
            EpcIlmd::query()->create([
                'epc_id' => $epc->getKey(),
                'gtin14' => $epc->gtin14,
                'lot_number' => 'LOT-SCAN-IN',
            ]);

            $request = TracingRequest::query()->create([
                'title' => 'Scan In recall',
                'status' => TracingRequestStatus::Open,
                'requestor_type' => TracingRequestorType::Internal,
                'scope' => TracingRequestScope::Lot,
                'gtin' => $epc->gtin14,
                'lot' => 'LOT-SCAN-IN',
                'is_recall' => true,
                'requested_at' => now(),
            ]);
            $this->requestIds[] = (int) $request->getKey();

            $session = app(OpenScanFirstReceivingSession::class)->handle();
            $this->sessionIds[] = (int) $session->getKey();

            $component = Livewire::test(ScanInWorkstation::class, ['sessionId' => $session->getKey()])
                ->set('scan', $uri)
                ->callAction('confirmScan');

            $this->assertStringContainsString('Open recall', (string) $component->get('lastScanMessage'));
            $this->assertSame(0, ReceivingScanLine::query()
                ->where('receiving_session_id', $session->getKey())
                ->where('epc_id', $epc->getKey())
                ->count());
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function lot_level_scan_on_scan_first_asks_for_asn_or_2d(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            $this->actingAs($this->createOwnerUser());

            $session = app(OpenScanFirstReceivingSession::class)->handle();
            $this->sessionIds[] = (int) $session->getKey();

            $component = Livewire::test(ScanInWorkstation::class, ['sessionId' => $session->getKey()])
                ->set('scan', '(01)00301163001167(10)LOT1')
                ->callAction('confirmScan');

            $this->assertStringContainsString(
                'Lot-level scan',
                (string) $component->get('lastScanMessage'),
            );
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function sscc_lists_cases_ticks_on_scan_and_complete_receives_only_confirmed(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            $this->actingAs($this->createOwnerUser());

            $ingested = $this->ingestUniqueMinimalShippingFixture();
            $siteId = $this->eligibleReceiveSiteId();
            $this->assertNotNull($siteId, 'Demo2 needs an eligible receive site.');
            $session = app(OpenReceivingSessionFromDocument::class)->handle($ingested['document'], $siteId);
            $this->sessionIds[] = (int) $session->getKey();

            $parent = Epc::query()->where('epc_uri', $ingested['sscc_uri'])->firstOrFail();
            $scannedChild = Epc::query()->where('epc_uri', $ingested['sgtin_uri'])->firstOrFail();
            $this->epcIds[] = (int) $parent->getKey();
            $this->epcIds[] = (int) $scannedChild->getKey();
            $leftover = $this->createSgtinEpc();
            ReceivingScanLine::query()->create([
                'receiving_session_id' => $session->getKey(),
                'epc_id' => $leftover->getKey(),
                'parent_epc_id' => $parent->getKey(),
                'line_role' => 'child',
                'status' => 'expected',
                'scan_raw' => $leftover->epc_uri,
            ]);

            $component = Livewire::test(ScanInWorkstation::class, ['sessionId' => $session->getKey()])
                ->set('scan', $parent->epc_uri)
                ->callAction('confirmScan')
                ->assertHasNoActionErrors();

            $this->assertSame('confirmed', ReceivingScanLine::query()
                ->where('receiving_session_id', $session->getKey())
                ->where('epc_id', $parent->getKey())
                ->value('status'));
            $this->assertSame('expected', ReceivingScanLine::query()
                ->where('receiving_session_id', $session->getKey())
                ->where('epc_id', $scannedChild->getKey())
                ->value('status'));

            $serials = $component->instance()->caseRows()->pluck('serial')->all();
            $this->assertContains($scannedChild->serial_number, $serials);
            $this->assertContains($leftover->serial_number, $serials);
            $this->assertFalse($component->instance()->caseRows()->firstWhere('serial', $scannedChild->serial_number)['confirmed']);

            $component->set('scan', $scannedChild->epc_uri)->callAction('confirmScan');
            $this->assertTrue($component->instance()->caseRows()->firstWhere('serial', $scannedChild->serial_number)['confirmed']);

            $scannedLineId = (int) ReceivingScanLine::query()
                ->where('receiving_session_id', $session->getKey())
                ->where('epc_id', $scannedChild->getKey())
                ->value('id');
            $component->call('removeCase', $scannedLineId);
            $this->assertFalse($component->instance()->caseRows()->firstWhere('serial', $scannedChild->serial_number)['confirmed']);

            $component->set('scan', $scannedChild->epc_uri)
                ->callAction('confirmScan')
                ->assertHasNoActionErrors();
            $component->callAction('completeReceiving')
                ->assertHasNoActionErrors();

            $this->assertSame('confirmed', ReceivingScanLine::query()
                ->where('receiving_session_id', $session->getKey())
                ->where('epc_id', $scannedChild->getKey())
                ->value('status'));
            $this->assertSame('expected', ReceivingScanLine::query()
                ->where('receiving_session_id', $session->getKey())
                ->where('epc_id', $leftover->getKey())
                ->value('status'));
            $session = $session->fresh();
            $this->assertSame('completed', $session->status, (string) $component->get('lastScanMessage'));
            $this->assertNotNull($session->receiving_epcis_document_id);

            $receivedEpcIds = DB::table('event_epcs')
                ->whereIn('event_id', function ($query) use ($session): void {
                    $query->select('id')
                        ->from('epcis_events')
                        ->where('document_id', $session->receiving_epcis_document_id);
                })
                ->pluck('epc_id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            $this->assertContains((int) $scannedChild->getKey(), $receivedEpcIds);
            $this->assertNotContains((int) $leftover->getKey(), $receivedEpcIds);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function complete_clears_session_when_no_next_inbound(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            $this->actingAs($this->createOwnerUser());

            $siteId = $this->eligibleReceiveSiteId();
            $this->assertNotNull($siteId, 'Demo2 needs an eligible receive site.');
            $session = app(OpenScanFirstReceivingSession::class)->handle($siteId);
            $this->sessionIds[] = (int) $session->getKey();
            $this->assertNotNull($session->fresh()?->site_id);

            ReceivingSession::query()
                ->whereIn('status', ['open', 'in_progress'])
                ->whereKeyNot($session->getKey())
                ->pluck('id')
                ->each(function ($id): void {
                    $this->deleteReceivingSessionForIsolation((int) $id);
                });

            $suffix = (string) random_int(10000000, 99999999);
            $uri = 'urn:epc:id:sgtin:030116.3'.substr($suffix, 0, 6).'.CL'.$suffix;
            $epc = Epc::query()->create(Epc::materializeAttributesFromUri($uri));
            $this->epcIds[] = (int) $epc->getKey();

            $component = Livewire::test(ScanInWorkstation::class, ['sessionId' => $session->getKey()])
                ->set('scan', $uri)
                ->callAction('confirmScan')
                ->assertHasNoActionErrors();

            $this->assertContains($component->get('lastScanTone'), ['ok', 'warn'], (string) $component->get('lastScanMessage'));
            $this->assertSame('confirmed', ReceivingScanLine::query()
                ->where('receiving_session_id', $session->getKey())
                ->where('epc_id', $epc->getKey())
                ->value('status'));

            $component->callAction('completeReceiving')
                ->assertSet('sessionId', null)
                ->assertSee('startScanFirst')
                ->assertSee('No open receive sessions')
                ->assertDontSee('Scan barcode');
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function complete_opens_next_inbound_on_scan_in(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            $this->actingAs($this->createOwnerUser());

            $siteId = $this->eligibleReceiveSiteId();
            $this->assertNotNull($siteId, 'Demo2 needs an eligible receive site.');
            $first = app(OpenScanFirstReceivingSession::class)->handle($siteId);
            $second = app(OpenScanFirstReceivingSession::class)->handle($siteId);
            $this->sessionIds[] = (int) $first->getKey();
            $this->sessionIds[] = (int) $second->getKey();
            $this->assertNotNull($first->fresh()?->site_id);
            $this->assertNotNull($second->fresh()?->site_id);

            $suffix = (string) random_int(10000000, 99999999);
            $uri = 'urn:epc:id:sgtin:030116.3'.substr($suffix, 0, 6).'.NQ'.$suffix;
            $epc = Epc::query()->create(Epc::materializeAttributesFromUri($uri));
            $this->epcIds[] = (int) $epc->getKey();

            $component = Livewire::test(ScanInWorkstation::class)
                ->call('selectSession', $first->getKey())
                ->assertSet('sessionId', (int) $first->getKey())
                ->set('scan', $uri)
                ->callAction('confirmScan')
                ->assertHasNoActionErrors();

            $this->assertContains($component->get('lastScanTone'), ['ok', 'warn'], (string) $component->get('lastScanMessage'));

            $component->callAction('completeReceiving')
                ->assertSet('sessionId', (int) $second->getKey());
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function picker_lists_open_inbound_session(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            $this->actingAs($this->createOwnerUser());

            $document = $this->demo2ReceivableDocument();
            $session = app(OpenReceivingSessionFromDocument::class)->handle($document);
            $this->sessionIds[] = (int) $session->getKey();

            Livewire::test(ScanInWorkstation::class)
                ->assertSee('#'.$session->getKey());
        } finally {
            $this->cleanup();
        }
    }

    /**
     * @return array{document: EpcisDocument, sscc_uri: string, sgtin_uri: string}
     */
    private function ingestUniqueMinimalShippingFixture(): array
    {
        $fixture = base_path('tests/Fixtures/epcis/minimal_object_shipping.xml');
        $this->assertFileExists($fixture);

        do {
            $ssccUri = 'urn:epc:id:sscc:030116.0'.str_pad((string) random_int(0, 9_999_999_999), 10, '0', STR_PAD_LEFT);
        } while (Epc::query()->where('epc_uri', $ssccUri)->exists());

        do {
            $sgtinUri = 'urn:epc:id:sgtin:030116.0200116.'.(string) random_int(10_000_000_000_000, 99_999_999_999_999);
        } while (Epc::query()->where('epc_uri', $sgtinUri)->exists());

        $tmp = tempnam(sys_get_temp_dir(), 'epcis_');
        $this->assertNotFalse($tmp);
        $xml = file_get_contents($fixture);
        $this->assertNotFalse($xml);
        $xml = str_replace(
            [
                '11111111-2222-3333-4444-555555555555',
                'urn:epc:id:sscc:030116.01001227052',
                'urn:epc:id:sgtin:030116.0200116.10000082001560',
            ],
            [(string) Str::uuid(), $ssccUri, $sgtinUri],
            $xml,
        );
        file_put_contents($tmp, $xml);

        try {
            $document = app(IngestEpcisXmlDocument::class)->handle($tmp, [
                'direction' => 'inbound',
                'original_filename' => 'minimal_object_shipping.xml',
            ]);
        } finally {
            @unlink($tmp);
        }

        return [
            'document' => $document,
            'sscc_uri' => $ssccUri,
            'sgtin_uri' => $sgtinUri,
        ];
    }

    private function eligibleReceiveSiteId(): ?int
    {
        $sites = EligibleReceiveSites::organizationOptions();

        return $sites === [] ? null : (int) array_key_first($sites);
    }

    private function createSgtinEpc(): Epc
    {
        do {
            $serial = (string) random_int(10_000_000_000_000, 99_999_999_999_999);
            $uri = 'urn:epc:id:sgtin:030116.0200116.'.$serial;
        } while (Epc::query()->where('epc_uri', $uri)->exists());

        $epc = Epc::query()->create(Epc::materializeAttributesFromUri($uri));
        $this->epcIds[] = (int) $epc->getKey();

        return $epc;
    }

    private function createOwnerUser(): User
    {
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);

        $user = User::factory()->create([
            'email' => 'scan-in-'.Str::uuid().'@example.test',
        ]);
        $user->assignRole(TenantRole::Owner->value);
        $this->userIds[] = (int) $user->getKey();

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

        $this->prepareDemo2ReceivingState();

        $this->priorRequireTi = TenantSettings::forTenant($tenant)->requireTiForScanFirst();
        TenantSettings::forTenant($tenant)->setRequireTiForScanFirst(false);
        $tenant->save();

        return $tenant;
    }

    private function demo2ReceivableDocument(): EpcisDocument
    {
        $requireValidated = (bool) config('tracepharma.epcis.require_validated_for_receiving', true);
        $statuses = $requireValidated ? ['validated'] : ['parsed', 'validated'];

        $document = EpcisDocument::query()
            ->whereIn('status', $statuses)
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($document, 'Demo2 needs a validated inbound EPCIS document.');

        return $document;
    }

    private function cleanup(): void
    {
        if (tenancy()->initialized) {
            $tenant = tenant();
            if ($this->priorRequireTi !== null && $tenant !== null) {
                TenantSettings::forTenant($tenant)->setRequireTiForScanFirst($this->priorRequireTi);
                $tenant->save();
                $this->priorRequireTi = null;
            }

            foreach ($this->requestIds as $requestId) {
                TracingRequest::query()->whereKey($requestId)->delete();
            }
            $this->requestIds = [];

            foreach ($this->sessionIds as $sessionId) {
                $this->deleteReceivingSessionForIsolation($sessionId);
            }
            $this->sessionIds = [];

            foreach ($this->epcIds as $epcId) {
                ReceivingScanLine::query()->where('epc_id', $epcId)->delete();
                EpcIlmd::query()->where('epc_id', $epcId)->delete();
                if (! DB::table('event_epcs')->where('epc_id', $epcId)->exists()
                    && ! DB::table('document_epcs')->where('epc_id', $epcId)->exists()) {
                    Epc::query()->whereKey($epcId)->delete();
                }
            }
            $this->epcIds = [];

            foreach ($this->userIds as $userId) {
                User::query()->whereKey($userId)->delete();
            }
            $this->userIds = [];

            tenancy()->end();
        }
    }
}
