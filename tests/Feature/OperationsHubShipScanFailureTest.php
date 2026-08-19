<?php

namespace Tests\Feature;

use App\Actions\Epcis\IngestEpcisXmlDocument;
use App\Actions\Receiving\ConfirmReceivingScan;
use App\Actions\Receiving\OpenReceivingSessionFromDocument;
use App\Actions\Epcis\ResolveEpcFromScan;
use App\Actions\Shipping\ConfirmOutboundShippingScan;
use App\Actions\Shipping\OpenOutboundShippingSession;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Pages\OperationsHub;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Quarantine\QuarantineHold;
use App\Filament\App\Resources\OutboundShippingSessions\OutboundShippingSessionResource;
use App\Models\Receiving\ReceivingSession;
use App\Models\Shipping\OutboundShippingSession;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\CurrentSite;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\Gs1\ElementString;
use App\Support\Shipping\ShippableEpcsAtSite;
use App\Support\TenantSettings;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Livewire\Livewire;
use DomainException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OperationsHubShipScanFailureTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private const SSCC_URI = 'urn:epc:id:sscc:030116.01001227052';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $siteIds = [];

    /** @var list<int> */
    private array $sessionIds = [];

    /** @var list<int> */
    private array $documentIds = [];

    /** @var list<int> */
    private array $receivingSessionIds = [];

    /** @var list<int> */
    private array $holdIds = [];

    private ?TenantProfile $priorProfile = null;

    #[Test]
    public function hub_ship_scan_failure_shows_notification_and_stays_on_hub(): void
    {
        $tenant = $this->initializeWholesalerTenant();

        try {
            config(['tracepharma.epcis.enforce_atp_outbound_gate' => false]);

            Filament::setCurrentPanel(Filament::getPanel('app'));
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::DrugWholesaler);

            $user = User::factory()->create();
            $user->assignRole(TenantRole::Owner->value);
            $this->actingAs($user);

            $site = $this->createShipSite($tenant);
            $this->makeEpcShippableAtSite($site);

            $epc = Epc::query()->where('epc_uri', self::SSCC_URI)->firstOrFail();
            $hold = QuarantineHold::query()->create([
                'epc_id' => $epc->getKey(),
                'reason' => 'Hub ship scan failure test',
                'status' => 'open',
                'severity' => 'blocking',
                'opened_at' => now(),
            ]);
            $this->holdIds[] = (int) $hold->getKey();

            $this->assertTrue(
                app(ShippableEpcsAtSite::class)->contains((int) $site->getKey(), (int) $epc->getKey()),
                'EPC must be shippable at the hub site before scanning.',
            );
            $this->assertTrue(OutboundShippingSessionResource::canAccess());

            $preflightSession = app(OpenOutboundShippingSession::class)->handle(
                (int) $site->getKey(),
                (int) $user->getKey(),
            );
            $this->sessionIds[] = (int) $preflightSession->getKey();
            $preflight = app(ConfirmOutboundShippingScan::class)->handle(
                $preflightSession,
                self::SSCC_URI,
                (int) $user->getKey(),
            );
            $this->assertFalse($preflight['ok'], $preflight['message']);
            $this->assertSame('quarantined', $preflight['effect']);

            CurrentSite::set((int) $site->getKey());

            $barcode = '(00)'.(string) $epc->sscc18;
            $normalized = ElementString::normalize($barcode);

            $this->withSession([CurrentSite::SESSION_KEY => (int) $site->getKey()]);

            $component = Livewire::actingAs($user)->test(OperationsHub::class);
            $hub = $component->instance();
            $resolvedEpc = app(ResolveEpcFromScan::class)->handle($normalized)['epc'];
            $this->assertNotNull($resolvedEpc);

            $routeShippable = new \ReflectionMethod(OperationsHub::class, 'routeShippableEpcScan');
            $routeShippable->setAccessible(true);
            $url = $routeShippable->invoke($hub, $resolvedEpc, $normalized);

            $this->assertNull($url);
            $this->assertTrue($hub->hubShipScanFailed);
            $component->assertNotified();

            $openShipSession = OutboundShippingSession::query()
                ->whereIn('status', ['open', 'in_progress'])
                ->where('site_id', (int) $site->getKey())
                ->first();
            $this->assertNotNull($openShipSession, 'Failed ship scan should leave an open ship session.');
            $this->assertSame(
                0,
                $openShipSession->scanLines()->count(),
                'Quarantined scan must not confirm a ship line.',
            );
            $this->sessionIds[] = (int) $openShipSession->getKey();
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function hub_open_ship_domain_exception_shows_notification_and_stays_on_hub(): void
    {
        $tenant = $this->initializeWholesalerTenant();

        try {
            config(['tracepharma.epcis.enforce_atp_outbound_gate' => false]);

            Filament::setCurrentPanel(Filament::getPanel('app'));
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::DrugWholesaler);

            $user = User::factory()->create();
            $user->assignRole(TenantRole::Owner->value);
            $this->actingAs($user);

            $site = $this->createShipSite($tenant);
            $this->makeEpcShippableAtSite($site);

            $epc = Epc::query()->where('epc_uri', self::SSCC_URI)->firstOrFail();
            $this->assertTrue(
                app(ShippableEpcsAtSite::class)->contains((int) $site->getKey(), (int) $epc->getKey()),
            );

            CurrentSite::set((int) $site->getKey());
            $barcode = '(00)'.(string) $epc->sscc18;
            $normalized = ElementString::normalize($barcode);
            $this->withSession([CurrentSite::SESSION_KEY => (int) $site->getKey()]);

            $openShip = app(OpenOutboundShippingSession::class);
            $mock = \Mockery::mock($openShip)->makePartial();
            $mock->shouldReceive('handle')
                ->once()
                ->andThrow(new DomainException('Outbound shipping is not available for this tenant profile.'));
            $this->instance(OpenOutboundShippingSession::class, $mock);

            $component = Livewire::actingAs($user)->test(OperationsHub::class);
            $hub = $component->instance();
            $resolvedEpc = app(ResolveEpcFromScan::class)->handle($normalized)['epc'];
            $this->assertNotNull($resolvedEpc);

            $routeReceiveScan = new \ReflectionMethod(OperationsHub::class, 'routeReceiveScan');
            $routeReceiveScan->setAccessible(true);
            $url = $routeReceiveScan->invoke(
                $hub,
                $normalized,
                app(ResolveEpcFromScan::class),
            );

            $this->assertNull($url);
            $this->assertTrue($hub->hubShipScanFailed);
            $component->assertNotified();

            $this->assertSame(
                0,
                OutboundShippingSession::query()
                    ->whereIn('status', ['open', 'in_progress'])
                    ->where('site_id', (int) $site->getKey())
                    ->count(),
                'Open-ship failure must not leave a ship session behind.',
            );
        } finally {
            $this->cleanup($tenant);
        }
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

        return $tenant;
    }

    private function createShipSite(Tenant $tenant): Site
    {
        $siteGln = '0366159000'.random_int(100, 999);

        $site = Site::query()->create([
            'name' => 'Hub Ship Site '.Str::random(6),
            'gln' => $siteGln,
            'is_active' => true,
            'is_headquarters' => true,
            'is_organization_facility' => true,
            'trading_partner_id' => null,
        ]);
        $this->siteIds[] = (int) $site->getKey();

        TenantSettings::forTenant($tenant)->saveOrganization([
            'gln' => $siteGln,
            'company_prefix' => '036615',
            'default_ship_from_site_id' => (int) $site->getKey(),
            'default_receive_site_id' => (int) $site->getKey(),
        ]);

        return $site;
    }

    private function makeEpcShippableAtSite(Site $site): void
    {
        $document = $this->ingestMinimalFixture(self::SSCC_URI);
        $this->documentIds[] = (int) $document->getKey();

        $session = app(OpenReceivingSessionFromDocument::class)->handle($document);
        $this->receivingSessionIds[] = (int) $session->getKey();
        $session->forceFill(['site_id' => (int) $site->getKey()])->save();

        app(ConfirmReceivingScan::class)->handle(
            $session->fresh(),
            self::SSCC_URI,
            userId: null,
            autoConfirmChildren: true,
        );

        $session = $session->fresh();
        $session->forceFill([
            'status' => 'completed',
            'completed_at' => now(),
        ])->save();

        if ($session->receiving_epcis_document_id !== null) {
            $this->documentIds[] = (int) $session->receiving_epcis_document_id;
        }
    }

    private function ingestMinimalFixture(string $ssccUri): EpcisDocument
    {
        $fixture = base_path('tests/Fixtures/epcis/minimal_object_shipping.xml');
        $this->assertFileExists($fixture);

        $tmp = tempnam(sys_get_temp_dir(), 'epcis_hub_');
        $this->assertNotFalse($tmp);
        $xml = file_get_contents($fixture);
        $this->assertNotFalse($xml);
        $xml = str_replace('11111111-2222-3333-4444-555555555555', (string) Str::uuid(), $xml);
        $xml = str_replace(self::SSCC_URI, $ssccUri, $xml);
        file_put_contents($tmp, $xml);

        try {
            return app(IngestEpcisXmlDocument::class)->handle($tmp, [
                'direction' => 'inbound',
                'original_filename' => basename($fixture),
            ]);
        } finally {
            @unlink($tmp);
        }
    }

    private function cleanup(Tenant $tenant): void
    {
        if (tenancy()->initialized) {
            if ($this->holdIds !== []) {
                QuarantineHold::query()->whereIn('id', $this->holdIds)->delete();
            }
            if ($this->sessionIds !== []) {
                OutboundShippingSession::query()->whereIn('id', $this->sessionIds)->delete();
            }
            if ($this->receivingSessionIds !== []) {
                \App\Models\Receiving\ReceivingSession::query()->whereIn('id', $this->receivingSessionIds)->delete();
            }
            if ($this->documentIds !== []) {
                EpcisDocument::query()->whereIn('id', $this->documentIds)->delete();
            }
            if ($this->siteIds !== []) {
                Site::query()->whereIn('id', $this->siteIds)->delete();
            }

            if ($this->priorProfile !== null) {
                $tenant->forceFill(['profile' => $this->priorProfile])->save();
            }

            session()->forget(CurrentSite::SESSION_KEY);
            tenancy()->end();
        }

        $this->holdIds = [];
        $this->sessionIds = [];
        $this->receivingSessionIds = [];
        $this->documentIds = [];
        $this->siteIds = [];
    }
}
