<?php

namespace Tests\Feature\Shipping;

use App\Actions\Receiving\CompleteReceivingSession;
use App\Actions\Receiving\ConfirmReceivingScan;
use App\Actions\Receiving\OpenScanFirstReceivingSession;
use App\Actions\Shipping\OpenOutboundShippingSession;
use App\Enums\PartnerType;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Pages\ScanOutWorkstation;
use App\Models\Epcis\Epc;
use App\Models\Receiving\ReceivingScanLine;
use App\Models\Receiving\ReceivingSession;
use App\Models\Shipping\OutboundShippingScanLine;
use App\Models\Shipping\OutboundShippingSession;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\TenantFeatures;
use App\Support\TenantSettings;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ScanOutWorkstationTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    private ?TenantProfile $priorProfile = null;

    private ?int $priorDefaultShipFromSiteId = null;

    private ?int $priorDefaultReceiveSiteId = null;

    private ?bool $priorRequireTi = null;

    /** @var list<int> */
    private array $partnerIds = [];

    /** @var list<int> */
    private array $sessionIds = [];

    /** @var list<int> */
    private array $receivingSessionIds = [];

    /** @var list<int> */
    private array $siteIds = [];

    /** @var list<int> */
    private array $epcIds = [];

    /** @var list<int> */
    private array $userIds = [];

    #[Test]
    public function page_is_visible_when_outbound_supported(): void
    {
        $this->initializeWholesalerTenant();

        try {
            $this->assertTrue(TenantFeatures::forTenant(tenant())->supportsOutboundIntegrations());
            $this->assertTrue(ScanOutWorkstation::canAccess());
            $this->assertTrue(ScanOutWorkstation::shouldRegisterNavigation());
            $this->assertSame('Scan Out', ScanOutWorkstation::getNavigationLabel());
            $this->assertSame('Ship', ScanOutWorkstation::getNavigationGroup());
            $this->assertSame('scan-out', ScanOutWorkstation::getSlug());
            $this->assertSame(11, ScanOutWorkstation::getNavigationSort());
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function picker_renders_and_new_ship_order_stays_on_scan_out(): void
    {
        $tenant = $this->initializeWholesalerTenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            $this->actingAs($this->createShippingUser());
            $site = $this->createShipSite($tenant);

            $beforeIds = OutboundShippingSession::query()->pluck('id')->all();

            $component = Livewire::test(ScanOutWorkstation::class)
                ->assertSuccessful()
                ->assertSee('Open a ship order')
                ->call('openNewSession', (int) $site->getKey())
                ->assertHasNoErrors()
                ->assertSet('sessionId', fn (?int $id): bool => $id !== null && $id > 0);

            $session = OutboundShippingSession::query()->find($component->get('sessionId'));

            $this->assertNotNull($session);
            $this->assertNotContains((int) $session->getKey(), $beforeIds);
            $this->sessionIds[] = (int) $session->getKey();
            $this->assertStringContainsString(
                'session='.$session->getKey(),
                ScanOutWorkstation::urlForSession((int) $session->getKey()),
            );
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function open_session_renders_wizard_with_scan_customer_and_send_steps(): void
    {
        $tenant = $this->initializeWholesalerTenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            $this->actingAs($this->createShippingUser());
            $site = $this->createShipSite($tenant);

            $session = app(OpenOutboundShippingSession::class)->handle((int) $site->getKey());
            $this->sessionIds[] = (int) $session->getKey();

            Livewire::test(ScanOutWorkstation::class, ['sessionId' => $session->getKey()])
                ->assertSuccessful()
                ->assertSee('Scan barcode')
                ->assertSee('Customer')
                ->assertSee('Send')
                ->set('wizardStep', 2)
                ->assertSee('Outbound connection')
                ->set('wizardStep', 3)
                ->assertSee('ASN number')
                ->assertSee('I affirm TI/TS')
                ->assertActionDisabled('sendShipment');

            $blade = File::get(resource_path('views/filament/app/pages/scan-out-workstation.blade.php'));
            $scanPartial = File::get(resource_path('views/filament/app/partials/outbound-ship-wizard-step-scan.blade.php'));
            $this->assertStringContainsString('outbound-ship-wizard-step-scan', $blade);
            $this->assertStringContainsString('scan-field', $scanPartial);
            $this->assertStringContainsString('useScanFieldComponent', $scanPartial);
            $this->assertStringContainsString('submit-action="confirmScan"', $scanPartial);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function go_to_step_and_save_party_updates_session_from_scan_out(): void
    {
        $tenant = $this->initializeWholesalerTenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            $this->actingAs($this->createShippingUser());
            $site = $this->createShipSite($tenant);

            $customerGlnBody = '037014'.str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $customerGln = $customerGlnBody.$this->gs1CheckDigit($customerGlnBody);
            $partner = TradingPartner::query()->create([
                'name' => 'Scan Out Customer '.Str::random(4),
                'slug' => 'scan-out-customer-'.Str::lower(Str::random(8)),
                'partner_type' => PartnerType::Pharmacy,
                'gln' => $customerGln,
                'is_active' => true,
            ]);
            $this->partnerIds[] = (int) $partner->getKey();

            $customerSite = Site::query()->create([
                'name' => 'Customer Site '.Str::random(4),
                'gln' => $customerGln,
                'is_active' => true,
                'is_organization_facility' => false,
                'trading_partner_id' => (int) $partner->getKey(),
            ]);
            $this->siteIds[] = (int) $customerSite->getKey();

            $session = app(OpenOutboundShippingSession::class)->handle((int) $site->getKey());
            $this->sessionIds[] = (int) $session->getKey();

            Livewire::test(ScanOutWorkstation::class, ['sessionId' => $session->getKey()])
                ->call('goToStep', 2)
                ->set('ship_to_site_id', (int) $customerSite->getKey())
                ->set('trading_partner_id', (int) $customerSite->trading_partner_id)
                ->set('ship_to_gln', (string) $customerSite->gln)
                ->callAction('saveParty')
                ->assertHasNoActionErrors();

            $session->refresh();
            $this->assertSame((int) $customerSite->getKey(), (int) $session->ship_to_site_id);
            $this->assertSame((string) $customerSite->gln, (string) $session->ship_to_gln);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function new_ship_order_uses_explicit_ship_from_site(): void
    {
        $tenant = $this->initializeWholesalerTenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            $this->actingAs($this->createShippingUser());
            $site = $this->createShipSite($tenant);

            $beforeIds = OutboundShippingSession::query()->pluck('id')->all();

            Livewire::test(ScanOutWorkstation::class)
                ->call('openNewSession', (int) $site->getKey())
                ->assertHasNoErrors();

            $session = OutboundShippingSession::query()
                ->whereNotIn('id', $beforeIds)
                ->latest('id')
                ->first();

            $this->assertNotNull($session);
            $this->sessionIds[] = (int) $session->getKey();
            $this->assertSame((int) $site->getKey(), (int) $session->site_id);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function confirm_scan_creates_confirmed_line(): void
    {
        $tenant = $this->initializeWholesalerTenant();

        try {
            $this->priorRequireTi = TenantSettings::forTenant($tenant)->requireTiForScanFirst();
            TenantSettings::forTenant($tenant)->setRequireTiForScanFirst(false);
            $tenant->save();

            Filament::setCurrentPanel(Filament::getPanel('app'));
            $this->actingAs($this->createShippingUser());
            $site = $this->createShipSite($tenant);

            $suffix = (string) random_int(10000000, 99999999);
            $uri = 'urn:epc:id:sgtin:030116.3'.substr($suffix, 0, 6).'.SO'.$suffix;
            $epc = Epc::query()->create(Epc::materializeAttributesFromUri($uri));
            $this->epcIds[] = (int) $epc->getKey();

            $receive = app(OpenScanFirstReceivingSession::class)->handle((int) $site->getKey());
            $this->receivingSessionIds[] = (int) $receive->getKey();
            $received = app(ConfirmReceivingScan::class)->handle($receive, $uri);
            $this->assertTrue($received['ok'], $received['message']);
            app(CompleteReceivingSession::class)->handle($receive->fresh(), auth()->id());

            $session = app(OpenOutboundShippingSession::class)->handle((int) $site->getKey());
            $this->sessionIds[] = (int) $session->getKey();

            Livewire::test(ScanOutWorkstation::class, ['sessionId' => $session->getKey()])
                ->set('scan', $uri)
                ->callAction('confirmScan')
                ->assertHasNoActionErrors();

            $line = OutboundShippingScanLine::query()
                ->where('outbound_shipping_session_id', $session->getKey())
                ->where('epc_id', $epc->getKey())
                ->first();

            $this->assertNotNull($line);
            $this->assertSame('confirmed', $line->status);
        } finally {
            $this->cleanup();
        }
    }

    private function createShippingUser(): User
    {
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::DrugWholesaler);

        $user = User::factory()->create([
            'email' => 'scan-out-'.uniqid('', true).'@example.test',
        ]);
        $user->assignRole(TenantRole::Owner->value);
        $this->userIds[] = (int) $user->getKey();

        return $user;
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
        }

        $this->priorProfile = $tenant->profile instanceof TenantProfile
            ? $tenant->profile
            : TenantProfile::tryFrom((string) $tenant->profile);

        $tenant->forceFill(['profile' => TenantProfile::DrugWholesaler])->save();

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

    private function createShipSite(Tenant $tenant): Site
    {
        $liveTenant = tenant() instanceof Tenant ? tenant() : $tenant;
        $settings = TenantSettings::forTenant($liveTenant);
        if ($this->priorDefaultShipFromSiteId === null) {
            $this->priorDefaultShipFromSiteId = $settings->defaultShipFromSiteId();
        }
        if ($this->priorDefaultReceiveSiteId === null) {
            $this->priorDefaultReceiveSiteId = $settings->defaultReceiveSiteId();
        }

        $body12 = '036615'.str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $gln = $body12.$this->gs1CheckDigit($body12);

        $site = Site::query()->create([
            'name' => 'Scan Out Site '.Str::random(6),
            'gln' => $gln,
            'is_active' => true,
            'is_headquarters' => true,
            'is_organization_facility' => true,
            'trading_partner_id' => null,
        ]);
        $this->siteIds[] = (int) $site->getKey();

        $settings->saveOrganization([
            'gln' => $gln,
            'company_prefix' => '036615',
            'default_ship_from_site_id' => (int) $site->getKey(),
            'default_receive_site_id' => (int) $site->getKey(),
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

        if ($this->priorRequireTi !== null && $tenant !== null) {
            TenantSettings::forTenant($tenant)->setRequireTiForScanFirst($this->priorRequireTi);
            $tenant->save();
            $this->priorRequireTi = null;
        }

        foreach ($this->sessionIds as $sessionId) {
            OutboundShippingScanLine::query()->where('outbound_shipping_session_id', $sessionId)->delete();
            OutboundShippingSession::query()->whereKey($sessionId)->delete();
        }
        $this->sessionIds = [];

        foreach ($this->receivingSessionIds as $sessionId) {
            ReceivingScanLine::query()->where('receiving_session_id', $sessionId)->delete();
            ReceivingSession::query()->whereKey($sessionId)->delete();
        }
        $this->receivingSessionIds = [];

        foreach ($this->epcIds as $epcId) {
            OutboundShippingScanLine::query()->where('epc_id', $epcId)->delete();
            ReceivingScanLine::query()->where('epc_id', $epcId)->delete();
            if (! DB::table('document_epcs')->where('epc_id', $epcId)->exists()
                && ! DB::table('event_epcs')->where('epc_id', $epcId)->exists()) {
                Epc::query()->whereKey($epcId)->delete();
            }
        }
        $this->epcIds = [];

        foreach ($this->siteIds as $siteId) {
            Site::query()->whereKey($siteId)->delete();
        }
        $this->siteIds = [];

        foreach ($this->partnerIds as $partnerId) {
            Site::query()->where('trading_partner_id', $partnerId)->delete();
            TradingPartner::query()->whereKey($partnerId)->delete();
        }
        $this->partnerIds = [];

        TradingPartner::query()
            ->where('name', 'like', 'Scan Out Customer %')
            ->each(function (TradingPartner $partner): void {
                Site::query()->where('trading_partner_id', $partner->getKey())->delete();
                $partner->delete();
            });

        if ($this->priorDefaultShipFromSiteId !== null || $this->priorDefaultReceiveSiteId !== null) {
            TenantSettings::forTenant($tenant)->saveOrganization([
                'default_ship_from_site_id' => $this->priorDefaultShipFromSiteId,
                'default_receive_site_id' => $this->priorDefaultReceiveSiteId,
            ]);
            $this->priorDefaultShipFromSiteId = null;
            $this->priorDefaultReceiveSiteId = null;
        }

        foreach ($this->userIds as $userId) {
            User::query()->whereKey($userId)->delete();
        }
        $this->userIds = [];

        if ($this->priorProfile !== null && $tenant !== null) {
            $tenant->forceFill(['profile' => $this->priorProfile])->save();
            $this->priorProfile = null;
        }

        tenancy()->end();
    }
}
