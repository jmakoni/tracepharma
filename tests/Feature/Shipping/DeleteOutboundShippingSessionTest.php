<?php

namespace Tests\Feature\Shipping;

use App\Actions\Shipping\CancelOutboundShippingSession;
use App\Actions\Shipping\DeleteOutboundShippingSession;
use App\Actions\Shipping\OpenOutboundShippingSession;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Resources\OutboundShippingSessions\Pages\MobileViewOutboundShippingSession;
use App\Filament\App\Resources\OutboundShippingSessions\Pages\ViewOutboundShippingSession;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Shipping\OutboundShippingScanLine;
use App\Models\Shipping\OutboundShippingSession;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\Gs1\Sgln;
use App\Support\TenantSettings;
use DomainException;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class DeleteOutboundShippingSessionTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private const SSCC_URI = 'urn:epc:id:sscc:030116.01001227052';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $sessionIds = [];

    /** @var list<int> */
    private array $siteIds = [];

    /** @var list<int> */
    private array $userIds = [];

    /** @var list<int> */
    private array $documentIds = [];

    #[Test]
    public function it_hard_deletes_empty_open_and_in_progress_ship_orders(): void
    {
        $tenant = $this->initializeWholesalerTenant();

        try {
            $site = $this->createShipSite($tenant);
            $this->actingAs($this->createShippingUser());

            $open = app(OpenOutboundShippingSession::class)->handle((int) $site->getKey());
            $this->sessionIds[] = (int) $open->getKey();
            $this->assertTrue($open->canHardDelete());

            app(DeleteOutboundShippingSession::class)->handle($open);
            $this->assertNull(OutboundShippingSession::query()->find($open->getKey()));

            $inProgress = app(OpenOutboundShippingSession::class)->handle((int) $site->getKey());
            $this->sessionIds[] = (int) $inProgress->getKey();
            $inProgress->forceFill(['status' => 'in_progress'])->save();

            app(DeleteOutboundShippingSession::class)->handle($inProgress->fresh());
            $this->assertNull(OutboundShippingSession::query()->find($inProgress->getKey()));
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function it_hard_deletes_ship_orders_with_confirmed_scans(): void
    {
        $tenant = $this->initializeWholesalerTenant();

        try {
            $site = $this->createShipSite($tenant);
            $this->actingAs($this->createShippingUser());

            $session = app(OpenOutboundShippingSession::class)->handle((int) $site->getKey());
            $this->sessionIds[] = (int) $session->getKey();

            $epc = Epc::query()->firstOrCreate(
                ['epc_uri' => self::SSCC_URI],
                Epc::materializeAttributesFromUri(self::SSCC_URI),
            );

            OutboundShippingScanLine::query()->create([
                'outbound_shipping_session_id' => $session->getKey(),
                'epc_id' => $epc->getKey(),
                'line_role' => 'parent',
                'status' => 'confirmed',
                'confirmed_at' => now(),
            ]);
            $session->forceFill(['status' => 'in_progress', 'confirmed_count' => 1])->save();

            $this->assertTrue(OutboundShippingScanLine::query()->where('outbound_shipping_session_id', $session->getKey())->exists());

            app(DeleteOutboundShippingSession::class)->handle($session->fresh());

            $this->assertNull(OutboundShippingSession::query()->find($session->getKey()));
            $this->assertFalse(OutboundShippingScanLine::query()->where('outbound_shipping_session_id', $session->getKey())->exists());
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function it_blocks_completed_cancelled_and_authored_epcis(): void
    {
        $tenant = $this->initializeWholesalerTenant();

        try {
            $site = $this->createShipSite($tenant);
            $this->actingAs($this->createShippingUser());

            $completed = app(OpenOutboundShippingSession::class)->handle((int) $site->getKey());
            $this->sessionIds[] = (int) $completed->getKey();
            $completed->forceFill(['status' => 'completed', 'completed_at' => now()])->save();

            try {
                app(DeleteOutboundShippingSession::class)->handle($completed->fresh());
                $this->fail('Expected DomainException for completed ship order.');
            } catch (DomainException $e) {
                $this->assertStringContainsString('completed', $e->getMessage());
            }

            $cancelled = app(OpenOutboundShippingSession::class)->handle((int) $site->getKey());
            $this->sessionIds[] = (int) $cancelled->getKey();
            $cancelled->forceFill(['status' => 'cancelled', 'cancelled_at' => now()])->save();

            try {
                app(DeleteOutboundShippingSession::class)->handle($cancelled->fresh());
                $this->fail('Expected DomainException for cancelled ship order.');
            } catch (DomainException $e) {
                $this->assertStringContainsString('cancelled', $e->getMessage());
            }

            $authored = app(OpenOutboundShippingSession::class)->handle((int) $site->getKey());
            $this->sessionIds[] = (int) $authored->getKey();
            $document = EpcisDocument::query()->create([
                'document_uuid' => (string) Str::uuid(),
                'schema_version' => '1.2',
                'creation_date' => now(),
                'received_at' => now(),
                'direction' => 'outbound',
                'status' => 'parsed',
                'original_filename' => 'delete-ship-authored.xml',
            ]);
            $authored->forceFill(['epcis_document_id' => $document->getKey()])->save();
            $this->documentIds[] = (int) $document->getKey();
            $this->assertFalse($authored->fresh()->canHardDelete());

            try {
                app(DeleteOutboundShippingSession::class)->handle($authored->fresh());
                $this->fail('Expected DomainException for authored EPCIS document.');
            } catch (DomainException $e) {
                $this->assertStringContainsString('EPCIS document', $e->getMessage());
            }
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function user_without_site_access_cannot_delete_ship_order(): void
    {
        $tenant = $this->initializeWholesalerTenant();

        try {
            $siteA = $this->createShipSite($tenant);
            $siteB = Site::query()->create([
                'name' => 'Other Ship Site '.Str::random(6),
                'gln' => $this->uniqueOrgGln('036615'),
                'is_active' => true,
                'is_organization_facility' => true,
                'trading_partner_id' => null,
            ]);
            $this->siteIds[] = (int) $siteB->getKey();

            $owner = $this->createShippingUser();
            $this->actingAs($owner);

            $session = app(OpenOutboundShippingSession::class)->handle((int) $siteB->getKey());
            $this->sessionIds[] = (int) $session->getKey();

            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::DrugWholesaler);
            $user = User::factory()->create([
                'email' => 'delete-ship-denied-'.uniqid('', true).'@example.test',
            ]);
            $user->syncSites([(int) $siteA->getKey()]);
            $this->userIds[] = (int) $user->getKey();
            $this->actingAs($user);

            try {
                app(DeleteOutboundShippingSession::class)->handle($session->fresh());
                $this->fail('Expected AuthorizationException for cross-site ship delete.');
            } catch (AuthorizationException) {
                // expected
            }
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function cancel_still_soft_deletes_history(): void
    {
        $tenant = $this->initializeWholesalerTenant();

        try {
            $site = $this->createShipSite($tenant);
            $this->actingAs($this->createShippingUser());

            $session = app(OpenOutboundShippingSession::class)->handle((int) $site->getKey());
            $this->sessionIds[] = (int) $session->getKey();

            $cancelled = app(CancelOutboundShippingSession::class)->handle($session);
            $this->assertSame('cancelled', $cancelled->status);
            $this->assertNotNull(OutboundShippingSession::query()->find($session->getKey()));
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function delete_ship_action_is_visible_on_active_view_and_deletes(): void
    {
        $tenant = $this->initializeWholesalerTenant();

        try {
            config(['tracepharma.regulatory_compliance.password_gate' => false]);
            Filament::setCurrentPanel(Filament::getPanel('app'));
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::DrugWholesaler);

            $site = $this->createShipSite($tenant);
            $user = $this->createShippingUser();
            $user->syncSites([(int) $site->getKey()]);
            $this->actingAs($user);

            $session = app(OpenOutboundShippingSession::class)->handle((int) $site->getKey());
            $this->sessionIds[] = (int) $session->getKey();
            $sessionId = (int) $session->getKey();

            Livewire::test(ViewOutboundShippingSession::class, ['record' => $sessionId])
                ->assertActionVisible('deleteShipOrder')
                ->callAction('deleteShipOrder')
                ->assertHasNoActionErrors()
                ->assertRedirect();

            $this->assertNull(OutboundShippingSession::query()->find($sessionId));
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function mobile_delete_ship_action_is_visible_and_deletes(): void
    {
        $tenant = $this->initializeWholesalerTenant();

        try {
            config(['tracepharma.regulatory_compliance.password_gate' => false]);
            Filament::setCurrentPanel(Filament::getPanel('app'));
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::DrugWholesaler);

            $site = $this->createShipSite($tenant);
            $user = $this->createShippingUser();
            $user->syncSites([(int) $site->getKey()]);
            $this->actingAs($user);

            $session = app(OpenOutboundShippingSession::class)->handle((int) $site->getKey());
            $this->sessionIds[] = (int) $session->getKey();
            $sessionId = (int) $session->getKey();

            Livewire::test(MobileViewOutboundShippingSession::class, ['record' => $sessionId])
                ->assertActionVisible('deleteShipOrder')
                ->callAction('deleteShipOrder')
                ->assertHasNoActionErrors()
                ->assertRedirect();

            $this->assertNull(OutboundShippingSession::query()->find($sessionId));
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function delete_ship_order_denies_user_without_ship_job_role(): void
    {
        $tenant = $this->initializeWholesalerTenant();
        // JobRoleAccess reads the live tenancy()->tenant() instance, which
        // initializeWholesalerTenant() initializes from a separate `fresh()`
        // copy — mutate settings via tenant() so the job-role gate sees them.
        $priorJobRolesEnabled = TenantSettings::forTenant(tenant())->jobRolesEnabled();

        try {
            $site = $this->createShipSite($tenant);
            $this->actingAs($this->createShippingUser());

            $session = app(OpenOutboundShippingSession::class)->handle((int) $site->getKey());
            $this->sessionIds[] = (int) $session->getKey();

            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::DrugWholesaler);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            TenantSettings::forTenant(tenant())->setJobRolesEnabled(true);
            tenant()->save();

            $user = User::factory()->create([
                'email' => 'delete-ship-jobrole-'.uniqid('', true).'@example.test',
            ]);
            $this->userIds[] = (int) $user->getKey();
            $user->syncRoles([TenantRole::VrsAnalyst->value]);
            $user->refresh();
            $this->actingAs($user);

            try {
                app(DeleteOutboundShippingSession::class)->handle($session->fresh());
                $this->fail('Expected DomainException for verify-only job role deleting a ship order.');
            } catch (DomainException $e) {
                $this->assertSame('Shipping is not authorized for your job role.', $e->getMessage());
            }

            $this->assertNotNull(OutboundShippingSession::query()->find($session->getKey()));
        } finally {
            if (tenancy()->initialized) {
                TenantSettings::forTenant(tenant())->setJobRolesEnabled($priorJobRolesEnabled);
                tenant()->save();
                app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::DrugWholesaler);
                app(PermissionRegistrar::class)->forgetCachedPermissions();
            }

            $this->cleanup($tenant);
        }
    }

    private function createShippingUser(): User
    {
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::DrugWholesaler);

        $user = User::factory()->create([
            'email' => 'delete-ship-'.uniqid('', true).'@example.test',
        ]);
        $user->assignRole(TenantRole::Owner->value);
        $this->userIds[] = (int) $user->getKey();

        return $user;
    }

    private function createShipSite(Tenant $tenant, string $companyPrefix = '030116'): Site
    {
        $gln = $this->uniqueOrgGln($companyPrefix);

        $site = Site::query()->create([
            'name' => 'Delete Ship Site '.Str::random(6),
            'gln' => $gln,
            'sgln' => Sgln::toUrn($gln, strlen($companyPrefix)),
            'is_active' => true,
            'is_organization_facility' => true,
            'trading_partner_id' => null,
        ]);
        $this->siteIds[] = (int) $site->getKey();

        $settings = TenantSettings::forTenant($tenant);
        $settings->setDefaultShipFromSiteId((int) $site->getKey());
        $tenant->save();

        return $site;
    }

    private function uniqueOrgGln(string $companyPrefix): string
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $body12 = $companyPrefix.str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $gln = $body12.$this->gs1CheckDigit($body12);

            if (! Site::query()->where('gln', $gln)->exists()) {
                return $gln;
            }
        }

        throw new \RuntimeException('Unable to allocate a unique org GLN for the test.');
    }

    private function gs1CheckDigit(string $body12): string
    {
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $digit = (int) $body12[11 - $i];
            $sum += $digit * ($i % 2 === 0 ? 3 : 1);
        }

        return (string) ((10 - ($sum % 10)) % 10);
    }

    private function initializeWholesalerTenant(): Tenant
    {
        $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);

        if ($tenant === null) {
            $tenant = Tenant::withoutEvents(fn () => Tenant::query()->create([
                'id' => self::DEMO2_TENANT_ID,
                'name' => 'Demo Wholesaler',
                'profile' => TenantProfile::DrugWholesaler,
                'status' => 'active',
                'tenancy_db_name' => self::DEMO2_DATABASE,
            ]));

            $tenant->domains()->create(['domain' => self::DEMO2_DOMAIN]);
        } else {
            $tenant->domains()->firstOrCreate(['domain' => self::DEMO2_DOMAIN]);
            $tenant->forceFill(['profile' => TenantProfile::DrugWholesaler])->save();
        }

        if (! self::$demo2TenantReady) {
            $this->artisan('tenants:migrate', [
                '--tenants' => [self::DEMO2_TENANT_ID],
                '--force' => true,
            ])->assertSuccessful();

            self::$demo2TenantReady = true;
        }

        tenancy()->initialize($tenant->fresh());

        return $tenant;
    }

    private function cleanup(Tenant $tenant): void
    {
        if (tenancy()->initialized) {
            if ($this->sessionIds !== []) {
                OutboundShippingScanLine::query()->whereIn('outbound_shipping_session_id', $this->sessionIds)->delete();
                OutboundShippingSession::query()->whereIn('id', $this->sessionIds)->delete();
                $this->sessionIds = [];
            }

            if ($this->documentIds !== []) {
                EpcisDocument::query()->whereIn('id', $this->documentIds)->delete();
                $this->documentIds = [];
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
