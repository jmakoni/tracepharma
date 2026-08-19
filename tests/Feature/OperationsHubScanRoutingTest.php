<?php

namespace Tests\Feature;

use App\Actions\Epcis\IngestEpcisXmlDocument;
use App\Actions\Receiving\OpenScanFirstReceivingSession;
use App\Actions\Transferring\CompleteTransferringSession;
use App\Actions\Transferring\ConfirmTransferringScan;
use App\Actions\Transferring\OpenTransferringSession;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Pages\AssetTracking;
use App\Filament\App\Pages\OperationsHub;
use App\Filament\App\Resources\EpcisDocuments\EpcisDocumentResource;
use App\Filament\App\Resources\ReceivingSessions\ReceivingSessionResource;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Receiving\ReceivingScanLine;
use App\Models\Receiving\ReceivingSession;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\Transferring\TransferringSession;
use App\Models\User;
use App\Support\Auth\CurrentSite;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\Gs1\ElementString;
use App\Support\Gs1\Gtin;
use App\Support\Receiving\EligibleReceiveSites;
use App\Support\TenantSettings;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OperationsHubScanRoutingTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    private ?int $priorDefaultReceiveSiteId = null;

    /** @var list<int> */
    private array $disposableReceiveSiteIds = [];

    #[Test]
    public function sgtin_scan_redirects_to_asset_tracking_when_no_single_open_session(): void
    {
        $this->initializeDemo2Tenant();

        $sessionIds = [];

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            $user = User::factory()->create();
            $user->assignRole(TenantRole::Owner->value);
            $this->actingAs($user);

            $this->ensureEligibleReceiveSite();

            // Ensure multi-open so SGTIN does not prefer a single session.
            $first = app(OpenScanFirstReceivingSession::class)->handle();
            $second = app(OpenScanFirstReceivingSession::class)->handle();
            $sessionIds = [(int) $first->getKey(), (int) $second->getKey()];

            $barcode = '(01)30301164005162(21)ABC123';
            $normalized = ElementString::normalize($barcode);

            Livewire::test(OperationsHub::class)
                ->set('hubScan', $barcode)
                ->call('routeHubScan')
                ->assertRedirect(AssetTracking::getUrl(['scan' => $normalized]));
        } finally {
            if ($sessionIds !== []) {
                ReceivingSession::query()->whereIn('id', $sessionIds)->delete();
            }

            $this->cleanupEligibleReceiveSiteBootstrap();
            tenancy()->end();
        }
    }

    #[Test]
    public function sgtin_on_open_session_scan_line_redirects_to_that_session(): void
    {
        $this->initializeDemo2Tenant();

        $sessionIds = [];
        $epcId = null;

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            $user = User::factory()->create();
            $user->assignRole(TenantRole::Owner->value);
            $this->actingAs($user);

            $this->ensureEligibleReceiveSite();

            $first = app(OpenScanFirstReceivingSession::class)->handle();
            $second = app(OpenScanFirstReceivingSession::class)->handle();
            $sessionIds = [(int) $first->getKey(), (int) $second->getKey()];

            $uri = 'urn:epc:id:sgtin:030116.0200116.9000008200HUB1';
            $epc = Epc::query()->create(Epc::materializeAttributesFromUri($uri));
            $epcId = (int) $epc->getKey();

            ReceivingScanLine::query()->create([
                'receiving_session_id' => $second->getKey(),
                'epc_id' => $epc->getKey(),
                'parent_epc_id' => null,
                'line_role' => 'parent',
                'status' => 'expected',
            ]);

            $barcode = '(01)'.$epc->gtin14.'(21)'.$epc->serial_number;
            $normalized = ElementString::normalize($barcode);

            Livewire::test(OperationsHub::class)
                ->set('hubScan', $barcode)
                ->call('routeHubScan')
                ->assertRedirect(ReceivingSessionResource::getUrl('view', [
                    'record' => $second,
                    'scan' => $normalized,
                ]));
        } finally {
            if ($epcId !== null) {
                ReceivingScanLine::query()->where('epc_id', $epcId)->delete();
                Epc::query()->whereKey($epcId)->delete();
            }

            if ($sessionIds !== []) {
                ReceivingSession::query()->whereIn('id', $sessionIds)->delete();
            }

            $this->cleanupEligibleReceiveSiteBootstrap();
            tenancy()->end();
        }
    }

    #[Test]
    public function sgtin_with_exactly_one_open_session_redirects_to_that_session(): void
    {
        $this->initializeDemo2Tenant();

        $sessionId = null;
        /** @var array<int, string> $pausedStatuses */
        $pausedStatuses = [];

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            $user = User::factory()->create();
            $user->assignRole(TenantRole::Owner->value);
            $this->actingAs($user);

            $this->ensureEligibleReceiveSite();

            // Pause leftover open sessions so count is deterministic.
            $open = ReceivingSession::query()
                ->whereIn('status', ['open', 'in_progress'])
                ->get(['id', 'status']);
            foreach ($open as $session) {
                $pausedStatuses[(int) $session->getKey()] = (string) $session->status;
                $session->forceFill(['status' => 'cancelled'])->save();
            }

            $only = app(OpenScanFirstReceivingSession::class)->handle();
            $sessionId = (int) $only->getKey();
            CurrentSite::set((int) $only->site_id);

            $barcode = '(01)30301164005162(21)SINGLEOPEN1';
            $normalized = ElementString::normalize($barcode);

            Livewire::test(OperationsHub::class)
                ->set('hubScan', $barcode)
                ->call('routeHubScan')
                ->assertRedirect(ReceivingSessionResource::getUrl('view', [
                    'record' => $only,
                    'scan' => $normalized,
                ]));
        } finally {
            if ($sessionId !== null) {
                ReceivingSession::query()->whereKey($sessionId)->delete();
            }

            foreach ($pausedStatuses as $id => $status) {
                ReceivingSession::query()->whereKey($id)->update(['status' => $status]);
            }

            session()->forget(CurrentSite::SESSION_KEY);
            $this->cleanupEligibleReceiveSiteBootstrap();
            tenancy()->end();
        }
    }

    #[Test]
    public function sgtin_on_in_transit_transfer_redirects_to_transfer_receive_session(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        $siteIds = [];
        $epcId = null;
        $transferSessionId = null;
        $priorDefaultShipFromSiteId = null;
        $priorDefaultReceiveSiteId = null;
        $custodyDocumentId = null;
        $custodyEventId = null;

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            $user = User::factory()->create();
            $user->assignRole(TenantRole::Owner->value);
            $this->actingAs($user);

            $fromGln = $this->uniqueGlnUnderCompanyPrefix();
            $toGln = $this->uniqueGlnUnderCompanyPrefix();

            $fromSite = Site::query()->create([
                'name' => 'Hub Transfer From '.Str::random(6),
                'gln' => $fromGln,
                'is_active' => true,
                'is_headquarters' => true,
                'trading_partner_id' => null,
                'is_organization_facility' => true,
            ]);
            $siteIds[] = (int) $fromSite->getKey();

            $toSite = Site::query()->create([
                'name' => 'Hub Transfer To '.Str::random(6),
                'gln' => $toGln,
                'is_active' => true,
                'is_headquarters' => false,
                'trading_partner_id' => null,
                'is_organization_facility' => true,
            ]);
            $siteIds[] = (int) $toSite->getKey();

            $settings = TenantSettings::forTenant($tenant);
            $priorDefaultShipFromSiteId = $settings->defaultShipFromSiteId();
            $priorDefaultReceiveSiteId = $settings->defaultReceiveSiteId();
            $settings->setDefaultShipFromSiteId((int) $fromSite->getKey());
            $settings->setDefaultReceiveSiteId((int) $toSite->getKey());
            $tenant->save();

            $uri = 'urn:epc:id:sgtin:030116.0200116.9000008200TRF1';
            $epc = Epc::query()->create(Epc::materializeAttributesFromUri($uri));
            $epcId = (int) $epc->getKey();

            // Put the unit in tenant custody at the ship-from site before transferring.
            $custodyDocument = EpcisDocument::query()->create([
                'document_uuid' => (string) Str::uuid(),
                'schema_version' => '1.2',
                'creation_date' => now(),
                'received_at' => now(),
                'direction' => 'outbound',
                'status' => 'parsed',
                'original_filename' => 'hub-transfer-custody-receipt.xml',
            ]);
            $custodyDocumentId = (int) $custodyDocument->getKey();
            $custodyEvent = EpcisEvent::query()->create([
                'document_id' => $custodyDocument->getKey(),
                'event_id' => 'urn:uuid:'.(string) Str::uuid(),
                'event_type' => 'ObjectEvent',
                'event_time' => now()->subMinute(),
                'record_time' => now()->subMinute(),
                'event_timezone_offset' => '+00:00',
                'action' => 'OBSERVE',
                'biz_step' => 'urn:epcglobal:cbv:bizstep:receiving',
                'disposition' => 'urn:epcglobal:cbv:disp:in_progress',
                'read_point_gln' => $fromGln,
                'biz_location_gln' => $fromGln,
            ]);
            $custodyEventId = (int) $custodyEvent->getKey();
            DB::table('event_epcs')->insertOrIgnore([[
                'event_id' => $custodyEvent->getKey(),
                'epc_id' => $epc->getKey(),
                'role' => 'epcList',
            ]]);

            $transferSession = app(OpenTransferringSession::class)->handle(
                fromSiteId: (int) $fromSite->getKey(),
                toSiteId: (int) $toSite->getKey(),
            );
            $transferSessionId = (int) $transferSession->getKey();

            $confirm = app(ConfirmTransferringScan::class)->handle($transferSession, $uri);
            $this->assertTrue($confirm['ok'], $confirm['message'] ?? 'transfer confirm failed');
            $shipped = app(CompleteTransferringSession::class)->handle($transferSession->fresh());
            $this->assertSame('in_transit', $shipped->status);

            $barcode = '(01)'.$epc->gtin14.'(21)'.$epc->serial_number;
            $normalized = ElementString::normalize($barcode);

            $response = Livewire::test(OperationsHub::class)
                ->set('hubScan', $barcode)
                ->call('routeHubScan');

            $receiving = ReceivingSession::query()
                ->where('transferring_session_id', $shipped->getKey())
                ->first();
            $this->assertNotNull($receiving, 'Expected a transfer_receive session to be opened.');

            $response->assertRedirect(ReceivingSessionResource::getUrl('view', [
                'record' => $receiving,
                'scan' => $normalized,
            ]));
        } finally {
            if ($transferSessionId !== null) {
                $transfer = TransferringSession::query()->find($transferSessionId);

                ReceivingSession::query()
                    ->where('transferring_session_id', $transferSessionId)
                    ->delete();

                if ($transfer !== null && $transfer->transfer_epcis_document_id !== null) {
                    EpcisDocument::query()->whereKey($transfer->transfer_epcis_document_id)->delete();
                }

                TransferringSession::query()->whereKey($transferSessionId)->delete();
            }

            if ($epcId !== null) {
                DB::table('event_epcs')->where('epc_id', $epcId)->delete();
                Epc::query()->whereKey($epcId)->delete();
            }

            if ($custodyEventId !== null) {
                EpcisEvent::query()->whereKey($custodyEventId)->delete();
            }
            if ($custodyDocumentId !== null) {
                EpcisDocument::query()->whereKey($custodyDocumentId)->delete();
            }

            if ($siteIds !== []) {
                Site::query()->whereIn('id', $siteIds)->delete();
            }

            if (tenancy()->initialized) {
                $settings = TenantSettings::forTenant($tenant);
                $settings->setDefaultShipFromSiteId($priorDefaultShipFromSiteId);
                $settings->setDefaultReceiveSiteId($priorDefaultReceiveSiteId);
                $tenant->save();
            }

            tenancy()->end();
        }
    }

    #[Test]
    public function sgtin_on_unmatched_inbound_asn_redirects_to_asn_session(): void
    {
        $this->initializeDemo2Tenant();

        $documentId = null;
        $epcUris = [
            'urn:epc:id:sscc:030116.01001227099',
            'urn:epc:id:sgtin:030116.0200116.10000082009991',
        ];

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            $user = User::factory()->create();
            $user->assignRole(TenantRole::Owner->value);
            $this->actingAs($user);

            $document = $this->ingestAsnFixture($epcUris[0], $epcUris[1]);
            $documentId = (int) $document->getKey();
            $this->assertSame('validated', $document->status);

            $sgtinEpc = Epc::query()->where('epc_uri', $epcUris[1])->firstOrFail();

            $barcode = '(01)'.$sgtinEpc->gtin14.'(21)'.$sgtinEpc->serial_number;
            $normalized = ElementString::normalize($barcode);

            $response = Livewire::test(OperationsHub::class)
                ->set('hubScan', $barcode)
                ->call('routeHubScan');

            $asnSession = ReceivingSession::query()
                ->where('epcis_document_id', $document->getKey())
                ->first();
            $this->assertNotNull($asnSession, 'Expected an ASN receiving session to be opened.');
            $this->assertSame('open', $asnSession->status);

            $response->assertRedirect(ReceivingSessionResource::getUrl('view', [
                'record' => $asnSession,
                'scan' => $normalized,
            ]));
        } finally {
            if ($documentId !== null) {
                ReceivingSession::query()->where('epcis_document_id', $documentId)->delete();
                EpcisDocument::query()->whereKey($documentId)->delete();
            }

            foreach ($epcUris as $uri) {
                $epc = Epc::query()->where('epc_uri', $uri)->first();
                if ($epc !== null) {
                    DB::table('epc_ilmd')->where('epc_id', $epc->getKey())->delete();
                    Epc::query()->whereKey($epc->getKey())->delete();
                }
            }

            tenancy()->end();
        }
    }

    #[Test]
    public function sscc_scan_redirects_to_asset_tracking_when_no_active_session(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $sscc = '003011610012354038';
            $normalized = ElementString::normalize($sscc);
            $activeSessions = ReceivingSession::query()
                ->whereIn('status', ['open', 'in_progress'])
                ->orderByDesc('opened_at')
                ->get();

            // Single open is only preferred after exact/transfer/ASN checks fail.
            $expected = $activeSessions->count() === 1
                ? ReceivingSessionResource::getUrl('view', [
                    'record' => $activeSessions->first(),
                    'scan' => $normalized,
                ])
                : AssetTracking::getUrl(['scan' => $normalized]);

            Livewire::test(OperationsHub::class)
                ->set('hubScan', $sscc)
                ->call('routeHubScan')
                ->assertRedirect($expected);
        } finally {
            tenancy()->end();
        }
    }

    #[Test]
    public function sscc_with_multiple_open_sessions_and_no_match_falls_to_asset_tracking(): void
    {
        $this->initializeDemo2Tenant();

        $sessionIds = [];

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            $user = User::factory()->create();
            $user->assignRole(TenantRole::Owner->value);
            $this->actingAs($user);

            $this->ensureEligibleReceiveSite();

            $first = app(OpenScanFirstReceivingSession::class)->handle();
            $second = app(OpenScanFirstReceivingSession::class)->handle();
            $sessionIds = [(int) $first->getKey(), (int) $second->getKey()];

            $this->assertGreaterThanOrEqual(
                2,
                ReceivingSession::query()->whereIn('status', ['open', 'in_progress'])->count(),
            );

            // Valid SSCC check digit, unlikely to exist as an EPC / ASN / transfer in demo2.
            $sscc = '099999900000000002';
            $normalized = ElementString::normalize($sscc);

            Livewire::test(OperationsHub::class)
                ->set('hubScan', $sscc)
                ->call('routeHubScan')
                ->assertRedirect(AssetTracking::getUrl(['scan' => $normalized]));
        } finally {
            if ($sessionIds !== []) {
                ReceivingSession::query()->whereIn('id', $sessionIds)->delete();
            }

            $this->cleanupEligibleReceiveSiteBootstrap();
            tenancy()->end();
        }
    }

    #[Test]
    public function unknown_scan_redirects_to_find_recall_when_inbound_enabled(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $url = EpcisDocumentResource::getUrl('index');
            $expected = $url.(str_contains($url, '?') ? '&' : '?').'findRecall=1';

            Livewire::test(OperationsHub::class)
                ->set('hubScan', 'UNKNOWN-LABEL')
                ->call('routeHubScan')
                ->assertRedirect($expected);
        } finally {
            tenancy()->end();
        }
    }

    #[Test]
    public function active_receiving_sessions_are_site_scoped_and_limited_to_five(): void
    {
        $this->initializeDemo2Tenant();

        $sessionIds = [];

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            $user = User::factory()->create();
            $user->assignRole(TenantRole::Owner->value);
            $this->actingAs($user);

            $siteA = $this->ensureEligibleReceiveSite();
            $siteB = Site::query()->create([
                'name' => 'Other Receive Site '.Str::random(6),
                'gln' => $this->uniqueGln(),
                'is_active' => true,
                'is_headquarters' => false,
                'is_organization_facility' => true,
            ]);
            $this->disposableReceiveSiteIds[] = (int) $siteB->getKey();

            CurrentSite::set((int) $siteA->getKey());

            for ($i = 0; $i < 6; $i++) {
                $session = app(OpenScanFirstReceivingSession::class)->handle(siteId: (int) $siteA->getKey());
                $sessionIds[] = (int) $session->getKey();
            }

            $otherSiteSession = app(OpenScanFirstReceivingSession::class)->handle(siteId: (int) $siteB->getKey());
            $sessionIds[] = (int) $otherSiteSession->getKey();

            $hub = Livewire::test(OperationsHub::class)->instance();
            $active = $hub->activeReceivingSessions();

            $this->assertCount(5, $active);
            $this->assertTrue($active->every(fn (ReceivingSession $session): bool => (int) $session->site_id === (int) $siteA->getKey()));
            $this->assertFalse($active->contains(fn (ReceivingSession $session): bool => (int) $session->getKey() === (int) $otherSiteSession->getKey()));
        } finally {
            if ($sessionIds !== []) {
                ReceivingSession::query()->whereIn('id', $sessionIds)->delete();
            }

            session()->forget(CurrentSite::SESSION_KEY);
            $this->cleanupEligibleReceiveSiteBootstrap();
            tenancy()->end();
        }
    }

    #[Test]
    public function single_open_receiving_session_fallback_is_site_scoped(): void
    {
        $this->initializeDemo2Tenant();

        $sessionIds = [];

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            $user = User::factory()->create();
            $user->assignRole(TenantRole::Owner->value);
            $this->actingAs($user);

            $siteA = $this->ensureEligibleReceiveSite();
            $siteB = Site::query()->create([
                'name' => 'Other Receive Site '.Str::random(6),
                'gln' => $this->uniqueGln(),
                'is_active' => true,
                'is_headquarters' => false,
                'is_organization_facility' => true,
            ]);
            $this->disposableReceiveSiteIds[] = (int) $siteB->getKey();

            CurrentSite::set((int) $siteA->getKey());

            $open = ReceivingSession::query()
                ->whereIn('status', ['open', 'in_progress'])
                ->get(['id', 'status']);
            foreach ($open as $session) {
                $session->forceFill(['status' => 'cancelled'])->save();
            }

            $onlyOnSiteA = app(OpenScanFirstReceivingSession::class)->handle(siteId: (int) $siteA->getKey());
            $sessionIds[] = (int) $onlyOnSiteA->getKey();

            $otherSiteSession = app(OpenScanFirstReceivingSession::class)->handle(siteId: (int) $siteB->getKey());
            $sessionIds[] = (int) $otherSiteSession->getKey();

            $barcode = '(01)30301164005162(21)SINGLE-SITE-1';
            $normalized = ElementString::normalize($barcode);

            Livewire::test(OperationsHub::class)
                ->set('hubScan', $barcode)
                ->call('routeHubScan')
                ->assertRedirect(ReceivingSessionResource::getUrl('view', [
                    'record' => $onlyOnSiteA,
                    'scan' => $normalized,
                ]));
        } finally {
            if ($sessionIds !== []) {
                ReceivingSession::query()->whereIn('id', $sessionIds)->delete();
            }

            session()->forget(CurrentSite::SESSION_KEY);
            $this->cleanupEligibleReceiveSiteBootstrap();
            tenancy()->end();
        }
    }

    #[Test]
    public function directories_include_asset_tracking_and_help_copy_mentions_trace(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $hub = Livewire::test(OperationsHub::class)->instance();
            $labels = collect($hub->directories())->pluck('label')->all();

            $this->assertContains('Asset Tracking', $labels);

            Livewire::test(OperationsHub::class)
                ->assertSee('Asset Tracking')
                ->assertSee('SGTIN opens a Receive session')
                ->assertSee('Receive');
        } finally {
            tenancy()->end();
        }
    }

    private function ensureEligibleReceiveSite(): Site
    {
        $existing = EligibleReceiveSites::forOrganization()->first();
        if ($existing !== null) {
            return $existing;
        }

        $tenant = tenant();
        if (! $tenant instanceof Tenant) {
            throw new \RuntimeException('Tenant not initialized.');
        }

        if ($this->priorDefaultReceiveSiteId === null) {
            $this->priorDefaultReceiveSiteId = TenantSettings::forTenant($tenant)->defaultReceiveSiteId();
        }

        $receiveSite = Site::query()->create([
            'name' => 'Scan-first Receive Site '.Str::random(6),
            'gln' => $this->uniqueGlnUnderCompanyPrefix(),
            'is_active' => true,
            'is_headquarters' => true,
            'is_organization_facility' => true,
        ]);
        $this->disposableReceiveSiteIds[] = (int) $receiveSite->getKey();

        TenantSettings::forTenant($tenant)->setDefaultReceiveSiteId((int) $receiveSite->getKey());
        $tenant->save();

        return $receiveSite;
    }

    private function cleanupEligibleReceiveSiteBootstrap(): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        if ($this->disposableReceiveSiteIds !== []) {
            Site::query()->whereIn('id', $this->disposableReceiveSiteIds)->delete();
            $this->disposableReceiveSiteIds = [];
        }

        if ($this->priorDefaultReceiveSiteId !== null) {
            $tenant = tenant();
            if ($tenant instanceof Tenant) {
                TenantSettings::forTenant($tenant)->setDefaultReceiveSiteId($this->priorDefaultReceiveSiteId);
                $tenant->save();
            }
            $this->priorDefaultReceiveSiteId = null;
        }
    }

    private function uniqueGln(): string
    {
        do {
            $body = '03'.str_pad((string) random_int(0, 9999999999), 10, '0', STR_PAD_LEFT);
            $gln = $body.Gtin::checkDigit($body);
        } while (Site::query()->where('gln', $gln)->exists());

        return $gln;
    }

    private function uniqueGlnUnderCompanyPrefix(): string
    {
        $prefix = TenantSettings::forTenant(tenant())->companyPrefix() ?? '0399991';
        $prefix = preg_replace('/\D+/', '', $prefix) ?: '0399991';
        $locationLen = 12 - strlen($prefix);

        do {
            $location = str_pad((string) random_int(0, (10 ** $locationLen) - 1), $locationLen, '0', STR_PAD_LEFT);
            $body = $prefix.$location;
            $gln = $body.Gtin::checkDigit($body);
        } while (Site::query()->where('gln', $gln)->exists());

        return $gln;
    }

    private function ingestAsnFixture(string $ssccUri, string $sgtinUri): EpcisDocument
    {
        $fixture = base_path('tests/Fixtures/epcis/minimal_object_shipping.xml');
        $this->assertFileExists($fixture);

        $xml = file_get_contents($fixture);
        $this->assertNotFalse($xml);
        $xml = str_replace('11111111-2222-3333-4444-555555555555', (string) str()->uuid(), $xml);
        $xml = str_replace('urn:epc:id:sscc:030116.01001227052', $ssccUri, $xml);
        $xml = str_replace('urn:epc:id:sgtin:030116.0200116.10000082001560', $sgtinUri, $xml);

        $tmp = tempnam(sys_get_temp_dir(), 'ops_hub_asn_');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, $xml);

        try {
            return app(IngestEpcisXmlDocument::class)->handle($tmp, [
                'direction' => 'inbound',
                'original_filename' => 'ops_hub_asn.xml',
            ]);
        } finally {
            @unlink($tmp);
        }
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
}
