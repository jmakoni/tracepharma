<?php

namespace Tests\Feature\Transferring;

use App\Actions\Transferring\ConfirmTransferringScan;
use App\Actions\Transferring\OpenTransferringSession;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Pages\OperationsHub;
use App\Filament\App\Resources\ReceivingSessions\Pages\ViewReceivingSession;
use App\Filament\App\Resources\ReceivingSessions\ReceivingSessionResource;
use App\Filament\App\Resources\TransferringSessions\Pages\CreateTransferringSession;
use App\Filament\App\Resources\TransferringSessions\Pages\ListTransferringSessions;
use App\Filament\App\Resources\TransferringSessions\Pages\ViewTransferringSession;
use App\Filament\App\Resources\TransferringSessions\RelationManagers\ScanLinesRelationManager;
use App\Filament\App\Resources\TransferringSessions\Tables\TransferringSessionsTable;
use App\Filament\App\Resources\TransferringSessions\TransferringSessionResource;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Quarantine\QuarantineHold;
use App\Models\Receiving\ReceivingSession;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\Transferring\TransferringScanLine;
use App\Models\Transferring\TransferringSession;
use App\Models\User;
use App\Support\Auth\CurrentSite;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\Gs1\Gtin;
use App\Support\Receiving\EligibleReceiveSites;
use App\Support\TenantFeatures;
use App\Support\TenantSettings;
use App\Support\Tracing\AssetTrackingUrl;
use Filament\Facades\Filament;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

class TransferringSessionResourceTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    /** Distinct from TransferringSessionTest EPC_URI / EPC_URI_2 to avoid shared demo2 collisions. */
    private const EPC_URI = 'urn:epc:id:sgtin:030116.0200116.90000082007777';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $siteIds = [];

    private ?int $sessionId = null;

    private ?int $receivingSessionId = null;

    private ?int $epcId = null;

    private ?int $transferDocumentId = null;

    /** @var list<int> */
    private array $custodyDocumentIds = [];

    /** @var list<int> */
    private array $custodyEventIds = [];

    private ?int $priorDefaultShipFromSiteId = null;

    private ?int $priorDefaultReceiveSiteId = null;

    private ?string $priorCompanyPrefix = null;

    #[Test]
    public function pharmacy_can_access_and_create_transferring_session_resource(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->assertTrue(TenantFeatures::forTenant(tenant())->supportsTransferring());
            $this->assertTrue(TransferringSessionResource::canAccess());
            $this->assertTrue(TransferringSessionResource::canCreate());
            $this->assertSame('transferring-sessions', TransferringSessionResource::getSlug());
            $this->assertSame('Transfer', TransferringSessionResource::getNavigationLabel());
            $this->assertContains(ScanLinesRelationManager::class, TransferringSessionResource::getRelations());

            $pages = TransferringSessionResource::getPages();
            $this->assertArrayHasKey('index', $pages);
            $this->assertArrayHasKey('create', $pages);
            $this->assertArrayHasKey('view', $pages);
        } finally {
            tenancy()->end();
        }
    }

    #[Test]
    public function create_is_ungated_while_ship_transfer_requires_confirmation_when_gate_enabled(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            config(['tracepharma.regulatory_compliance.password_gate' => true]);
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $user = $this->createOrgAdminUser();
            $this->actingAs($user);

            [$fromSite, $toSite] = $this->createTransferSites($tenant);

            $createPage = Livewire::test(CreateTransferringSession::class);
            $createAction = (new ReflectionMethod(CreateTransferringSession::class, 'getCreateFormAction'))
                ->invoke($createPage->instance());

            $this->assertFalse(
                $createAction->isConfirmationRequired(),
                'Opening a transfer session must not require regulatory password confirmation.',
            );

            $session = app(OpenTransferringSession::class)->handle(
                fromSiteId: (int) $fromSite->getKey(),
                toSiteId: (int) $toSite->getKey(),
                openedBy: (int) $user->getKey(),
            );
            $this->sessionId = (int) $session->getKey();

            $view = Livewire::test(ViewTransferringSession::class, ['record' => $session->getKey()]);

            $confirmScan = $view->instance()->confirmScanAction();
            $this->assertFalse(
                $confirmScan->isConfirmationRequired(),
                'Per-scan Confirm must not require regulatory password confirmation.',
            );

            $ship = $view->instance()->getAction('completeTransfer');
            $this->assertTrue(
                $ship->isConfirmationRequired(),
                'Ship transfer must require confirmation when submitting scanned data.',
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function complete_transfer_hidden_when_user_lacks_from_site_access(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);

            [$fromSite, $toSite] = $this->createTransferSites($tenant);

            $owner = User::factory()->create();
            $owner->assignRole(TenantRole::Owner->value);

            $session = app(OpenTransferringSession::class)->handle(
                fromSiteId: (int) $fromSite->getKey(),
                toSiteId: (int) $toSite->getKey(),
                openedBy: (int) $owner->getKey(),
            );
            $this->sessionId = (int) $session->getKey();

            $restricted = User::factory()->create();
            $restricted->syncSites([(int) $toSite->getKey()]);
            $this->actingAs($restricted);

            $view = Livewire::test(ViewTransferringSession::class, ['record' => $session->getKey()]);
            $view->assertActionHidden('completeTransfer');
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function scan_line_sscc_and_gtin_columns_link_to_asset_tracking(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $user = $this->createOrgAdminUser();
            $this->actingAs($user);

            [$fromSite, $toSite] = $this->createTransferSites($tenant);
            $session = app(OpenTransferringSession::class)->handle(
                fromSiteId: (int) $fromSite->getKey(),
                toSiteId: (int) $toSite->getKey(),
                openedBy: (int) $user->getKey(),
            );
            $this->sessionId = (int) $session->getKey();

            $epc = Epc::query()->create(Epc::materializeAttributesFromUri(self::EPC_URI));
            $this->epcId = (int) $epc->getKey();
            $this->receiveAtSite($fromSite, $epc);
            app(ConfirmTransferringScan::class)->handle($session, self::EPC_URI, (int) $user->getKey());

            $line = $session->scanLines()->with('epc')->first();
            $this->assertNotNull($line);
            $this->assertNotNull($line->epc);

            $expected = AssetTrackingUrl::forEpc($line->epc);
            $this->assertNotNull($expected);
            $this->assertStringContainsString('asset-tracking', $expected);
            $this->assertStringContainsString('scan=', $expected);

            $component = Livewire::test(ScanLinesRelationManager::class, [
                'ownerRecord' => $session,
                'pageClass' => ViewTransferringSession::class,
            ]);

            $columns = collect($component->instance()->getTable()->getColumns());
            foreach (['epc.sscc18', 'epc.gtin14', 'epc.serial_number', 'epc.epc_uri'] as $name) {
                $column = $columns->first(fn ($c) => $c->getName() === $name);
                $this->assertNotNull($column, "Missing column {$name}");
                $column->record($line);
                $resolved = $column->getUrl();
                $this->assertSame($expected, $resolved, "Column {$name} should link to Asset Tracking");
            }

            $actionsCol = $columns->first(fn ($c) => $c->getName() === 'context_actions');
            $this->assertNotNull($actionsCol, 'Missing Actions column');
            $actionsCol->record($line);
            $actionsHtml = (string) $actionsCol->getState();
            $this->assertStringContainsString('Transfer', $actionsHtml);
            $this->assertStringContainsString((string) $this->sessionId, $actionsHtml);
            $this->assertNotSame('—', trim(strip_tags($actionsHtml)));
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function list_table_exposes_transfer_columns(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $table = TransferringSessionsTable::configure(Table::make(new ListTransferringSessions));
            $columnNames = collect($table->getColumns())->map(fn ($column) => $column->getName())->all();

            $this->assertContains('status', $columnNames);
            $this->assertContains('fromSite.name', $columnNames);
            $this->assertContains('toSite.name', $columnNames);
            $this->assertContains('confirmed_count', $columnNames);
            $this->assertContains('opened_at', $columnNames);
            $this->assertContains('shipped_at', $columnNames);
            $this->assertContains('completed_at', $columnNames);
            $this->assertContains('receivingSession.id', $columnNames);

            $filterNames = collect($table->getFilters())->map(fn ($filter) => $filter->getName())->all();
            $this->assertContains('status', $filterNames);
            $this->assertContains('site', $filterNames);
        } finally {
            tenancy()->end();
        }
    }

    #[Test]
    public function operations_hub_includes_transfer_directory_when_enabled(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $hub = Livewire::test(OperationsHub::class)->instance();
            $labels = collect($hub->directories())->pluck('label')->all();

            $this->assertContains('Transfer', $labels);

            $transfer = collect($hub->directories())->firstWhere('label', 'Transfer');
            $this->assertNotNull($transfer);
            $this->assertStringContainsString('transferring-sessions', (string) $transfer['url']);
        } finally {
            tenancy()->end();
        }
    }

    #[Test]
    public function livewire_create_confirm_ship_and_receive_transfer_session(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $priorAutoOpen = TenantSettings::forTenant($tenant)->autoOpenReceiveAfterTransferShip();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            config(['tracepharma.regulatory_compliance.password_gate' => false]);
            // Shared demo2 may retain a prior true; pin default-off for this assertion.
            TenantSettings::forTenant($tenant)
                ->setAutoOpenReceiveAfterTransferShip(false)
                ->saveQuietly();

            $user = $this->createOrgAdminUser();
            $this->actingAs($user);

            [$fromSite, $toSite] = $this->createTransferSites($tenant);

            // Org-admin form options are not filtered by site pivot (factory users
            // without SitesAccessAll / pivot would see empty user-scoped options()).
            $userWithoutSites = User::factory()->create();
            $this->assertSame([], EligibleReceiveSites::options($userWithoutSites));
            $orgOptions = EligibleReceiveSites::organizationOptions();
            $this->assertArrayHasKey((int) $fromSite->getKey(), $orgOptions);
            $this->assertArrayHasKey((int) $toSite->getKey(), $orgOptions);

            Livewire::test(CreateTransferringSession::class)
                ->assertFormSet([
                    'from_site_id' => $fromSite->getKey(),
                    'to_site_id' => $toSite->getKey(),
                ])
                ->fillForm([
                    'from_site_id' => $fromSite->getKey(),
                    'to_site_id' => $toSite->getKey(),
                    'notes' => 'Resource UI test transfer',
                ])
                ->call('create')
                ->assertHasNoFormErrors()
                ->assertRedirect();

            $session = TransferringSession::query()
                ->where('from_site_id', $fromSite->getKey())
                ->where('to_site_id', $toSite->getKey())
                ->where('notes', 'Resource UI test transfer')
                ->latest('id')
                ->first();

            $this->assertNotNull($session);
            $this->sessionId = (int) $session->getKey();
            $this->assertSame('open', $session->status);

            $epc = Epc::query()->create(Epc::materializeAttributesFromUri(self::EPC_URI));
            $this->epcId = (int) $epc->getKey();
            $this->receiveAtSite($fromSite, $epc);

            $view = Livewire::test(ViewTransferringSession::class, ['record' => $session->getKey()]);
            $view->assertSee('Scan barcode');
            $view->assertSee('ADD');
            $view->assertSee($fromSite->name);
            $view->assertSee($toSite->name);
            $view->assertSee($fromSite->gln);
            $view->assertSee($toSite->gln);

            $view->set('scan', self::EPC_URI)
                ->callAction('confirmScan');

            $session->refresh();
            $this->assertSame(1, (int) $session->confirmed_count);

            $shipResult = $view->callAction('completeTransfer');

            $session->refresh();
            $this->assertSame('in_transit', $session->status);
            $this->assertNotNull($session->transfer_epcis_document_id);
            $this->assertNotNull($session->shipped_at);
            $this->transferDocumentId = (int) $session->transfer_epcis_document_id;

            // Default: ship leaves session in_transit; open receive via Receive at destination.
            $this->assertFalse(TenantSettings::forTenant($tenant)->autoOpenReceiveAfterTransferShip());

            $receiving = ReceivingSession::query()
                ->where('transferring_session_id', $session->getKey())
                ->first();
            $this->assertNull($receiving);
            $shipResult->assertNoRedirect();

            $receiveView = Livewire::test(ViewTransferringSession::class, ['record' => $session->getKey()]);
            $receiveView->assertDontSee('Scan to receive');
            // In-transit HUD no longer shows destination "Received" stats chrome
            // (daisyUI stats/stat-value blocks); that progress lives on
            // ViewReceivingSession instead. (The infolist's plain "Received at"
            // timestamp field is unrelated and intentionally untouched.)
            $receiveView->assertDontSee('stat-value');

            $receiveView->assertSee('Receive at destination');
            $receiveView->callAction('receiveAtDestination')
                ->assertRedirect();

            $receiving = ReceivingSession::query()
                ->where('transferring_session_id', $session->getKey())
                ->first();

            $this->assertNotNull($receiving);
            $this->receivingSessionId = (int) $receiving->getKey();

            Livewire::test(ViewReceivingSession::class, ['record' => $receiving->getKey()])
                ->assertSee('RECEIVE')
                ->assertSee('Scan to receive')
                ->set('scan', self::EPC_URI)
                ->callAction('confirmScan');

            $session->refresh();
            $this->assertSame('completed', $session->status);
            $this->assertSame(1, (int) $session->received_count);
            $this->assertNotNull($session->received_at);
            $this->assertNotNull($session->receive_events_generated_at);

            Livewire::test(ViewTransferringSession::class, ['record' => $session->getKey()])
                ->assertSee('Transfer complete')
                ->assertDontSee('Scan barcode')
                ->assertDontSee('Scan to receive')
                ->assertSee('View transfer EPCIS document');

            $this->assertStringContainsString(
                'receiving-sessions/'.$receiving->getKey(),
                ReceivingSessionResource::getUrl('view', ['record' => $receiving], panel: 'app'),
            );
        } finally {
            TenantSettings::forTenant($tenant)
                ->setAutoOpenReceiveAfterTransferShip($priorAutoOpen)
                ->saveQuietly();
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function ship_auto_opens_receive_when_setting_enabled(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $prior = TenantSettings::forTenant($tenant)->autoOpenReceiveAfterTransferShip();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            config(['tracepharma.regulatory_compliance.password_gate' => false]);
            TenantSettings::forTenant($tenant)
                ->setAutoOpenReceiveAfterTransferShip(true)
                ->saveQuietly();

            $user = $this->createOrgAdminUser();
            $this->actingAs($user);

            [$fromSite, $toSite] = $this->createTransferSites($tenant);
            $session = app(OpenTransferringSession::class)->handle(
                fromSiteId: (int) $fromSite->getKey(),
                toSiteId: (int) $toSite->getKey(),
                openedBy: (int) $user->getKey(),
            );
            $this->sessionId = (int) $session->getKey();

            $epc = Epc::query()->create(Epc::materializeAttributesFromUri(self::EPC_URI));
            $this->epcId = (int) $epc->getKey();
            $this->receiveAtSite($fromSite, $epc);
            app(ConfirmTransferringScan::class)->handle($session, self::EPC_URI, (int) $user->getKey());

            Livewire::test(ViewTransferringSession::class, ['record' => $session->getKey()])
                ->callAction('completeTransfer')
                ->assertRedirectContains('receiving-sessions');

            $session->refresh();
            $this->assertSame('in_transit', $session->status);
            $this->assertNotNull($session->transfer_epcis_document_id);
            $this->transferDocumentId = (int) $session->transfer_epcis_document_id;

            $receiving = ReceivingSession::query()
                ->where('transferring_session_id', $session->getKey())
                ->first();
            $this->assertNotNull($receiving);
            $this->receivingSessionId = (int) $receiving->getKey();
        } finally {
            TenantSettings::forTenant($tenant)
                ->setAutoOpenReceiveAfterTransferShip($prior)
                ->saveQuietly();
            $this->cleanup($tenant);
        }
    }

    /**
     * @return array{0: Site, 1: Site}
     */
    private function createTransferSites(Tenant $tenant): array
    {
        $settings = TenantSettings::forTenant($tenant);
        $this->priorDefaultShipFromSiteId = $settings->defaultShipFromSiteId();
        $this->priorDefaultReceiveSiteId = $settings->defaultReceiveSiteId();
        $this->priorCompanyPrefix = $settings->companyPrefix();
        if ($settings->companyPrefix() === null) {
            $orgGln = preg_replace('/\D+/', '', (string) ($settings->gln() ?? '')) ?? '';
            $prefix = strlen($orgGln) === 13 ? substr($orgGln, 0, 7) : '0366150';
            $settings->setCompanyPrefix($prefix);
            $tenant->saveQuietly();
            tenancy()->initialize($tenant->fresh());
        }

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

        $settings->setDefaultShipFromSiteId((int) $fromSite->getKey());
        $settings->setDefaultReceiveSiteId((int) $toSite->getKey());
        $tenant->save();

        return [$fromSite, $toSite];
    }

    private function createOrgAdminUser(): User
    {
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);

        $user = User::factory()->create();
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
            'original_filename' => 'transfer-resource-custody-receipt.xml',
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

        Filament::setCurrentPanel(Filament::getPanel('app'));

        return $tenant;
    }

    private function cleanup(Tenant $tenant): void
    {
        if (tenancy()->initialized) {
            if ($this->custodyEventIds !== []) {
                DB::table('event_epcs')->whereIn('event_id', $this->custodyEventIds)->delete();
                EpcisEvent::query()->whereIn('id', $this->custodyEventIds)->delete();
                $this->custodyEventIds = [];
            }

            if ($this->custodyDocumentIds !== []) {
                EpcisDocument::query()->whereIn('id', $this->custodyDocumentIds)->delete();
                $this->custodyDocumentIds = [];
            }

            if ($this->transferDocumentId !== null) {
                $document = EpcisDocument::query()->find($this->transferDocumentId);
                if ($document !== null && filled($document->payload_path)) {
                    Storage::disk($document->payload_disk)->delete($document->payload_path);
                }
                EpcisDocument::query()->whereKey($this->transferDocumentId)->delete();
                $this->transferDocumentId = null;
            }

            if ($this->receivingSessionId !== null) {
                ReceivingSession::query()->whereKey($this->receivingSessionId)->delete();
                $this->receivingSessionId = null;
            }

            if ($this->sessionId !== null) {
                // Cover auto-opened receive when the test failed before capturing receivingSessionId.
                ReceivingSession::query()
                    ->where('transferring_session_id', $this->sessionId)
                    ->delete();
                TransferringSession::query()->whereKey($this->sessionId)->delete();
                $this->sessionId = null;
            }

            if ($this->epcId !== null) {
                QuarantineHold::query()->where('epc_id', $this->epcId)->delete();
                DB::table('exception_epcs')->where('epc_id', $this->epcId)->delete();
                DB::table('event_epcs')->where('epc_id', $this->epcId)->delete();
                if (Schema::hasTable('document_epcs')) {
                    DB::table('document_epcs')->where('epc_id', $this->epcId)->delete();
                }
                TransferringScanLine::query()->where('epc_id', $this->epcId)->delete();
                Epc::query()->whereKey($this->epcId)->delete();
                $this->epcId = null;
            }

            if ($this->siteIds !== []) {
                Site::query()->whereIn('id', $this->siteIds)->delete();
                $this->siteIds = [];
            }

            $settings = TenantSettings::forTenant($tenant);
            $settings->setDefaultShipFromSiteId($this->priorDefaultShipFromSiteId);
            $settings->setDefaultReceiveSiteId($this->priorDefaultReceiveSiteId);
            if ($this->priorCompanyPrefix !== null || $settings->companyPrefix() !== $this->priorCompanyPrefix) {
                $settings->setCompanyPrefix($this->priorCompanyPrefix);
            }
            $tenant->saveQuietly();
            $this->priorDefaultShipFromSiteId = null;
            $this->priorDefaultReceiveSiteId = null;
            $this->priorCompanyPrefix = null;

            tenancy()->end();
        }
    }
}
