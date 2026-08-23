<?php

namespace Tests\Feature\Shipping;

use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Pages\PharmacyOutboundDesk;
use App\Filament\App\Pages\ScanOutWorkstation;
use App\Filament\App\Resources\OutboundShippingSessions\OutboundShippingSessionResource;
use App\Models\Shipping\OutboundShippingSession;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\TenantFeatures;
use App\Support\TenantSettings;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PharmacyOutboundDeskTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    private ?TenantProfile $priorProfile = null;

    private ?int $priorDefaultShipFromSiteId = null;

    /** @var list<int> */
    private array $sessionIds = [];

    /** @var list<int> */
    private array $siteIds = [];

    /** @var list<int> */
    private array $userIds = [];

    #[Test]
    public function desk_is_visible_while_ship_order_and_scan_out_stay_locked(): void
    {
        $this->initializePharmacyTenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            $this->actingAs($this->createOwner());

            $this->assertTrue(TenantFeatures::forTenant(tenant())->supportsPharmacyOutboundDesk());
            $this->assertFalse(TenantFeatures::forTenant(tenant())->supportsOutboundIntegrations());
            $this->assertTrue(PharmacyOutboundDesk::canAccess());
            $this->assertSame('pharmacy-outbound', PharmacyOutboundDesk::getSlug());
            $this->assertFalse(ScanOutWorkstation::canAccess());
            $this->assertFalse(OutboundShippingSessionResource::canAccess());
            $this->assertSame('Scan Out', ScanOutWorkstation::getNavigationLabel());
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function owner_can_open_a_pharmacy_outbound_session(): void
    {
        $tenant = $this->initializePharmacyTenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            $this->actingAs($this->createOwner());
            $this->createShipSite($tenant);

            $beforeIds = OutboundShippingSession::query()->pluck('id')->all();

            $component = Livewire::test(PharmacyOutboundDesk::class)
                ->assertSuccessful()
                ->callAction('startShipOrder')
                ->assertHasNoActionErrors();

            $session = OutboundShippingSession::query()
                ->whereNotIn('id', $beforeIds)
                ->latest('id')
                ->first();

            $this->assertNotNull($session);
            $this->sessionIds[] = (int) $session->getKey();
            $this->assertSame((int) $session->getKey(), (int) $component->get('sessionId'));

            $session->forceFill([
                'status' => 'completed',
                'completed_at' => now(),
                'shipping_events_generated_at' => now(),
            ])->save();

            Livewire::test(PharmacyOutboundDesk::class, ['sessionId' => $session->getKey()])
                ->assertSuccessful()
                ->assertSee('Outbound sent', false)
                ->assertDontSee('Scan barcode', false);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function missing_session_is_not_shown_as_outbound_sent(): void
    {
        $this->initializePharmacyTenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            $this->actingAs($this->createOwner());

            Livewire::test(PharmacyOutboundDesk::class)
                ->assertSuccessful()
                ->set('sessionId', 9_999_999)
                ->assertDontSee('Outbound sent', false)
                ->assertSee('Outbound not found', false)
                ->assertDontSee('Scan barcode', false);
        } finally {
            $this->cleanup();
        }
    }

    private function initializePharmacyTenant(): Tenant
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

        $this->priorProfile = $tenant->profile instanceof TenantProfile
            ? $tenant->profile
            : TenantProfile::tryFrom((string) $tenant->profile);

        $tenant->forceFill(['profile' => TenantProfile::Pharmacy])->save();

        if (! self::$demo2TenantReady) {
            $this->artisan('tenants:migrate', [
                '--tenants' => [self::DEMO2_TENANT_ID],
                '--force' => true,
            ])->assertSuccessful();
            self::$demo2TenantReady = true;
        }

        tenancy()->initialize($tenant->fresh());

        return tenant() instanceof Tenant ? tenant() : $tenant;
    }

    private function createOwner(): User
    {
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
        $user = User::factory()->create();
        $user->assignRole(TenantRole::Owner->value);
        $this->userIds[] = (int) $user->getKey();

        return $user;
    }

    private function createShipSite(Tenant $tenant): Site
    {
        $liveTenant = tenant() instanceof Tenant ? tenant() : $tenant;
        $settings = TenantSettings::forTenant($liveTenant);
        if ($this->priorDefaultShipFromSiteId === null) {
            $this->priorDefaultShipFromSiteId = $settings->defaultShipFromSiteId();
        }

        $body12 = '036615'.str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $gln = $body12.$this->gs1CheckDigit($body12);

        $site = Site::query()->create([
            'name' => 'Pharmacy desk site '.Str::random(6),
            'gln' => $gln,
            'is_active' => true,
            'is_headquarters' => true,
            'is_organization_facility' => true,
            'trading_partner_id' => null,
        ]);
        $this->siteIds[] = (int) $site->getKey();

        $settings->saveOrganization([
            'default_ship_from_site_id' => (int) $site->getKey(),
        ]);

        return $site;
    }

    private function gs1CheckDigit(string $body12): string
    {
        $sum = 0;
        foreach (str_split($body12) as $i => $digit) {
            $sum += ((int) $digit) * (($i % 2 === 0) ? 1 : 3);
        }

        return (string) ((10 - ($sum % 10)) % 10);
    }

    private function cleanup(): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        $tenant = tenant();

        foreach ($this->sessionIds as $id) {
            OutboundShippingSession::query()->whereKey($id)->delete();
        }
        $this->sessionIds = [];

        if ($this->priorDefaultShipFromSiteId !== null) {
            TenantSettings::forTenant($tenant)->saveOrganization([
                'default_ship_from_site_id' => $this->priorDefaultShipFromSiteId,
            ]);
            $this->priorDefaultShipFromSiteId = null;
        }

        foreach ($this->siteIds as $id) {
            Site::query()->whereKey($id)->delete();
        }
        $this->siteIds = [];

        foreach ($this->userIds as $id) {
            User::query()->whereKey($id)->delete();
        }
        $this->userIds = [];

        if ($this->priorProfile !== null && $tenant !== null) {
            $tenant->forceFill(['profile' => $this->priorProfile])->save();
            $this->priorProfile = null;
        }

        tenancy()->end();
    }
}
