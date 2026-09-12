<?php

namespace Tests\Feature\Transferring;

use App\Actions\Transferring\CancelTransferringSession;
use App\Actions\Transferring\ConfirmTransferringScan;
use App\Actions\Transferring\DeleteTransferringSession;
use App\Actions\Transferring\OpenTransferringSession;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Resources\TransferringSessions\Pages\MobileViewTransferringSession;
use App\Filament\App\Resources\TransferringSessions\Pages\ViewTransferringSession;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\Transferring\TransferringScanLine;
use App\Models\Transferring\TransferringSession;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\TenantSettings;
use DomainException;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class DeleteTransferringSessionTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private const EPC_URI = 'urn:epc:id:sgtin:030116.0200116.90000082006666';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $siteIds = [];

    /** @var list<int> */
    private array $sessionIds = [];

    /** @var list<int> */
    private array $epcIds = [];

    /** @var list<int> */
    private array $userIds = [];

    /** @var list<int> */
    private array $custodyDocumentIds = [];

    /** @var list<int> */
    private array $custodyEventIds = [];

    #[Test]
    public function it_hard_deletes_empty_open_transfer(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            [$fromSite, $toSite] = $this->createTransferSites($tenant);
            $this->actingAs($this->createShipUser([(int) $fromSite->getKey()]));

            $session = app(OpenTransferringSession::class)->handle(
                fromSiteId: (int) $fromSite->getKey(),
                toSiteId: (int) $toSite->getKey(),
            );
            $this->sessionIds[] = (int) $session->getKey();
            $this->assertTrue($session->canHardDelete());

            app(DeleteTransferringSession::class)->handle($session);
            $this->assertNull(TransferringSession::query()->find($session->getKey()));
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function it_hard_deletes_transfer_with_confirmed_scans(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            [$fromSite, $toSite] = $this->createTransferSites($tenant);
            $this->actingAs($this->createShipUser([(int) $fromSite->getKey()]));

            $session = app(OpenTransferringSession::class)->handle(
                fromSiteId: (int) $fromSite->getKey(),
                toSiteId: (int) $toSite->getKey(),
            );
            $this->sessionIds[] = (int) $session->getKey();

            $epc = Epc::query()->create(Epc::materializeAttributesFromUri(self::EPC_URI));
            $this->epcIds[] = (int) $epc->getKey();
            $this->receiveAtSite($fromSite, $epc);

            app(ConfirmTransferringScan::class)->handle($session, self::EPC_URI);
            $this->assertSame(1, (int) $session->fresh()->confirmed_count);
            $this->assertTrue(TransferringScanLine::query()->where('transferring_session_id', $session->getKey())->exists());

            app(DeleteTransferringSession::class)->handle($session->fresh());

            $this->assertNull(TransferringSession::query()->find($session->getKey()));
            $this->assertFalse(TransferringScanLine::query()->where('transferring_session_id', $session->getKey())->exists());
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function it_blocks_in_transit_completed_cancelled_and_authored_epcis(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            [$fromSite, $toSite] = $this->createTransferSites($tenant);
            $this->actingAs($this->createShipUser([(int) $fromSite->getKey()]));

            $inTransit = app(OpenTransferringSession::class)->handle(
                fromSiteId: (int) $fromSite->getKey(),
                toSiteId: (int) $toSite->getKey(),
            );
            $this->sessionIds[] = (int) $inTransit->getKey();
            $inTransit->forceFill(['status' => 'in_transit', 'shipped_at' => now()])->save();

            try {
                app(DeleteTransferringSession::class)->handle($inTransit->fresh());
                $this->fail('Expected DomainException for in_transit transfer.');
            } catch (DomainException $e) {
                $this->assertStringContainsString('in_transit', $e->getMessage());
            }

            $completed = app(OpenTransferringSession::class)->handle(
                fromSiteId: (int) $fromSite->getKey(),
                toSiteId: (int) $toSite->getKey(),
            );
            $this->sessionIds[] = (int) $completed->getKey();
            $completed->forceFill(['status' => 'completed', 'completed_at' => now()])->save();

            try {
                app(DeleteTransferringSession::class)->handle($completed->fresh());
                $this->fail('Expected DomainException for completed transfer.');
            } catch (DomainException $e) {
                $this->assertStringContainsString('completed', $e->getMessage());
            }

            $cancelled = app(OpenTransferringSession::class)->handle(
                fromSiteId: (int) $fromSite->getKey(),
                toSiteId: (int) $toSite->getKey(),
            );
            $this->sessionIds[] = (int) $cancelled->getKey();
            $cancelled->forceFill(['status' => 'cancelled', 'completed_at' => now()])->save();

            try {
                app(DeleteTransferringSession::class)->handle($cancelled->fresh());
                $this->fail('Expected DomainException for cancelled transfer.');
            } catch (DomainException $e) {
                $this->assertStringContainsString('cancelled', $e->getMessage());
            }

            $authored = app(OpenTransferringSession::class)->handle(
                fromSiteId: (int) $fromSite->getKey(),
                toSiteId: (int) $toSite->getKey(),
            );
            $this->sessionIds[] = (int) $authored->getKey();
            $authored->forceFill(['transfer_events_generated_at' => now()])->save();

            try {
                app(DeleteTransferringSession::class)->handle($authored->fresh());
                $this->fail('Expected DomainException for authored transferring EPCIS.');
            } catch (DomainException $e) {
                $this->assertStringContainsString('authored transferring EPCIS', $e->getMessage());
            }
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function cancel_still_soft_deletes_history(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            [$fromSite, $toSite] = $this->createTransferSites($tenant);
            $this->actingAs($this->createShipUser([(int) $fromSite->getKey()]));

            $session = app(OpenTransferringSession::class)->handle(
                fromSiteId: (int) $fromSite->getKey(),
                toSiteId: (int) $toSite->getKey(),
            );
            $this->sessionIds[] = (int) $session->getKey();

            $cancelled = app(CancelTransferringSession::class)->handle($session);
            $this->assertSame('cancelled', $cancelled->status);
            $this->assertNotNull(TransferringSession::query()->find($session->getKey()));
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function to_site_only_user_cannot_delete_transfer_session(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            [$fromSite, $toSite] = $this->createTransferSites($tenant);
            $fromUser = $this->createShipUser([(int) $fromSite->getKey()]);
            $this->actingAs($fromUser);

            $session = app(OpenTransferringSession::class)->handle(
                fromSiteId: (int) $fromSite->getKey(),
                toSiteId: (int) $toSite->getKey(),
            );
            $this->sessionIds[] = (int) $session->getKey();

            $toUser = $this->createSiteScopedShipUser([(int) $toSite->getKey()]);

            $this->assertFalse($toUser->can('delete', $session));

            $this->actingAs($toUser);
            try {
                app(DeleteTransferringSession::class)->handle($session->fresh());
                $this->fail('Expected AuthorizationException for to-site-only user.');
            } catch (AuthorizationException $e) {
                $this->assertNotNull(TransferringSession::query()->find($session->getKey()));
            }
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function from_site_user_can_delete_open_transfer_session(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            [$fromSite, $toSite] = $this->createTransferSites($tenant);
            $fromUser = $this->createShipUser([(int) $fromSite->getKey()]);
            $this->actingAs($fromUser);

            $session = app(OpenTransferringSession::class)->handle(
                fromSiteId: (int) $fromSite->getKey(),
                toSiteId: (int) $toSite->getKey(),
            );
            $this->sessionIds[] = (int) $session->getKey();

            $scopedFromUser = $this->createSiteScopedShipUser([(int) $fromSite->getKey()]);

            $this->assertTrue($scopedFromUser->can('delete', $session));
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function delete_transfer_action_is_visible_on_active_view_and_deletes(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            config(['tracepharma.regulatory_compliance.password_gate' => false]);
            Filament::setCurrentPanel(Filament::getPanel('app'));
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);

            [$fromSite, $toSite] = $this->createTransferSites($tenant);
            $user = $this->createShipUser([(int) $fromSite->getKey()]);
            $this->actingAs($user);

            $session = app(OpenTransferringSession::class)->handle(
                fromSiteId: (int) $fromSite->getKey(),
                toSiteId: (int) $toSite->getKey(),
            );
            $this->sessionIds[] = (int) $session->getKey();
            $sessionId = (int) $session->getKey();

            Livewire::test(ViewTransferringSession::class, ['record' => $sessionId])
                ->assertActionVisible('deleteTransfer')
                ->callAction('deleteTransfer')
                ->assertHasNoActionErrors()
                ->assertRedirect();

            $this->assertNull(TransferringSession::query()->find($sessionId));
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function mobile_delete_transfer_action_is_visible_and_deletes(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            config(['tracepharma.regulatory_compliance.password_gate' => false]);
            Filament::setCurrentPanel(Filament::getPanel('app'));
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);

            [$fromSite, $toSite] = $this->createTransferSites($tenant);
            $user = $this->createShipUser([(int) $fromSite->getKey()]);
            $this->actingAs($user);

            $session = app(OpenTransferringSession::class)->handle(
                fromSiteId: (int) $fromSite->getKey(),
                toSiteId: (int) $toSite->getKey(),
            );
            $this->sessionIds[] = (int) $session->getKey();
            $sessionId = (int) $session->getKey();

            Livewire::test(MobileViewTransferringSession::class, ['record' => $sessionId])
                ->assertActionVisible('deleteTransfer')
                ->callAction('deleteTransfer')
                ->assertHasNoActionErrors()
                ->assertRedirect();

            $this->assertNull(TransferringSession::query()->find($sessionId));
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function delete_transfer_action_is_hidden_for_to_site_only_user(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            config(['tracepharma.regulatory_compliance.password_gate' => false]);
            Filament::setCurrentPanel(Filament::getPanel('app'));
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);

            [$fromSite, $toSite] = $this->createTransferSites($tenant);
            $fromUser = $this->createShipUser([(int) $fromSite->getKey()]);
            $this->actingAs($fromUser);

            $session = app(OpenTransferringSession::class)->handle(
                fromSiteId: (int) $fromSite->getKey(),
                toSiteId: (int) $toSite->getKey(),
            );
            $this->sessionIds[] = (int) $session->getKey();
            $sessionId = (int) $session->getKey();

            $toUser = $this->createSiteScopedShipUser([(int) $toSite->getKey()]);
            $this->actingAs($toUser);

            Livewire::test(ViewTransferringSession::class, ['record' => $sessionId])
                ->assertActionHidden('deleteTransfer');

            $this->assertNotNull(TransferringSession::query()->find($sessionId));
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function delete_transfer_denies_user_without_ship_job_role(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $priorJobRolesEnabled = TenantSettings::forTenant($tenant)->jobRolesEnabled();

        try {
            [$fromSite, $toSite] = $this->createTransferSites($tenant);
            $this->actingAs($this->createShipUser([(int) $fromSite->getKey()]));

            $session = app(OpenTransferringSession::class)->handle(
                fromSiteId: (int) $fromSite->getKey(),
                toSiteId: (int) $toSite->getKey(),
            );
            $this->sessionIds[] = (int) $session->getKey();

            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            TenantSettings::forTenant($tenant)->setJobRolesEnabled(true);
            $tenant->save();

            $user = User::factory()->create([
                'email' => 'delete-transfer-jobrole-'.uniqid('', true).'@example.test',
            ]);
            $this->userIds[] = (int) $user->getKey();
            $user->syncRoles([TenantRole::ReceivingTechnician->value]);
            $user->syncSites([(int) $fromSite->getKey()]);
            $user->refresh();
            $this->actingAs($user);

            try {
                app(DeleteTransferringSession::class)->handle($session->fresh());
                $this->fail('Expected DomainException for receive-only job role deleting a transfer.');
            } catch (DomainException $e) {
                $this->assertSame('Shipping is not authorized for your job role.', $e->getMessage());
            }

            $this->assertNotNull(TransferringSession::query()->find($session->getKey()));
        } finally {
            if (tenancy()->initialized) {
                TenantSettings::forTenant($tenant)->setJobRolesEnabled($priorJobRolesEnabled);
                $tenant->save();
                app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
                app(PermissionRegistrar::class)->forgetCachedPermissions();
            }

            $this->cleanup($tenant);
        }
    }

    /** @return array{0: Site, 1: Site} */
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
        $this->siteIds[] = (int) $fromSite->getKey();

        $toSite = Site::query()->create([
            'name' => 'Delete Transfer To '.Str::random(6),
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

    /**
     * @param  list<int>  $siteIds
     */
    private function createShipUser(array $siteIds): User
    {
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
        $user = User::factory()->create([
            'email' => 'delete-transfer-'.uniqid('', true).'@example.test',
        ]);
        $user->syncSites($siteIds);
        $user->assignRole(TenantRole::Owner->value);
        $this->userIds[] = (int) $user->getKey();

        return $user;
    }

    /**
     * A user without SitesAccessAll (no role assigned), scoped to only the given
     * sites — mirrors the cross-site pattern in DeleteReceivingSessionTest to
     * exercise from-site-only policy enforcement (unlike the Owner helper
     * above, which bypasses site scoping via SitesAccessAll).
     *
     * @param  list<int>  $siteIds
     */
    private function createSiteScopedShipUser(array $siteIds): User
    {
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
        $user = User::factory()->create([
            'email' => 'delete-transfer-scoped-'.uniqid('', true).'@example.test',
        ]);
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
            'original_filename' => 'delete-transfer-custody.xml',
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
        if (tenancy()->initialized) {
            if ($this->sessionIds !== []) {
                TransferringScanLine::query()
                    ->whereIn('transferring_session_id', $this->sessionIds)
                    ->delete();
                TransferringSession::query()->whereIn('id', $this->sessionIds)->delete();
                $this->sessionIds = [];
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

            tenancy()->end();
        }
    }
}
