<?php

namespace Tests\Feature\Receiving;

use App\Actions\Receiving\AttachReceivingSessionInvoice;
use App\Actions\Receiving\CancelReceivingSession;
use App\Actions\Receiving\ConfirmReceivingScan;
use App\Actions\Receiving\DeleteReceivingSession;
use App\Actions\Receiving\OpenScanFirstReceivingSession;
use App\Actions\Receiving\OpenTransferReceivingSession;
use App\Actions\Transferring\CompleteTransferringSession;
use App\Actions\Transferring\ConfirmTransferringScan;
use App\Actions\Transferring\OpenTransferringSession;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Resources\ReceivingSessions\Pages\MobileViewReceivingSession;
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
use App\Support\Floor\UnsubmittedSessionDelete;
use App\Support\TenantSettings;
use DomainException;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class DeleteReceivingSessionTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $sessionIds = [];

    /** @var list<int> */
    private array $transferSessionIds = [];

    /** @var list<int> */
    private array $siteIds = [];

    /** @var list<int> */
    private array $epcIds = [];

    /** @var list<int> */
    private array $userIds = [];

    /** @var list<int> */
    private array $custodyDocumentIds = [];

    /** @var list<int> */
    private array $custodyEventIds = [];

    /** @var list<int> */
    private array $transferDocumentIds = [];

    private ?int $priorDefaultShipFromSiteId = null;

    private ?int $priorDefaultReceiveSiteId = null;

    #[Test]
    public function it_hard_deletes_empty_open_and_in_progress_sessions(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $open = app(OpenScanFirstReceivingSession::class)->handle();
            $this->trackSession($open);
            $this->assertTrue($open->canHardDelete());

            app(DeleteReceivingSession::class)->handle($open);
            $this->assertNull(ReceivingSession::query()->find($open->getKey()));

            $inProgress = app(OpenScanFirstReceivingSession::class)->handle();
            $this->trackSession($inProgress);
            $inProgress->forceFill(['status' => 'in_progress'])->save();

            app(DeleteReceivingSession::class)->handle($inProgress->fresh());
            $this->assertNull(ReceivingSession::query()->find($inProgress->getKey()));
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function it_hard_deletes_sessions_with_confirmed_scans(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $session = app(OpenScanFirstReceivingSession::class)->handle();
            $this->trackSession($session);

            $uri = 'urn:epc:id:sgtin:030116.3'.substr((string) random_int(10000000, 99999999), 0, 6).'.DEL1';
            $epc = Epc::query()->create(Epc::materializeAttributesFromUri($uri));
            $this->epcIds[] = (int) $epc->getKey();

            $result = app(ConfirmReceivingScan::class)->handle($session, $uri);
            $this->assertTrue($result['ok']);

            $session = $session->fresh();
            $this->assertGreaterThan(0, (int) $session->confirmed_parent_count + (int) $session->confirmed_child_count);
            $this->assertTrue(ReceivingScanLine::query()->where('receiving_session_id', $session->getKey())->exists());

            app(DeleteReceivingSession::class)->handle($session);

            $this->assertNull(ReceivingSession::query()->find($session->getKey()));
            $this->assertFalse(ReceivingScanLine::query()->where('receiving_session_id', $session->getKey())->exists());
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function it_removes_invoice_blob_when_hard_deleting(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $session = app(OpenScanFirstReceivingSession::class)->handle();
            $this->trackSession($session);

            $tmp = $this->writeTempInvoice('delete-session invoice '.random_int(100000, 999999), 'invoice.pdf');

            try {
                $session = app(AttachReceivingSessionInvoice::class)->handle(
                    $session,
                    $tmp,
                    'invoice.pdf',
                );
            } finally {
                @unlink($tmp);
            }

            $disk = (string) $session->fresh()->invoice_disk;
            $path = (string) $session->fresh()->invoice_path;
            $this->assertTrue(Storage::disk($disk)->exists($path));

            app(DeleteReceivingSession::class)->handle($session->fresh());

            $this->assertFalse(Storage::disk($disk)->exists($path));
            $this->assertNull(ReceivingSession::query()->find($session->getKey()));
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function it_blocks_completed_cancelled_and_authored_receiving_epcis(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $completed = app(OpenScanFirstReceivingSession::class)->handle();
            $this->trackSession($completed);
            $completed->forceFill(['status' => 'completed', 'completed_at' => now()])->save();
            $this->assertFalse($completed->fresh()->canHardDelete());

            try {
                app(DeleteReceivingSession::class)->handle($completed->fresh());
                $this->fail('Expected DomainException for completed session.');
            } catch (DomainException $e) {
                $this->assertStringContainsString('completed', $e->getMessage());
            }

            $cancelled = app(OpenScanFirstReceivingSession::class)->handle();
            $this->trackSession($cancelled);
            $cancelled->forceFill(['status' => 'cancelled', 'completed_at' => now()])->save();

            try {
                app(DeleteReceivingSession::class)->handle($cancelled->fresh());
                $this->fail('Expected DomainException for cancelled session.');
            } catch (DomainException $e) {
                $this->assertStringContainsString('cancelled', $e->getMessage());
            }

            $authored = app(OpenScanFirstReceivingSession::class)->handle();
            $this->trackSession($authored);
            $authored->forceFill([
                'status' => 'in_progress',
                'receiving_events_generated_at' => now(),
            ])->save();
            $this->assertFalse($authored->fresh()->canHardDelete());

            try {
                app(DeleteReceivingSession::class)->handle($authored->fresh());
                $this->fail('Expected DomainException for authored receiving EPCIS.');
            } catch (DomainException $e) {
                $this->assertStringContainsString('authored receiving EPCIS', $e->getMessage());
            }
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function transfer_receive_delete_reverts_transferring_marks(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            [$fromSite, $toSite] = $this->createTransferSites($tenant);
            $owner = User::factory()->create([
                'email' => 'delete-transfer-receive-'.uniqid('', true).'@example.test',
            ]);
            $owner->assignRole(TenantRole::Owner->value);
            $owner->syncSites([(int) $fromSite->getKey(), (int) $toSite->getKey()]);
            $this->userIds[] = (int) $owner->getKey();
            $this->actingAs($owner);

            $shipped = $this->shipTwoEpcs($fromSite, $toSite);

            $session = app(OpenTransferReceivingSession::class)->handle($shipped->fresh());
            $this->trackSession($session);

            $epc = Epc::query()->findOrFail($this->epcIds[0]);
            $barcode = '(01)'.$epc->gtin14.'(21)'.$epc->serial_number;
            app(ConfirmReceivingScan::class)->handle($session->fresh(), $barcode);

            $session = $session->fresh();
            $this->assertContains($session->status, ['open', 'in_progress']);

            $transfer = TransferringSession::query()->findOrFail($shipped->getKey());
            $this->assertSame(1, (int) $transfer->fresh()->received_count);
            $this->assertSame('received', TransferringScanLine::query()
                ->where('transferring_session_id', $transfer->getKey())
                ->where('epc_id', $epc->getKey())
                ->value('status'));

            app(DeleteReceivingSession::class)->handle($session);

            $this->assertNull(ReceivingSession::query()->find($session->getKey()));
            $transfer = $transfer->fresh();
            $this->assertSame('in_transit', $transfer->status);
            $this->assertSame(0, (int) $transfer->received_count);
            $this->assertSame('confirmed', TransferringScanLine::query()
                ->where('transferring_session_id', $transfer->getKey())
                ->where('epc_id', $epc->getKey())
                ->value('status'));
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function it_refuses_hard_delete_when_transfer_receive_epcis_authored(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            [$fromSite, $toSite] = $this->createTransferSites($tenant);
            $owner = User::factory()->create([
                'email' => 'delete-transfer-receive-authored-'.uniqid('', true).'@example.test',
            ]);
            $owner->assignRole(TenantRole::Owner->value);
            $owner->syncSites([(int) $fromSite->getKey(), (int) $toSite->getKey()]);
            $this->userIds[] = (int) $owner->getKey();
            $this->actingAs($owner);

            $shipped = $this->shipTwoEpcs($fromSite, $toSite);

            $session = app(OpenTransferReceivingSession::class)->handle($shipped->fresh());
            $this->trackSession($session);

            $epc = Epc::query()->findOrFail($this->epcIds[0]);
            $barcode = '(01)'.$epc->gtin14.'(21)'.$epc->serial_number;
            app(ConfirmReceivingScan::class)->handle($session->fresh(), $barcode);

            $transfer = TransferringSession::query()->findOrFail($shipped->getKey());
            $transfer->forceFill(['receive_events_generated_at' => now()])->save();

            $session = $session->fresh();
            $this->assertFalse($session->canHardDelete());

            try {
                app(DeleteReceivingSession::class)->handle($session);
                $this->fail('Expected DomainException for authored transfer receive EPCIS.');
            } catch (DomainException $e) {
                $this->assertStringContainsString('transfer receive EPCIS', $e->getMessage());
            }

            $this->assertNotNull(ReceivingSession::query()->find($session->getKey()));
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function user_without_site_access_cannot_delete_receive(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            [$siteA, $siteB] = $this->createOwnedSites($tenant);

            $owner = User::factory()->create([
                'email' => 'delete-receive-owner-'.uniqid('', true).'@example.test',
            ]);
            $this->userIds[] = (int) $owner->getKey();
            $owner->assignRole(TenantRole::Owner->value);
            $this->actingAs($owner);

            $session = app(OpenScanFirstReceivingSession::class)->handle((int) $siteB->getKey());
            $this->trackSession($session);

            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            $user = User::factory()->create([
                'email' => 'delete-receive-denied-'.uniqid('', true).'@example.test',
            ]);
            $user->syncSites([(int) $siteA->getKey()]);
            $this->userIds[] = (int) $user->getKey();
            $this->actingAs($user);

            try {
                app(DeleteReceivingSession::class)->handle($session->fresh());
                $this->fail('Expected AuthorizationException for cross-site receive delete.');
            } catch (AuthorizationException) {
                // expected
            }
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function cancel_still_soft_deletes_history(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $session = app(OpenScanFirstReceivingSession::class)->handle();
            $this->trackSession($session);

            $cancelled = app(CancelReceivingSession::class)->handle($session);
            $this->assertSame('cancelled', $cancelled->status);
            $this->assertNotNull(ReceivingSession::query()->find($session->getKey()));
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function delete_receiving_action_is_visible_on_active_view_and_deletes(): void
    {
        $this->initializeDemo2Tenant();

        try {
            config(['tracepharma.regulatory_compliance.password_gate' => false]);
            Filament::setCurrentPanel(Filament::getPanel('app'));
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);

            $user = User::factory()->create([
                'email' => 'delete-receive-ui-'.uniqid('', true).'@example.test',
            ]);
            $this->userIds[] = (int) $user->getKey();
            $user->assignRole(TenantRole::Owner->value);
            $this->actingAs($user);

            $session = app(OpenScanFirstReceivingSession::class)->handle();
            $this->trackSession($session);
            $sessionId = (int) $session->getKey();

            Livewire::test(ViewReceivingSession::class, ['record' => $sessionId])
                ->assertActionVisible('deleteReceiving')
                ->callAction('deleteReceiving')
                ->assertHasNoActionErrors()
                ->assertRedirect();

            $this->assertNull(ReceivingSession::query()->find($sessionId));
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function mobile_delete_receiving_action_deletes_session(): void
    {
        $this->initializeDemo2Tenant();

        try {
            config(['tracepharma.regulatory_compliance.password_gate' => false]);
            Filament::setCurrentPanel(Filament::getPanel('app'));
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);

            $user = User::factory()->create([
                'email' => 'delete-receive-mobile-'.uniqid('', true).'@example.test',
            ]);
            $this->userIds[] = (int) $user->getKey();
            $user->assignRole(TenantRole::Owner->value);
            $this->actingAs($user);

            $session = app(OpenScanFirstReceivingSession::class)->handle();
            $this->trackSession($session);
            $sessionId = (int) $session->getKey();

            Livewire::test(MobileViewReceivingSession::class, ['record' => $sessionId])
                ->assertActionVisible('deleteReceiving')
                ->callAction('deleteReceiving')
                ->assertHasNoActionErrors()
                ->assertRedirect();

            $this->assertNull(ReceivingSession::query()->find($sessionId));
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function delete_receiving_requires_delete_phrase_when_scans_exist(): void
    {
        $this->initializeDemo2Tenant();

        try {
            config(['tracepharma.regulatory_compliance.password_gate' => false]);
            Filament::setCurrentPanel(Filament::getPanel('app'));
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);

            $user = User::factory()->create([
                'email' => 'delete-receive-phrase-'.uniqid('', true).'@example.test',
            ]);
            $this->userIds[] = (int) $user->getKey();
            $user->assignRole(TenantRole::Owner->value);
            $this->actingAs($user);

            $session = app(OpenScanFirstReceivingSession::class)->handle();
            $this->trackSession($session);
            $sessionId = (int) $session->getKey();

            $uri = 'urn:epc:id:sgtin:030116.3'.substr((string) random_int(10000000, 99999999), 0, 6).'.PHR1';
            $epc = Epc::query()->create(Epc::materializeAttributesFromUri($uri));
            $this->epcIds[] = (int) $epc->getKey();

            $result = app(ConfirmReceivingScan::class)->handle($session->fresh(), $uri);
            $this->assertTrue($result['ok']);
            $this->assertGreaterThan(0, UnsubmittedSessionDelete::confirmedScanCountReceiving($session->fresh()));

            Livewire::test(ViewReceivingSession::class, ['record' => $sessionId])
                ->callAction('deleteReceiving', data: ['confirm_phrase' => 'WRONG'])
                ->assertHasActionErrors(['confirm_phrase']);

            $this->assertNotNull(ReceivingSession::query()->find($sessionId));

            Livewire::test(ViewReceivingSession::class, ['record' => $sessionId])
                ->callAction('deleteReceiving', data: ['confirm_phrase' => 'DELETE'])
                ->assertHasNoActionErrors()
                ->assertRedirect();

            $this->assertNull(ReceivingSession::query()->find($sessionId));
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function delete_receiving_denies_user_without_receive_job_role(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $priorJobRolesEnabled = TenantSettings::forTenant($tenant)->jobRolesEnabled();

        try {
            $session = app(OpenScanFirstReceivingSession::class)->handle();
            $this->trackSession($session);

            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Logistics3pl);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            TenantSettings::forTenant($tenant)->setJobRolesEnabled(true);
            $tenant->save();

            $user = User::factory()->create([
                'email' => 'delete-receive-jobrole-'.uniqid('', true).'@example.test',
            ]);
            $this->userIds[] = (int) $user->getKey();
            $user->syncRoles([TenantRole::OutboundPickAndPackLead->value]);
            $user->refresh();
            $this->actingAs($user);

            try {
                app(DeleteReceivingSession::class)->handle($session->fresh());
                $this->fail('Expected DomainException for ship-only job role deleting a receive.');
            } catch (DomainException $e) {
                $this->assertSame('Receiving is not authorized for your job role.', $e->getMessage());
            }

            $this->assertNotNull(ReceivingSession::query()->find($session->getKey()));
        } finally {
            $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);
            if ($tenant !== null) {
                TenantSettings::forTenant($tenant)->setJobRolesEnabled($priorJobRolesEnabled);
                $tenant->save();
                app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
                app(PermissionRegistrar::class)->forgetCachedPermissions();
            }

            $this->cleanup();
        }
    }

    #[Test]
    public function delete_receiving_action_is_hidden_when_completed(): void
    {
        $this->initializeDemo2Tenant();

        try {
            config(['tracepharma.regulatory_compliance.password_gate' => false]);
            Filament::setCurrentPanel(Filament::getPanel('app'));
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);

            $user = User::factory()->create([
                'email' => 'delete-hidden-'.uniqid('', true).'@example.test',
            ]);
            $this->userIds[] = (int) $user->getKey();
            $user->assignRole(TenantRole::Owner->value);
            $this->actingAs($user);

            $session = app(OpenScanFirstReceivingSession::class)->handle();
            $this->trackSession($session);
            $session->forceFill(['status' => 'completed', 'completed_at' => now()])->save();

            Livewire::test(ViewReceivingSession::class, ['record' => $session->getKey()])
                ->assertActionHidden('deleteReceiving');
        } finally {
            $this->cleanup();
        }
    }

    private function trackSession(ReceivingSession $session): void
    {
        $this->sessionIds[] = (int) $session->getKey();
    }

    private function writeTempInvoice(string $contents, string $basename): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'del_recv_inv_');
        $this->assertNotFalse($tmp);
        $named = $tmp.'_'.$basename;
        $this->assertNotFalse(rename($tmp, $named));
        file_put_contents($named, $contents);

        return $named;
    }

    /**
     * @return array{0: Site, 1: Site}
     */
    private function createOwnedSites(Tenant $tenant): array
    {
        $siteA = Site::factory()->owned()->create([
            'name' => 'Delete Receive A '.Str::random(6),
            'gln' => $this->uniqueGln(),
            'is_active' => true,
        ]);
        $siteB = Site::factory()->owned()->create([
            'name' => 'Delete Receive B '.Str::random(6),
            'gln' => $this->uniqueGln(),
            'is_active' => true,
        ]);
        $this->siteIds = [(int) $siteA->getKey(), (int) $siteB->getKey()];

        return [$siteA, $siteB];
    }

    /**
     * @return array{0: Site, 1: Site}
     */
    private function createTransferSites(Tenant $tenant): array
    {
        $fromSite = Site::query()->create([
            'name' => 'Delete Transfer From '.Str::random(6),
            'gln' => $this->uniqueGln(),
            'is_active' => true,
            'is_headquarters' => true,
            'trading_partner_id' => null,
            'is_organization_facility' => true,
        ]);
        $toSite = Site::query()->create([
            'name' => 'Delete Transfer To '.Str::random(6),
            'gln' => $this->uniqueGln(),
            'is_active' => true,
            'is_headquarters' => false,
            'trading_partner_id' => null,
            'is_organization_facility' => true,
        ]);
        $this->siteIds = [(int) $fromSite->getKey(), (int) $toSite->getKey()];

        $settings = TenantSettings::forTenant($tenant);
        $this->priorDefaultShipFromSiteId = $settings->defaultShipFromSiteId();
        $this->priorDefaultReceiveSiteId = $settings->defaultReceiveSiteId();
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
        $this->transferSessionIds[] = (int) $transfer->getKey();

        foreach ([1, 2] as $index) {
            $suffix = (string) random_int(10000000, 99999999);
            $uri = 'urn:epc:id:sgtin:030116.3'.substr($suffix, 0, 6).'.TR'.$suffix.$index;

            $epc = Epc::query()->create(Epc::materializeAttributesFromUri($uri));
            $this->epcIds[] = (int) $epc->getKey();
            $this->receiveAtSite($fromSite, $epc);

            app(ConfirmTransferringScan::class)->handle($transfer->fresh(), $uri);
        }

        $shipped = app(CompleteTransferringSession::class)->handle($transfer->fresh());
        if ($shipped->transfer_epcis_document_id !== null) {
            $this->transferDocumentIds[] = (int) $shipped->transfer_epcis_document_id;
        }

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
            'original_filename' => 'delete-receive-custody.xml',
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

    private function cleanup(): void
    {
        if ($this->sessionIds !== []) {
            ReceivingScanLine::query()->whereIn('receiving_session_id', $this->sessionIds)->delete();
            ReceivingSession::query()->whereIn('id', $this->sessionIds)->delete();
            $this->sessionIds = [];
        }

        if ($this->transferSessionIds !== []) {
            TransferringScanLine::query()->whereIn('transferring_session_id', $this->transferSessionIds)->delete();
            TransferringSession::query()->whereIn('id', $this->transferSessionIds)->delete();
            $this->transferSessionIds = [];
        }

        if ($this->transferDocumentIds !== []) {
            EpcisDocument::query()->whereIn('id', $this->transferDocumentIds)->delete();
            $this->transferDocumentIds = [];
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

        if ($this->epcIds !== []) {
            DB::table('event_epcs')->whereIn('epc_id', $this->epcIds)->delete();
            DB::table('document_epcs')->whereIn('epc_id', $this->epcIds)->delete();
            ReceivingScanLine::query()->whereIn('epc_id', $this->epcIds)->delete();
            TransferringScanLine::query()->whereIn('epc_id', $this->epcIds)->delete();
            Epc::query()->whereIn('id', $this->epcIds)->delete();
            $this->epcIds = [];
        }

        if ($this->siteIds !== []) {
            Site::query()->whereIn('id', $this->siteIds)->delete();
            $this->siteIds = [];
        }

        foreach ($this->userIds as $userId) {
            User::query()->whereKey($userId)->delete();
        }
        $this->userIds = [];

        if (tenancy()->initialized) {
            $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);
            if ($tenant !== null && ($this->priorDefaultShipFromSiteId !== null || $this->priorDefaultReceiveSiteId !== null)) {
                $settings = TenantSettings::forTenant($tenant);
                $settings->setDefaultShipFromSiteId($this->priorDefaultShipFromSiteId);
                $settings->setDefaultReceiveSiteId($this->priorDefaultReceiveSiteId);
                $tenant->save();
                $this->priorDefaultShipFromSiteId = null;
                $this->priorDefaultReceiveSiteId = null;
            }

            tenancy()->end();
        }
    }
}
