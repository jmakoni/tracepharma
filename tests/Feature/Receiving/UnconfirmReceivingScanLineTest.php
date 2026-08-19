<?php

namespace Tests\Feature\Receiving;

use App\Actions\Epcis\IngestEpcisXmlDocument;
use App\Actions\Receiving\ConfirmReceivingScan;
use App\Actions\Receiving\CopyConfirmedReceivingScansToSession;
use App\Actions\Receiving\OpenReceivingSessionFromDocument;
use App\Actions\Receiving\OpenScanFirstReceivingSession;
use App\Actions\Receiving\OpenTransferReceivingSession;
use App\Actions\Receiving\UnconfirmReceivingScanLine;
use App\Actions\Transferring\CompleteTransferringSession;
use App\Actions\Transferring\ConfirmTransferringScan;
use App\Actions\Transferring\OpenTransferringSession;
use App\Enums\TenantProfile;
use App\Filament\App\Resources\ReceivingSessions\Pages\ViewReceivingSession;
use App\Filament\App\Resources\ReceivingSessions\RelationManagers\ScanLinesRelationManager;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Receiving\ReceivingScanLine;
use App\Models\Receiving\ReceivingSession;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\Transferring\TransferringScanLine;
use App\Models\Transferring\TransferringSession;
use App\Models\User;
use App\Support\Gs1\Gtin;
use App\Support\TenantSettings;
use DomainException;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UnconfirmReceivingScanLineTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private const SSCC_URI = 'urn:epc:id:sscc:030116.01001227052';

    private const SGTIN_URI = 'urn:epc:id:sgtin:030116.0200116.10000082001560';

    private const UNEXPECTED_URI = 'urn:epc:id:sgtin:0614141.107346.2017';

    private static bool $demo2TenantReady = false;

    private ?int $documentId = null;

    /** @var list<int> */
    private array $receivingSessionIds = [];

    private ?int $unexpectedEpcId = null;

    /** @var list<int> */
    private array $userIds = [];

    /** @var list<int> */
    private array $siteIds = [];

    private ?int $transferSessionId = null;

    /** @var list<int> */
    private array $transferEpcIds = [];

    /** @var list<int> */
    private array $custodyDocumentIds = [];

    /** @var list<int> */
    private array $custodyEventIds = [];

    private ?int $priorDefaultShipFromSiteId = null;

    private ?int $priorDefaultReceiveSiteId = null;

    #[Test]
    public function it_unconfirms_parent_and_deletes_child_lines(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $document = $this->ingestMinimalFixture();
            $this->documentId = (int) $document->getKey();

            $session = app(OpenReceivingSessionFromDocument::class)->handle($document);
            $parentLine = ReceivingScanLine::query()
                ->where('receiving_session_id', $session->id)
                ->where('line_role', 'parent')
                ->firstOrFail();

            app(ConfirmReceivingScan::class)->handle(
                $session,
                self::SSCC_URI,
                userId: null,
                autoConfirmChildren: false,
            );

            $session->refresh();
            $this->assertSame(1, $session->confirmed_parent_count);
            $this->assertSame('in_progress', $session->status);
            $this->assertGreaterThan(0, $session->expected_child_count);
            $this->assertSame(0, $session->confirmed_child_count);

            $updated = app(UnconfirmReceivingScanLine::class)->handle($parentLine->fresh());

            $this->assertSame('open', $updated->status);
            $this->assertSame(0, $updated->confirmed_parent_count);
            $this->assertSame(0, $updated->confirmed_child_count);
            $this->assertSame(0, $updated->expected_child_count);
            $this->assertNull($updated->completed_at);

            $parentLine->refresh();
            $this->assertSame('expected', $parentLine->status);
            $this->assertNull($parentLine->confirmed_at);
            $this->assertNull($parentLine->scan_raw);

            $this->assertSame(0, ReceivingScanLine::query()
                ->where('receiving_session_id', $session->id)
                ->where('line_role', 'child')
                ->count());
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function it_deletes_unexpected_scan_lines(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $document = $this->ingestMinimalFixture();
            $this->documentId = (int) $document->getKey();

            $session = app(OpenReceivingSessionFromDocument::class)->handle($document);

            $unexpected = Epc::query()->create(Epc::materializeAttributesFromUri(self::UNEXPECTED_URI));
            $this->unexpectedEpcId = (int) $unexpected->getKey();

            $result = app(ConfirmReceivingScan::class)->handle($session, self::UNEXPECTED_URI);
            $this->assertFalse($result['ok']);
            $this->assertSame('unexpected', $result['effect']);

            $unexpectedLine = ReceivingScanLine::query()
                ->where('receiving_session_id', $session->id)
                ->where('status', 'unexpected')
                ->firstOrFail();

            app(UnconfirmReceivingScanLine::class)->handle($unexpectedLine);

            $this->assertSame(0, ReceivingScanLine::query()
                ->where('receiving_session_id', $session->id)
                ->where('status', 'unexpected')
                ->count());
            $this->assertSame('open', $session->fresh()->status);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function it_compensates_transferring_line_when_unconfirming_transfer_receive_scan(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            TenantSettings::forTenant($tenant)->setRequireTiForScanFirst(false);
            $tenant->save();

            [$fromSite, $toSite] = $this->createTransferSites($tenant);

            $transfer = app(OpenTransferringSession::class)->handle(
                fromSiteId: (int) $fromSite->getKey(),
                toSiteId: (int) $toSite->getKey(),
            );
            $this->transferSessionId = (int) $transfer->getKey();

            $uris = [];
            for ($i = 0; $i < 2; $i++) {
                $suffix = (string) random_int(10000000, 99999999);
                $uris[] = 'urn:epc:id:sgtin:030116.3'.substr($suffix, 0, 6).'.UC'.$suffix;
            }

            $epcs = [];
            foreach ($uris as $uri) {
                $epc = Epc::query()->create(Epc::materializeAttributesFromUri($uri));
                $this->transferEpcIds[] = (int) $epc->getKey();
                $this->receiveAtSite($fromSite, $epc);
                $epcs[] = $epc;
            }

            foreach ($uris as $uri) {
                app(ConfirmTransferringScan::class)->handle($transfer->fresh(), $uri);
            }

            $shipped = app(CompleteTransferringSession::class)->handle($transfer->fresh());
            $transferReceive = app(OpenTransferReceivingSession::class)->handle($shipped->fresh());

            $confirm = app(ConfirmReceivingScan::class)->handle($transferReceive, $uris[0]);
            $this->assertTrue($confirm['ok']);

            $transferReceive->refresh();
            $this->assertSame('in_progress', $transferReceive->status);

            $transferLine = TransferringScanLine::query()
                ->where('transferring_session_id', $this->transferSessionId)
                ->where('epc_id', $epcs[0]->getKey())
                ->firstOrFail();
            $this->assertSame('received', $transferLine->status);
            $this->assertSame(1, (int) $shipped->fresh()->received_count);

            $receivingLine = ReceivingScanLine::query()
                ->where('receiving_session_id', $transferReceive->getKey())
                ->where('epc_id', $epcs[0]->getKey())
                ->firstOrFail();

            app(UnconfirmReceivingScanLine::class)->handle($receivingLine->fresh());

            $transferLine->refresh();
            $this->assertSame('confirmed', $transferLine->status);
            $this->assertNull($transferLine->received_at);
            $this->assertSame(0, (int) $shipped->fresh()->received_count);

            $receivingLine->refresh();
            $this->assertSame('expected', $receivingLine->status);
            $this->assertNull($receivingLine->confirmed_at);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function it_unconfirms_scan_first_bare_sgtin_child_line(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            TenantSettings::forTenant($tenant)->setRequireTiForScanFirst(false);
            $tenant->save();

            $suffix = (string) random_int(10000000, 99999999);
            $uri = 'urn:epc:id:sgtin:030116.3'.substr($suffix, 0, 6).'.UC'.$suffix;
            $epc = Epc::query()->create(Epc::materializeAttributesFromUri($uri));
            $this->unexpectedEpcId = (int) $epc->getKey();

            $session = app(OpenScanFirstReceivingSession::class)->handle();
            $this->documentId = null;
            $this->receivingSessionIds[] = (int) $session->getKey();

            $confirm = app(ConfirmReceivingScan::class)->handle($session, $uri);
            $this->assertTrue($confirm['ok']);
            $this->assertSame('child_confirmed', $confirm['effect']);

            $childLine = ReceivingScanLine::query()
                ->where('receiving_session_id', $session->getKey())
                ->where('epc_id', $epc->getKey())
                ->firstOrFail();
            $this->assertSame('child', $childLine->line_role);
            $this->assertNull($childLine->parent_epc_id);

            $session->refresh();
            $this->assertSame(1, (int) $session->confirmed_child_count);
            $this->assertSame(1, (int) $session->expected_child_count);

            $updated = app(UnconfirmReceivingScanLine::class)->handle($childLine->fresh());

            $this->assertSame('open', $updated->status);
            $this->assertSame(0, (int) $updated->confirmed_child_count);
            $this->assertSame(0, (int) $updated->expected_child_count);
            $this->assertSame(
                0,
                ReceivingScanLine::query()
                    ->where('receiving_session_id', $session->getKey())
                    ->where('epc_id', $epc->getKey())
                    ->count(),
            );
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function it_deletes_scan_first_parent_and_decrements_both_parent_counters(): void
    {
        $this->initializeDemo2Tenant();

        try {
            TenantSettings::forTenant(tenant())->setRequireTiForScanFirst(false);
            tenant()->save();

            $document = $this->ingestMinimalFixture();
            $this->documentId = (int) $document->getKey();

            $scanFirst = app(OpenScanFirstReceivingSession::class)->handle();
            $this->sessionId = (int) $scanFirst->getKey();

            app(ConfirmReceivingScan::class)->handle(
                $scanFirst,
                self::SSCC_URI,
                userId: null,
                autoConfirmChildren: false,
            );

            $parentLine = ReceivingScanLine::query()
                ->where('receiving_session_id', $scanFirst->getKey())
                ->where('line_role', 'parent')
                ->firstOrFail();

            $scanFirst->refresh();
            $this->assertSame(1, $scanFirst->expected_parent_count);
            $this->assertSame(1, $scanFirst->confirmed_parent_count);

            $updated = app(UnconfirmReceivingScanLine::class)->handle($parentLine->fresh());

            $this->assertSame('open', $updated->status);
            $this->assertSame(0, $updated->expected_parent_count);
            $this->assertSame(0, $updated->confirmed_parent_count);
            $this->assertNull($updated->completed_at);

            $this->assertSame(0, ReceivingScanLine::query()
                ->where('receiving_session_id', $scanFirst->getKey())
                ->where('line_role', 'parent')
                ->count());
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function it_blocks_unconfirm_when_session_is_completed(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $document = $this->ingestMinimalFixture();
            $this->documentId = (int) $document->getKey();

            $session = app(OpenReceivingSessionFromDocument::class)->handle($document);
            $parentLine = ReceivingScanLine::query()
                ->where('receiving_session_id', $session->id)
                ->where('line_role', 'parent')
                ->firstOrFail();

            app(ConfirmReceivingScan::class)->handle($session, self::SSCC_URI, autoConfirmChildren: true);

            // Auto-confirm completes the minimal fixture; force a completed gate without relying on events.
            $session->refresh()->forceFill([
                'status' => 'completed',
                'completed_at' => now(),
            ])->save();

            $this->expectException(DomainException::class);
            $this->expectExceptionMessage('already complete');

            app(UnconfirmReceivingScanLine::class)->handle($parentLine->fresh());
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function remove_action_is_hidden_when_session_completed(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $user = User::factory()->create([
                'email' => 'unconfirm-ui-'.uniqid('', true).'@example.test',
            ]);
            $this->userIds[] = (int) $user->getKey();
            $this->actingAs($user);

            $document = $this->ingestMinimalFixture();
            $this->documentId = (int) $document->getKey();

            $session = app(OpenReceivingSessionFromDocument::class)->handle($document);
            app(ConfirmReceivingScan::class)->handle($session, self::SSCC_URI, autoConfirmChildren: false);

            $parentLine = ReceivingScanLine::query()
                ->where('receiving_session_id', $session->id)
                ->where('line_role', 'parent')
                ->firstOrFail();

            Livewire::test(ScanLinesRelationManager::class, [
                'ownerRecord' => $session->fresh(),
                'pageClass' => ViewReceivingSession::class,
            ])
                ->call('loadTable')
                ->assertActionVisible(TestAction::make('removeScan')->table($parentLine));

            $session->forceFill([
                'status' => 'completed',
                'completed_at' => now(),
            ])->save();

            Livewire::test(ScanLinesRelationManager::class, [
                'ownerRecord' => $session->fresh(),
                'pageClass' => ViewReceivingSession::class,
            ])
                ->call('loadTable')
                ->assertActionHidden(TestAction::make('removeScan')->table($parentLine));
        } finally {
            $this->cleanup();
        }
    }

    private function createTransferSites(Tenant $tenant): array
    {
        $fromSite = Site::query()->create([
            'name' => 'Unconfirm Transfer From '.Str::random(6),
            'gln' => $this->uniqueGln(),
            'is_active' => true,
            'is_headquarters' => true,
            'trading_partner_id' => null,
            'is_organization_facility' => true,
        ]);
        $this->siteIds[] = (int) $fromSite->getKey();

        $toSite = Site::query()->create([
            'name' => 'Unconfirm Transfer To '.Str::random(6),
            'gln' => $this->uniqueGln(),
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

    private function receiveAtSite(Site $site, Epc $epc): void
    {
        $document = EpcisDocument::query()->create([
            'document_uuid' => (string) Str::uuid(),
            'schema_version' => '1.2',
            'creation_date' => now(),
            'received_at' => now(),
            'direction' => 'outbound',
            'status' => 'parsed',
            'original_filename' => 'unconfirm-transfer-custody.xml',
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
            if ($this->documentId !== null) {
                $session = ReceivingSession::query()->where('epcis_document_id', $this->documentId)->first();
                if ($session !== null && $session->receiving_epcis_document_id !== null) {
                    EpcisDocument::query()->whereKey($session->receiving_epcis_document_id)->delete();
                }
                ReceivingSession::query()->where('epcis_document_id', $this->documentId)->delete();
                EpcisDocument::query()->whereKey($this->documentId)->delete();
                $this->documentId = null;
            }

            foreach ($this->receivingSessionIds as $sessionId) {
                ReceivingScanLine::query()->where('receiving_session_id', $sessionId)->delete();
                ReceivingSession::query()->whereKey($sessionId)->delete();
            }
            $this->receivingSessionIds = [];

            foreach ($this->userIds as $userId) {
                User::query()->whereKey($userId)->delete();
            }
            $this->userIds = [];

            if ($this->transferSessionId !== null) {
                $receivingSessions = ReceivingSession::query()
                    ->where('transferring_session_id', $this->transferSessionId)
                    ->pluck('id');

                foreach ($receivingSessions as $sessionId) {
                    ReceivingScanLine::query()->where('receiving_session_id', $sessionId)->delete();
                    ReceivingSession::query()->whereKey($sessionId)->delete();
                }

                TransferringScanLine::query()
                    ->where('transferring_session_id', $this->transferSessionId)
                    ->delete();
                TransferringSession::query()->whereKey($this->transferSessionId)->delete();
                $this->transferSessionId = null;
            }

            if ($this->custodyEventIds !== []) {
                DB::table('event_epcs')->whereIn('event_id', $this->custodyEventIds)->delete();
                EpcisEvent::query()->whereIn('id', $this->custodyEventIds)->delete();
                $this->custodyEventIds = [];
            }

            if ($this->custodyDocumentIds !== []) {
                EpcisDocument::query()->whereIn('id', $this->custodyDocumentIds)->delete();
                $this->custodyDocumentIds = [];
            }

            foreach ($this->transferEpcIds as $epcId) {
                DB::table('document_epcs')->where('epc_id', $epcId)->delete();
                DB::table('event_epcs')->where('epc_id', $epcId)->delete();
                ReceivingScanLine::query()->where('epc_id', $epcId)->delete();
                TransferringScanLine::query()->where('epc_id', $epcId)->delete();
                Epc::query()->whereKey($epcId)->delete();
            }
            $this->transferEpcIds = [];

            if ($this->siteIds !== []) {
                Site::query()->whereIn('id', $this->siteIds)->delete();
                $this->siteIds = [];
            }

            $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);
            if ($tenant !== null && ($this->priorDefaultShipFromSiteId !== null || $this->priorDefaultReceiveSiteId !== null)) {
                $settings = TenantSettings::forTenant($tenant);
                $settings->setDefaultShipFromSiteId($this->priorDefaultShipFromSiteId);
                $settings->setDefaultReceiveSiteId($this->priorDefaultReceiveSiteId);
                $tenant->save();
                $this->priorDefaultShipFromSiteId = null;
                $this->priorDefaultReceiveSiteId = null;
            }

            if ($this->unexpectedEpcId !== null) {
                $epc = Epc::query()->find($this->unexpectedEpcId);
                if ($epc !== null && ! DB::table('event_epcs')->where('epc_id', $epc->id)->exists()) {
                    $epc->delete();
                }
                $this->unexpectedEpcId = null;
            }

            foreach ([self::SGTIN_URI, self::SSCC_URI] as $uri) {
                $epc = Epc::query()->where('epc_uri', $uri)->first();
                if ($epc !== null && ! DB::table('event_epcs')->where('epc_id', $epc->id)->exists()) {
                    $epc->delete();
                }
            }

            tenancy()->end();
        }
    }
}
