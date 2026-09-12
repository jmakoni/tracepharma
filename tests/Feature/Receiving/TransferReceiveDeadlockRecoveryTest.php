<?php

namespace Tests\Feature\Receiving;

use App\Actions\Receiving\CompleteReceivingSession;
use App\Actions\Receiving\ConfirmReceivingScan;
use App\Actions\Receiving\OpenTransferReceivingSession;
use App\Actions\Receiving\ResetReceivingSessionScans;
use App\Actions\Receiving\UnpackReceivingHierarchy;
use App\Actions\Transferring\CompleteTransferringSession;
use App\Actions\Transferring\ConfirmTransferringScan;
use App\Actions\Transferring\OpenTransferringSession;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Resources\ReceivingSessions\Pages\ViewReceivingSession;
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
use App\Support\Auth\TenantRoleSeeder;
use App\Support\Gs1\Gtin;
use App\Support\TenantSettings;
use DomainException;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TransferReceiveDeadlockRecoveryTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $siteIds = [];

    /** @var list<int> */
    private array $userIds = [];

    /** @var list<int> */
    private array $extraEpcIds = [];

    /** @var list<int> */
    private array $custodyDocumentIds = [];

    /** @var list<int> */
    private array $custodyEventIds = [];

    private ?int $sessionId = null;

    private ?int $receivingSessionId = null;

    private ?int $transferDocumentId = null;

    private ?int $epcId = null;

    private ?string $fromGln = null;

    private ?string $toGln = null;

    private ?int $priorDefaultShipFromSiteId = null;

    private ?int $priorDefaultReceiveSiteId = null;

    private ?string $priorCompanyPrefix = null;

    private ?string $priorTenantGln = null;

    #[Test]
    public function manual_complete_is_available_when_transfer_receive_stuck_after_epcis_failure(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));

            [$fromSite, $toSite] = $this->createTransferSites($tenant);
            [$receiving] = $this->stuckCompletedTransferReceive($fromSite, $toSite);

            // Simulate last-scan complete that failed after confirming lines: session
            // reverted to in_progress with no expected lines left.
            $receiving->forceFill([
                'status' => 'in_progress',
                'completed_at' => null,
            ])->save();

            $this->actingAs($this->createUserWithSites([(int) $toSite->getKey()]));

            $component = Livewire::test(ViewReceivingSession::class, ['record' => $receiving->getKey()]);

            $this->assertTrue($component->instance()->canCompleteManually());
            $component->assertActionVisible('completeReceiving');
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function retry_receive_epcis_action_authors_events_for_completed_transfer_receive_without_epcis(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            config(['tracepharma.regulatory_compliance.password_gate' => false]);
            Filament::setCurrentPanel(Filament::getPanel('app'));

            [$fromSite, $toSite] = $this->createTransferSites($tenant);
            [$receiving, $transfer] = $this->stuckCompletedTransferReceive($fromSite, $toSite);

            $this->actingAs($this->createUserWithSites([(int) $toSite->getKey()]));

            Livewire::test(ViewReceivingSession::class, ['record' => $receiving->getKey()])
                ->assertActionVisible('retryReceiveEpcis')
                ->callAction('retryReceiveEpcis')
                ->assertHasNoActionErrors();

            $transfer->refresh();
            $this->assertNotNull($transfer->receive_events_generated_at);
            $this->assertSame(
                1,
                EpcisEvent::query()
                    ->where('document_id', $this->transferDocumentId)
                    ->where('biz_step', 'urn:epcglobal:cbv:bizstep:receiving')
                    ->count(),
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function reset_scans_recovers_completed_transfer_receive_without_receive_epcis(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            config(['tracepharma.regulatory_compliance.password_gate' => false]);
            Filament::setCurrentPanel(Filament::getPanel('app'));

            [$fromSite, $toSite] = $this->createTransferSites($tenant);
            [$receiving, $transfer] = $this->stuckCompletedTransferReceive($fromSite, $toSite);

            $this->actingAs($this->createUserWithSites([(int) $toSite->getKey()]));

            Livewire::test(ViewReceivingSession::class, ['record' => $receiving->getKey()])
                ->assertActionVisible('resetScans')
                ->callAction('resetScans')
                ->assertHasNoActionErrors();

            $receiving->refresh();
            $transfer->refresh();

            $this->assertSame('open', $receiving->status);
            $this->assertNull($receiving->completed_at);
            $this->assertSame(0, (int) $receiving->confirmed_parent_count);
            $this->assertSame('in_transit', $transfer->status);
            $this->assertNull($transfer->completed_at);
            $this->assertSame(0, (int) $transfer->received_count);
            $this->assertSame(
                'expected',
                ReceivingScanLine::query()
                    ->where('receiving_session_id', $receiving->getKey())
                    ->value('status'),
            );
            $this->assertSame(
                'confirmed',
                TransferringScanLine::query()
                    ->where('transferring_session_id', $transfer->getKey())
                    ->where('epc_id', $this->epcId)
                    ->value('status'),
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function reset_receiving_session_scans_action_clears_confirmed_lines_on_deadlock(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            [$fromSite, $toSite] = $this->createTransferSites($tenant);
            [$receiving] = $this->stuckCompletedTransferReceive($fromSite, $toSite);

            $reset = app(ResetReceivingSessionScans::class)->handle($receiving->fresh());

            $this->assertSame('open', $reset->status);
            $this->assertSame(0, (int) $reset->confirmed_parent_count);
            $this->assertSame(
                0,
                ReceivingScanLine::query()
                    ->where('receiving_session_id', $reset->getKey())
                    ->where('status', 'confirmed')
                    ->count(),
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function close_with_shortage_action_marks_completed_under_lock_and_authors_epcis(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $suffix = (string) random_int(10000000, 99999999);
        $receivedUri = 'urn:epc:id:sgtin:030116.3'.substr($suffix, 0, 6).'.SH'.$suffix;
        $missingUri = 'urn:epc:id:sgtin:030116.3'.substr((string) ($suffix + 1), 0, 6).'.MI'.($suffix + 1);

        try {
            config(['tracepharma.regulatory_compliance.password_gate' => false]);
            Filament::setCurrentPanel(Filament::getPanel('app'));

            [$fromSite, $toSite] = $this->createTransferSites($tenant);

            $session = app(OpenTransferringSession::class)->handle(
                fromSiteId: (int) $fromSite->getKey(),
                toSiteId: (int) $toSite->getKey(),
            );
            $this->sessionId = (int) $session->getKey();

            $receivedEpc = Epc::query()->create(Epc::materializeAttributesFromUri($receivedUri));
            $missingEpc = Epc::query()->create(Epc::materializeAttributesFromUri($missingUri));
            $this->epcId = (int) $receivedEpc->getKey();
            $this->extraEpcIds[] = (int) $missingEpc->getKey();

            foreach ([$receivedEpc, $missingEpc] as $epc) {
                $this->receiveAtSite($fromSite, $epc);
            }

            app(ConfirmTransferringScan::class)->handle($session, $receivedUri);
            app(ConfirmTransferringScan::class)->handle($session->fresh(), $missingUri);

            $shipped = app(CompleteTransferringSession::class)->handle($session->fresh());
            $this->transferDocumentId = (int) $shipped->transfer_epcis_document_id;

            $receiving = app(OpenTransferReceivingSession::class)->handle($shipped->fresh());
            $this->receivingSessionId = (int) $receiving->getKey();

            $barcode = '(01)'.$receivedEpc->gtin14.'(21)'.$receivedEpc->serial_number;
            app(ConfirmReceivingScan::class)->handle($receiving->fresh(), $barcode);

            $this->actingAs($this->createUserWithSites([(int) $toSite->getKey()]));

            Livewire::test(ViewReceivingSession::class, ['record' => $receiving->getKey()])
                ->assertActionVisible('closeTransferWithShortage')
                ->callAction('closeTransferWithShortage')
                ->assertHasNoActionErrors();

            $receiving->refresh();
            $shipped->refresh();

            $this->assertSame('completed', $receiving->status);
            $this->assertNotNull($receiving->completed_at);
            $this->assertSame('completed', $shipped->status);
            $this->assertSame(1, (int) $shipped->received_count);
            $this->assertNotNull($shipped->receive_events_generated_at);
            $this->assertSame(
                1,
                EpcisEvent::query()
                    ->where('document_id', $this->transferDocumentId)
                    ->where('biz_step', 'urn:epcglobal:cbv:bizstep:receiving')
                    ->count(),
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function complete_receiving_session_short_close_marks_completed_under_lock(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $suffix = (string) random_int(10000000, 99999999);
        $receivedUri = 'urn:epc:id:sgtin:030116.3'.substr($suffix, 0, 6).'.SL'.$suffix;
        $missingUri = 'urn:epc:id:sgtin:030116.3'.substr((string) ($suffix + 1), 0, 6).'.ML'.($suffix + 1);

        try {
            [$fromSite, $toSite] = $this->createTransferSites($tenant);

            $session = app(OpenTransferringSession::class)->handle(
                fromSiteId: (int) $fromSite->getKey(),
                toSiteId: (int) $toSite->getKey(),
            );
            $this->sessionId = (int) $session->getKey();

            $receivedEpc = Epc::query()->create(Epc::materializeAttributesFromUri($receivedUri));
            $missingEpc = Epc::query()->create(Epc::materializeAttributesFromUri($missingUri));
            $this->epcId = (int) $receivedEpc->getKey();
            $this->extraEpcIds[] = (int) $missingEpc->getKey();

            foreach ([$receivedEpc, $missingEpc] as $epc) {
                $this->receiveAtSite($fromSite, $epc);
            }

            app(ConfirmTransferringScan::class)->handle($session, $receivedUri);
            app(ConfirmTransferringScan::class)->handle($session->fresh(), $missingUri);

            $shipped = app(CompleteTransferringSession::class)->handle($session->fresh());
            $this->transferDocumentId = (int) $shipped->transfer_epcis_document_id;

            $receiving = app(OpenTransferReceivingSession::class)->handle($shipped->fresh());
            $this->receivingSessionId = (int) $receiving->getKey();

            $barcode = '(01)'.$receivedEpc->gtin14.'(21)'.$receivedEpc->serial_number;
            app(ConfirmReceivingScan::class)->handle($receiving->fresh(), $barcode);

            $this->assertNotSame('completed', $receiving->fresh()->status);

            app(CompleteReceivingSession::class)->handle($receiving->fresh(), null, shortClose: true);

            $receiving->refresh();
            $shipped->refresh();

            $this->assertSame('completed', $receiving->status);
            $this->assertNotNull($receiving->completed_at);
            $this->assertSame(1, (int) $shipped->received_count);
            $this->assertNotNull($shipped->receive_events_generated_at);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function unpack_is_denied_when_receiving_session_completed_without_receiving_epcis(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $tenant->forceFill(['profile' => TenantProfile::DrugWholesaler])->save();
            tenancy()->end();
            tenancy()->initialize($tenant->fresh());

            $session = ReceivingSession::query()->create([
                'session_kind' => \App\Enums\ReceivingSessionKind::InboundAsn,
                'status' => 'completed',
                'opened_at' => now(),
                'completed_at' => now(),
                'expected_parent_count' => 1,
                'confirmed_parent_count' => 1,
                'expected_child_count' => 0,
                'confirmed_child_count' => 0,
                'receiving_events_generated_at' => null,
            ]);
            $this->receivingSessionId = (int) $session->getKey();

            config(['tracepharma.regulatory_compliance.password_gate' => false]);
            Filament::setCurrentPanel(Filament::getPanel('app'));

            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::DrugWholesaler);
            $user = User::factory()->create();
            $user->assignRole(TenantRole::PharmacySystemAdministrator->value);
            $this->userIds[] = (int) $user->getKey();
            $this->actingAs($user);

            Livewire::test(ViewReceivingSession::class, ['record' => $session->getKey()])
                ->assertActionHidden('unpackHierarchy');

            $this->expectException(DomainException::class);
            $this->expectExceptionMessage('receiving EPCIS events');

            app(UnpackReceivingHierarchy::class)->handle($session->fresh());
        } finally {
            Tenant::query()->whereKey(self::DEMO2_TENANT_ID)->update([
                'profile' => TenantProfile::Pharmacy->value,
            ]);
            $this->cleanup($tenant);
        }
    }

    /**
     * @return array{0: ReceivingSession, 1: TransferringSession}
     */
    private function stuckCompletedTransferReceive(Site $fromSite, Site $toSite): array
    {
        $suffix = (string) random_int(10000000, 99999999);
        $epcUri = 'urn:epc:id:sgtin:030116.3'.substr($suffix, 0, 6).'.DL'.$suffix;

        $session = app(OpenTransferringSession::class)->handle(
            fromSiteId: (int) $fromSite->getKey(),
            toSiteId: (int) $toSite->getKey(),
        );
        $this->sessionId = (int) $session->getKey();

        $epc = Epc::query()->create(Epc::materializeAttributesFromUri($epcUri));
        $this->epcId = (int) $epc->getKey();
        $this->receiveAtSite($fromSite, $epc);

        app(ConfirmTransferringScan::class)->handle($session, $epcUri);
        $shipped = app(CompleteTransferringSession::class)->handle($session->fresh());
        $this->transferDocumentId = (int) $shipped->transfer_epcis_document_id;

        $receiving = app(OpenTransferReceivingSession::class)->handle($shipped->fresh());
        $this->receivingSessionId = (int) $receiving->getKey();

        $now = now();

        ReceivingScanLine::query()
            ->where('receiving_session_id', $receiving->getKey())
            ->where('epc_id', $this->epcId)
            ->update([
                'status' => 'confirmed',
                'scan_raw' => '(01)'.$epc->gtin14.'(21)'.$epc->serial_number,
                'confirmed_at' => $now,
                'updated_at' => $now,
            ]);

        TransferringScanLine::query()
            ->where('transferring_session_id', $shipped->getKey())
            ->where('epc_id', $this->epcId)
            ->update([
                'status' => 'received',
                'received_at' => $now,
                'updated_at' => $now,
            ]);

        $receiving->forceFill([
            'status' => 'completed',
            'confirmed_parent_count' => 1,
            'completed_at' => $now,
        ])->save();

        $shipped->forceFill([
            'status' => 'completed',
            'received_count' => 1,
            'received_at' => $now,
            'completed_at' => $now,
            'receive_events_generated_at' => null,
        ])->save();

        $receiving->refresh();
        $shipped->refresh();

        $this->assertNull($shipped->receive_events_generated_at);

        return [$receiving, $shipped];
    }

    /**
     * @return array{0: Site, 1: Site}
     */
    private function createTransferSites(Tenant $tenant): array
    {
        $this->fromGln = $this->uniqueGln();
        $this->toGln = $this->uniqueGln();

        $fromSite = Site::query()->create([
            'name' => 'Transfer From '.Str::random(6),
            'gln' => $this->fromGln,
            'is_active' => true,
            'is_headquarters' => true,
            'trading_partner_id' => null,
            'is_organization_facility' => true,
        ]);
        $this->siteIds[] = (int) $fromSite->getKey();

        $toSite = Site::query()->create([
            'name' => 'Transfer To '.Str::random(6),
            'gln' => $this->toGln,
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

    /**
     * @param  list<int>  $siteIds
     */
    private function createUserWithSites(array $siteIds): User
    {
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);

        $user = User::factory()->create();
        $user->syncSites($siteIds);
        $this->userIds[] = (int) $user->getKey();

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
            'original_filename' => 'transfer-custody-receipt.xml',
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

        $this->priorCompanyPrefix = $tenant->company_prefix;
        $this->priorTenantGln = $tenant->gln;
        if (blank($tenant->company_prefix)) {
            $tenant->forceFill([
                'company_prefix' => '030116',
                'gln' => $tenant->gln ?: '0369108777802',
            ])->save();
        }

        tenancy()->initialize($tenant->fresh());

        return $tenant;
    }

    private function cleanup(Tenant $tenant): void
    {
        if (! tenancy()->initialized) {
            return;
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

        if ($this->receivingSessionId !== null) {
            ReceivingScanLine::query()->where('receiving_session_id', $this->receivingSessionId)->delete();
            ReceivingSession::query()->whereKey($this->receivingSessionId)->delete();
            $this->receivingSessionId = null;
        }

        if ($this->transferDocumentId !== null) {
            $document = EpcisDocument::query()->find($this->transferDocumentId);
            if ($document !== null && filled($document->payload_path)) {
                Storage::disk($document->payload_disk)->delete($document->payload_path);
            }
            EpcisDocument::query()->whereKey($this->transferDocumentId)->delete();
            $this->transferDocumentId = null;
        }

        if ($this->sessionId !== null) {
            TransferringScanLine::query()->where('transferring_session_id', $this->sessionId)->delete();
            TransferringSession::query()->whereKey($this->sessionId)->delete();
            $this->sessionId = null;
        }

        foreach ($this->extraEpcIds as $extraEpcId) {
            DB::table('event_epcs')->where('epc_id', $extraEpcId)->delete();
            if (Schema::hasTable('document_epcs')) {
                DB::table('document_epcs')->where('epc_id', $extraEpcId)->delete();
            }
            TransferringScanLine::query()->where('epc_id', $extraEpcId)->delete();
            Epc::query()->whereKey($extraEpcId)->delete();
        }
        $this->extraEpcIds = [];

        if ($this->epcId !== null) {
            DB::table('event_epcs')->where('epc_id', $this->epcId)->delete();
            if (Schema::hasTable('document_epcs')) {
                DB::table('document_epcs')->where('epc_id', $this->epcId)->delete();
            }
            TransferringScanLine::query()->where('epc_id', $this->epcId)->delete();
            Epc::query()->whereKey($this->epcId)->delete();
            $this->epcId = null;
        }

        if ($this->userIds !== []) {
            User::query()->whereIn('id', $this->userIds)->delete();
            $this->userIds = [];
        }

        if ($this->siteIds !== []) {
            Site::query()->whereIn('id', $this->siteIds)->delete();
            $this->siteIds = [];
        }

        $settings = TenantSettings::forTenant($tenant);
        $settings->setDefaultShipFromSiteId($this->priorDefaultShipFromSiteId);
        $settings->setDefaultReceiveSiteId($this->priorDefaultReceiveSiteId);
        $tenant->save();
        $this->priorDefaultShipFromSiteId = null;
        $this->priorDefaultReceiveSiteId = null;

        if ($this->priorCompanyPrefix !== null || $this->priorTenantGln !== null) {
            $restored = Tenant::query()->find(self::DEMO2_TENANT_ID);
            if ($restored !== null) {
                $restored->forceFill([
                    'company_prefix' => $this->priorCompanyPrefix,
                    'gln' => $this->priorTenantGln,
                ])->save();
            }
            $this->priorCompanyPrefix = null;
            $this->priorTenantGln = null;
        }

        auth()->logout();
        tenancy()->end();
    }
}
