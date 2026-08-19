<?php

namespace Tests\Feature\Receiving;

use App\Actions\Receiving\CancelReceivingSession;
use App\Actions\Receiving\ConfirmReceivingScan;
use App\Actions\Receiving\OpenTransferReceivingSession;
use App\Actions\Transferring\CompleteTransferringSession;
use App\Actions\Transferring\ConfirmTransferringScan;
use App\Actions\Transferring\OpenTransferringSession;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Quarantine\QuarantineHold;
use App\Models\Receiving\ReceivingScanLine;
use App\Models\Receiving\ReceivingSession;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\Transferring\TransferringScanLine;
use App\Models\Transferring\TransferringSession;
use App\Models\User;
use App\Services\Quarantine\QuarantineService;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\Receiving\ResolveOpenReceiveUrl;
use App\Support\TenantSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OpenTransferReceivingSessionTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    private ?int $sessionId = null;

    private ?int $transferSessionId = null;

    private ?int $transferDocumentId = null;

    private ?int $epcId = null;

    private ?string $epcUri = null;

    /** @var list<int> */
    private array $custodyDocumentIds = [];

    /** @var list<int> */
    private array $custodyEventIds = [];

    /** @var list<int> */
    private array $siteIds = [];

    /** @var list<int> */
    private array $userIds = [];

    #[Test]
    public function confirm_receiving_scan_rejects_cancelled_transfer_receive_session(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            [$fromSite, $toSite] = $this->createTransferSites($tenant);
            $shipped = $this->shipOneEpc($fromSite, $toSite);

            $session = app(OpenTransferReceivingSession::class)->handle($shipped->fresh());
            $this->sessionId = (int) $session->getKey();

            app(CancelReceivingSession::class)->handle($session->fresh());
            $this->assertSame('cancelled', $session->fresh()->status);

            $epc = Epc::query()->findOrFail($this->epcId);
            $barcode = '(01)'.$epc->gtin14.'(21)'.$epc->serial_number;

            $result = app(ConfirmReceivingScan::class)->handle($session->fresh(), $barcode);

            $this->assertFalse($result['ok']);
            $this->assertSame('This receiving session is already closed.', $result['message']);
            $this->assertSame('not_in_session', $result['effect']);

            $line = ReceivingScanLine::query()
                ->where('receiving_session_id', $session->getKey())
                ->where('epc_id', $epc->getKey())
                ->firstOrFail();
            $this->assertSame('expected', $line->status);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function confirm_receiving_scan_rejects_completed_transfer_receive_session(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            [$fromSite, $toSite] = $this->createTransferSites($tenant);
            $shipped = $this->shipOneEpc($fromSite, $toSite);

            $session = app(OpenTransferReceivingSession::class)->handle($shipped->fresh());
            $this->sessionId = (int) $session->getKey();

            // Short-close: session marked completed while a line remains expected.
            $session->forceFill([
                'status' => 'completed',
                'completed_at' => now(),
            ])->save();

            $epc = Epc::query()->findOrFail($this->epcId);
            $barcode = '(01)'.$epc->gtin14.'(21)'.$epc->serial_number;

            $result = app(ConfirmReceivingScan::class)->handle($session->fresh(), $barcode);

            $this->assertFalse($result['ok']);
            $this->assertSame('This receiving session is already closed.', $result['message']);
            $this->assertSame('not_in_session', $result['effect']);

            $line = ReceivingScanLine::query()
                ->where('receiving_session_id', $session->getKey())
                ->where('epc_id', $epc->getKey())
                ->firstOrFail();
            $this->assertSame('expected', $line->status);

            $transferLine = TransferringScanLine::query()
                ->where('transferring_session_id', $shipped->getKey())
                ->where('epc_id', $epc->getKey())
                ->firstOrFail();
            $this->assertSame('confirmed', $transferLine->status);
            $this->assertNull($transferLine->received_at);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function confirm_receiving_scan_under_lock_on_closed_transfer_receive_does_not_mark_transfer_received(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            [$fromSite, $toSite] = $this->createTransferSites($tenant);
            $shipped = $this->shipOneEpc($fromSite, $toSite);

            $session = app(OpenTransferReceivingSession::class)->handle($shipped->fresh());
            $this->sessionId = (int) $session->getKey();

            $epc = Epc::query()->findOrFail($this->epcId);
            $barcode = '(01)'.$epc->gtin14.'(21)'.$epc->serial_number;

            DB::transaction(function () use ($session, $barcode, $shipped, $epc): void {
                $locked = ReceivingSession::query()
                    ->whereKey($session->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();
                $locked->forceFill([
                    'status' => 'cancelled',
                    'completed_at' => now(),
                ])->save();

                $result = app(ConfirmReceivingScan::class)->handle($locked->fresh(), $barcode);

                $this->assertFalse($result['ok']);
                $this->assertSame('not_in_session', $result['effect']);

                $transferLine = TransferringScanLine::query()
                    ->where('transferring_session_id', $shipped->getKey())
                    ->where('epc_id', $epc->getKey())
                    ->firstOrFail();
                $this->assertSame('confirmed', $transferLine->status);
                $this->assertNull($transferLine->received_at);
                $this->assertSame('in_transit', $shipped->fresh()->status);
            });
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function confirm_receiving_scan_quarantine_under_lock_leaves_transfer_line_unreceived(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            [$fromSite, $toSite] = $this->createTransferSites($tenant);
            $shipped = $this->shipOneEpc($fromSite, $toSite);

            $session = app(OpenTransferReceivingSession::class)->handle($shipped->fresh());
            $this->sessionId = (int) $session->getKey();

            $epc = Epc::query()->findOrFail($this->epcId);
            $barcode = '(01)'.$epc->gtin14.'(21)'.$epc->serial_number;

            app(QuarantineService::class)->quarantineFromFindRecall(
                epcIds: [(int) $epc->getKey()],
                reason: 'Block transfer receive under lock',
            );

            $result = app(ConfirmReceivingScan::class)->handle($session->fresh(), $barcode);

            $this->assertFalse($result['ok']);
            $this->assertSame('quarantined', $result['effect']);

            $line = ReceivingScanLine::query()
                ->where('receiving_session_id', $session->getKey())
                ->where('epc_id', $epc->getKey())
                ->firstOrFail();
            $this->assertSame('expected', $line->status);

            $transferLine = TransferringScanLine::query()
                ->where('transferring_session_id', $shipped->getKey())
                ->where('epc_id', $epc->getKey())
                ->firstOrFail();
            $this->assertSame('confirmed', $transferLine->status);
            $this->assertNull($transferLine->received_at);
            $this->assertSame('in_transit', $shipped->fresh()->status);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function cancel_then_reopen_leaves_transferring_lines_confirmed_not_received(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            [$fromSite, $toSite] = $this->createTransferSites($tenant);
            $shipped = $this->shipTwoEpcs($fromSite, $toSite);

            $session = app(OpenTransferReceivingSession::class)->handle($shipped->fresh());
            $this->sessionId = (int) $session->getKey();
            $this->assertSame('open', $session->status);

            $firstEpc = Epc::query()->findOrFail($this->epcId);
            $barcode = '(01)'.$firstEpc->gtin14.'(21)'.$firstEpc->serial_number;

            $confirm = app(ConfirmReceivingScan::class)->handle($session->fresh(), $barcode);
            $this->assertTrue($confirm['ok'], $confirm['message'] ?? 'confirm failed');
            $this->assertFalse($confirm['session_completed']);

            $transferAfterConfirm = $shipped->fresh();
            $this->assertSame('in_transit', $transferAfterConfirm->status);
            $this->assertSame(1, (int) $transferAfterConfirm->received_count);
            $this->assertSame(1, TransferringScanLine::query()
                ->where('transferring_session_id', $transferAfterConfirm->getKey())
                ->where('status', 'received')
                ->count());

            app(CancelReceivingSession::class)->handle($session->fresh());

            $transferAfterCancel = $shipped->fresh();
            $this->assertSame('cancelled', $session->fresh()->status);
            $this->assertSame('in_transit', $transferAfterCancel->status);
            $this->assertSame(0, (int) $transferAfterCancel->received_count);
            $this->assertNull($transferAfterCancel->received_at);
            $this->assertSame(0, TransferringScanLine::query()
                ->where('transferring_session_id', $transferAfterCancel->getKey())
                ->where('status', 'received')
                ->count());
            $this->assertSame(2, TransferringScanLine::query()
                ->where('transferring_session_id', $transferAfterCancel->getKey())
                ->where('status', 'confirmed')
                ->count());

            $reopened = app(OpenTransferReceivingSession::class)->handle($transferAfterCancel->fresh());
            $this->assertSame((int) $session->getKey(), (int) $reopened->getKey());
            $this->assertSame('open', $reopened->status);

            $transferAfterReopen = $shipped->fresh();
            $this->assertSame('in_transit', $transferAfterReopen->status);
            $this->assertSame(0, (int) $transferAfterReopen->received_count);
            $this->assertSame(0, TransferringScanLine::query()
                ->where('transferring_session_id', $transferAfterReopen->getKey())
                ->where('status', 'received')
                ->count());
            $this->assertSame(2, TransferringScanLine::query()
                ->where('transferring_session_id', $transferAfterReopen->getKey())
                ->where('status', 'confirmed')
                ->count());
            $this->assertSame(2, ReceivingScanLine::query()
                ->where('receiving_session_id', $reopened->getKey())
                ->where('line_role', 'parent')
                ->where('status', 'expected')
                ->count());
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function cancel_reopens_transfer_completed_solely_by_partial_receive(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            [$fromSite, $toSite] = $this->createTransferSites($tenant);
            $shipped = $this->shipTwoEpcs($fromSite, $toSite);

            $session = app(OpenTransferReceivingSession::class)->handle($shipped->fresh());
            $this->sessionId = (int) $session->getKey();

            $firstEpc = Epc::query()->findOrFail($this->epcId);
            $barcode = '(01)'.$firstEpc->gtin14.'(21)'.$firstEpc->serial_number;

            app(ConfirmReceivingScan::class)->handle($session->fresh(), $barcode);

            $transfer = $shipped->fresh();
            $transfer->forceFill([
                'status' => 'completed',
                'received_at' => now(),
                'completed_at' => now(),
            ])->save();

            app(CancelReceivingSession::class)->handle($session->fresh());

            $transferAfterCancel = $shipped->fresh();
            $this->assertSame('in_transit', $transferAfterCancel->status);
            $this->assertSame(0, (int) $transferAfterCancel->received_count);
            $this->assertNull($transferAfterCancel->received_at);
            $this->assertNull($transferAfterCancel->completed_at);
            $this->assertSame(2, TransferringScanLine::query()
                ->where('transferring_session_id', $transferAfterCancel->getKey())
                ->where('status', 'confirmed')
                ->count());

            $reopened = app(OpenTransferReceivingSession::class)->handle($transferAfterCancel->fresh());
            $this->assertSame('open', $reopened->status);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function it_reopens_a_cancelled_transfer_receive_session_with_fresh_expected_lines(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            [$fromSite, $toSite] = $this->createTransferSites($tenant);
            $shipped = $this->shipOneEpc($fromSite, $toSite);

            $session = app(OpenTransferReceivingSession::class)->handle($shipped->fresh());
            $this->sessionId = (int) $session->getKey();
            $this->assertSame('open', $session->status);

            app(CancelReceivingSession::class)->handle($session->fresh());
            $cancelled = $session->fresh();
            $this->assertSame('cancelled', $cancelled->status);
            $this->assertNotNull($cancelled->completed_at);

            $reopened = app(OpenTransferReceivingSession::class)->handle($shipped->fresh());
            $this->assertSame((int) $session->getKey(), (int) $reopened->getKey());
            $this->assertSame('open', $reopened->status);
            $this->assertNull($reopened->completed_at);
            $this->assertSame(0, $reopened->confirmed_parent_count);
            $this->assertSame(0, $reopened->confirmed_child_count);
            $this->assertSame(1, ReceivingScanLine::query()
                ->where('receiving_session_id', $reopened->getKey())
                ->where('line_role', 'parent')
                ->where('status', 'expected')
                ->count());
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function resolve_open_receive_url_reopens_cancelled_transfer_receive_on_scan(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            $user = User::factory()->create();
            $user->assignRole(TenantRole::Owner->value);
            $this->userIds[] = (int) $user->getKey();
            $this->actingAs($user);

            [$fromSite, $toSite] = $this->createTransferSites($tenant);
            $user->syncSites([(int) $fromSite->getKey(), (int) $toSite->getKey()]);
            $shipped = $this->shipOneEpc($fromSite, $toSite);

            $session = app(OpenTransferReceivingSession::class)->handle($shipped->fresh());
            $this->sessionId = (int) $session->getKey();

            app(CancelReceivingSession::class)->handle($session->fresh());
            $this->assertSame('cancelled', $session->fresh()->status);

            $epc = Epc::query()->findOrFail($this->epcId);
            $barcode = '(01)'.$epc->gtin14.'(21)'.$epc->serial_number;

            $this->assertNull(
                app(ResolveOpenReceiveUrl::class)->previewUrl($barcode),
                'Preview links must not point at a cancelled transfer receive session.',
            );

            $url = app(ResolveOpenReceiveUrl::class)->handle($barcode);
            $this->assertNotNull($url);
            $this->assertStringContainsString('receiving-sessions/'.$session->getKey(), $url);

            $reopened = ReceivingSession::query()->findOrFail($session->getKey());
            $this->assertSame('open', $reopened->status);
            $this->assertNull($reopened->completed_at);
        } finally {
            $this->cleanup($tenant);
        }
    }

    /**
     * @return array{0: Site, 1: Site}
     */
    private function createTransferSites(Tenant $tenant): array
    {
        $fromSite = Site::query()->create([
            'name' => 'Transfer Receive Reopen From '.Str::random(6),
            'gln' => $this->uniqueGln(),
            'is_active' => true,
            'is_headquarters' => true,
            'trading_partner_id' => null,
            'is_organization_facility' => true,
        ]);
        $this->siteIds[] = (int) $fromSite->getKey();

        $toSite = Site::query()->create([
            'name' => 'Transfer Receive Reopen To '.Str::random(6),
            'gln' => $this->uniqueGln(),
            'is_active' => true,
            'is_headquarters' => false,
            'trading_partner_id' => null,
            'is_organization_facility' => true,
        ]);
        $this->siteIds[] = (int) $toSite->getKey();

        $settings = TenantSettings::forTenant($tenant);
        $settings->setDefaultShipFromSiteId((int) $fromSite->getKey());
        $settings->setDefaultReceiveSiteId((int) $toSite->getKey());
        $tenant->save();

        return [$fromSite, $toSite];
    }

    private function shipTwoEpcs(Site $fromSite, Site $toSite): TransferringSession
    {
        $transfer = app(OpenTransferringSession::class)->handle(
            fromSiteId: (int) $fromSite->getKey(),
            toSiteId: (int) $toSite->getKey(),
        );
        $this->transferSessionId = (int) $transfer->getKey();

        foreach ([1, 2] as $index) {
            $suffix = (string) random_int(10000000, 99999999);
            $uri = 'urn:epc:id:sgtin:030116.3'.substr($suffix, 0, 6).'.TR'.$suffix.$index;

            $epc = Epc::query()->create(Epc::materializeAttributesFromUri($uri));
            if ($index === 1) {
                $this->epcId = (int) $epc->getKey();
                $this->epcUri = $uri;
            }

            $this->receiveAtSite($fromSite, $epc);
            app(ConfirmTransferringScan::class)->handle($transfer->fresh(), $uri);
        }

        $shipped = app(CompleteTransferringSession::class)->handle($transfer->fresh());
        $this->transferDocumentId = (int) $shipped->transfer_epcis_document_id;

        return $shipped;
    }

    private function shipOneEpc(Site $fromSite, Site $toSite): TransferringSession
    {
        $suffix = (string) random_int(10000000, 99999999);
        $this->epcUri = 'urn:epc:id:sgtin:030116.3'.substr($suffix, 0, 6).'.TR'.$suffix;

        $transfer = app(OpenTransferringSession::class)->handle(
            fromSiteId: (int) $fromSite->getKey(),
            toSiteId: (int) $toSite->getKey(),
        );
        $this->transferSessionId = (int) $transfer->getKey();

        $epc = Epc::query()->create(Epc::materializeAttributesFromUri($this->epcUri));
        $this->epcId = (int) $epc->getKey();
        $this->receiveAtSite($fromSite, $epc);

        app(ConfirmTransferringScan::class)->handle($transfer, $this->epcUri);

        $shipped = app(CompleteTransferringSession::class)->handle($transfer->fresh());
        $this->transferDocumentId = (int) $shipped->transfer_epcis_document_id;

        return $shipped;
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
            'original_filename' => 'transfer-receive-reopen-custody.xml',
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
        do {
            $gln = '0366159'.str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
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

    private function cleanup(Tenant $tenant): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        if ($this->sessionId !== null) {
            ReceivingSession::query()->whereKey($this->sessionId)->delete();
            $this->sessionId = null;
        }

        if ($this->transferDocumentId !== null) {
            $doc = EpcisDocument::query()->find($this->transferDocumentId);
            if ($doc !== null && filled($doc->payload_path)) {
                Storage::disk($doc->payload_disk)->delete($doc->payload_path);
            }
            EpcisDocument::query()->whereKey($this->transferDocumentId)->delete();
            $this->transferDocumentId = null;
        }

        if ($this->transferSessionId !== null) {
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

        if ($this->epcId !== null) {
            ReceivingScanLine::query()->where('epc_id', $this->epcId)->delete();
            QuarantineHold::query()->where('epc_id', $this->epcId)->delete();
            DB::table('event_epcs')->where('epc_id', $this->epcId)->delete();
            Epc::query()->whereKey($this->epcId)->delete();
            $this->epcId = null;
        }

        if ($this->siteIds !== []) {
            Site::query()->whereIn('id', $this->siteIds)->delete();
            $this->siteIds = [];
        }

        if ($this->userIds !== []) {
            User::query()->whereIn('id', $this->userIds)->delete();
            $this->userIds = [];
        }

        tenancy()->end();
    }
}
