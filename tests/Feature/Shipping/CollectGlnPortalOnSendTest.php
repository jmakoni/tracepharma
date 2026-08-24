<?php

namespace Tests\Feature\Shipping;

use App\Actions\Epcis\IngestEpcisXmlDocument;
use App\Actions\Receiving\ConfirmReceivingScan;
use App\Actions\Receiving\OpenReceivingSessionFromDocument;
use App\Actions\Shipping\CompleteOutboundShippingSession;
use App\Actions\Shipping\ConfirmOutboundShippingScan;
use App\Actions\Shipping\OpenOutboundShippingSession;
use App\Actions\Shipping\RecordOutboundDestIdentity;
use App\Actions\Shipping\UpdateOutboundShippingParty;
use App\Actions\Shipping\UpdateOutboundShippingReferences;
use App\Actions\Shipping\ValidateOutboundShippingSend;
use App\Enums\FacilityType;
use App\Enums\PartnerType;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Pages\PharmacyOutboundDesk;
use App\Models\AtpLicense;
use App\Models\Epcis\AggregationLink;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Receiving\ReceivingSession;
use App\Models\Shipping\OutboundShippingSession;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Models\User;
use App\Services\Outbound\CustomerPortalService;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\Gs1\Sgln;
use App\Support\MasterData\AtpDisclosure;
use App\Support\TenantSettings;
use DomainException;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CollectGlnPortalOnSendTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    private ?TenantProfile $priorProfile = null;

    private ?int $priorDefaultShipFromSiteId = null;

    private ?int $priorDefaultReceiveSiteId = null;

    private ?string $priorReceivingState = null;

    private bool $receivingStateCaptured = false;

    /** @var list<int> */
    private array $sessionIds = [];

    /** @var list<int> */
    private array $receivingSessionIds = [];

    /** @var list<int> */
    private array $documentIds = [];

    /** @var list<int> */
    private array $epcIds = [];

    /** @var list<int> */
    private array $siteIds = [];

    /** @var list<int> */
    private array $partnerIds = [];

    /** @var list<int> */
    private array $atpLicenseIds = [];

    /** @var list<int> */
    private array $userIds = [];

    #[Test]
    public function cg1_dest_sgln_blocker_when_partner_has_gln_but_no_sgln(): void
    {
        $tenant = $this->initializeTenant(TenantProfile::DrugWholesaler);

        try {
            [$session, $shipTo] = $this->readySessionWithBlankDestSgln($tenant);

            $blockers = app(ValidateOutboundShippingSend::class)->handle($session);
            $destBlockers = array_values(array_filter(
                $blockers,
                static fn (string $blocker): bool => str_contains($blocker, 'not ours to guess'),
            ));

            $this->assertNotSame([], $destBlockers);
            $this->assertStringContainsString((string) $shipTo->gln, $destBlockers[0]);

            try {
                app(CompleteOutboundShippingSession::class)->handle($session->fresh());
                $this->fail('Expected send to stay blocked until the customer states their SGLN.');
            } catch (DomainException $e) {
                $this->assertStringContainsString('not ours to guess', $e->getMessage());
                $this->assertStringContainsString((string) $shipTo->gln, $e->getMessage());
            }

            $session = $session->fresh();
            $this->assertNotSame('completed', $session->status);
            $this->assertNull($session->epcis_document_id);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function cg2_dest_sgln_blocker_clears_after_site_sgln_is_recorded(): void
    {
        $tenant = $this->initializeTenant(TenantProfile::DrugWholesaler);

        try {
            [$session, $shipTo] = $this->readySessionWithBlankDestSgln($tenant);

            $shipToSgln = Sgln::toUrn((string) $shipTo->gln, 6);
            $this->assertNotNull($shipToSgln);
            $shipTo->forceFill(['sgln' => $shipToSgln])->save();

            $blockers = app(ValidateOutboundShippingSend::class)->handle($session->fresh());
            $this->assertSame([], array_values(array_filter(
                $blockers,
                static fn (string $blocker): bool => str_contains($blocker, 'not ours to guess'),
            )));
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function cg5_inactive_customer_send_succeeds_without_portal_url(): void
    {
        $tenant = $this->initializeTenant(TenantProfile::DrugWholesaler);

        try {
            [$session, $shipTo] = $this->readySessionWithBlankDestSgln($tenant);
            $shipToSgln = Sgln::toUrn((string) $shipTo->gln, 6);
            $this->assertNotNull($shipToSgln);
            $shipTo->forceFill(['sgln' => $shipToSgln])->save();

            $partner = TradingPartner::query()->findOrFail($session->trading_partner_id);
            $partner->forceFill(['is_active' => false])->save();
            $this->assertNull($partner->fresh()->customer_portal_uuid);

            $completed = app(CompleteOutboundShippingSession::class)->handle($session->fresh());
            $this->assertSame('completed', $completed->status);
            $this->assertNotNull($completed->epcis_document_id);
            $this->documentIds[] = (int) $completed->epcis_document_id;

            $this->assertNull($partner->fresh()->customer_portal_uuid);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function cg6_signed_portal_lists_the_authored_shipping_document(): void
    {
        $tenant = $this->initializeTenant(TenantProfile::DrugWholesaler);

        try {
            [$session, $shipTo] = $this->readySessionWithBlankDestSgln($tenant);
            $shipToSgln = Sgln::toUrn((string) $shipTo->gln, 6);
            $this->assertNotNull($shipToSgln);
            $shipTo->forceFill(['sgln' => $shipToSgln])->save();

            $completed = app(CompleteOutboundShippingSession::class)->handle($session->fresh());
            $this->assertSame('completed', $completed->status);
            $this->assertNotNull($completed->epcis_document_id);
            $this->documentIds[] = (int) $completed->epcis_document_id;

            $partner = TradingPartner::query()->findOrFail($completed->trading_partner_id);
            $this->assertNotNull($partner->fresh()->customer_portal_uuid);

            $document = EpcisDocument::query()->findOrFail($completed->epcis_document_id);
            URL::forceRootUrl('http://'.self::DEMO2_DOMAIN);
            $url = app(CustomerPortalService::class)->signedCustomerPortalUrl($partner->fresh());

            tenancy()->end();

            $this->get($url)
                ->assertOk()
                ->assertSee((string) $document->original_filename, false);
        } finally {
            if (! tenancy()->initialized) {
                tenancy()->initialize($tenant);
            }
            $this->cleanup();
        }
    }

    #[Test]
    public function cg4_pharmacy_desk_paste_authors_ti_and_issues_portal(): void
    {
        $tenant = $this->initializeTenant(TenantProfile::Pharmacy);

        try {
            config(['tracepharma.epcis.enforce_atp_outbound_gate' => false]);

            Filament::setCurrentPanel(Filament::getPanel('app'));
            $this->actingAs($this->createOwner(TenantProfile::Pharmacy));

            $shipFrom = $this->createShipSite($tenant);
            $ssccUri = $this->makeUniqueSsccShippableAtSite($shipFrom);

            $partner = TradingPartner::query()->create([
                'name' => 'No Gln Dispenser '.Str::random(6),
                'partner_type' => PartnerType::Pharmacy,
                'country_code' => 'US',
                'is_active' => true,
                'gln' => null,
                'sgln' => null,
            ]);
            $this->partnerIds[] = (int) $partner->getKey();
            $this->assertNull($partner->gln);
            $this->assertNull($partner->sgln);

            $destGln = $this->uniqueGln('037088');
            $destSgln = Sgln::toUrn($destGln, 6);
            $this->assertNotNull($destSgln);

            $component = Livewire::test(PharmacyOutboundDesk::class)
                ->assertSuccessful()
                ->callAction('startShipOrder')
                ->assertHasNoActionErrors();

            $sessionId = (int) $component->get('sessionId');
            $this->assertGreaterThan(0, $sessionId);
            $this->sessionIds[] = $sessionId;

            $component
                ->set('tradingPartnerId', (int) $partner->getKey())
                ->set('destGln', $destGln)
                ->set('destSgln', $destSgln)
                ->set('asn', 'ASN-CG4-'.Str::random(4))
                ->set('po', 'PO-CG4-'.Str::random(4))
                ->set('dscsaAffirm', true)
                ->callAction('saveRefs')
                ->assertHasNoActionErrors();

            $session = OutboundShippingSession::query()->findOrFail($sessionId);
            $this->assertNotNull($session->ship_to_site_id);
            $this->assertNotNull($session->ship_to_gln);

            $component
                ->set('scan', $ssccUri)
                ->callAction('confirmScan');

            $this->assertSame('ok', $component->get('lastScanTone'), (string) $component->get('lastScanMessage'));

            $blockers = app(ValidateOutboundShippingSend::class)->handle($session->fresh());
            $this->assertSame([], $blockers, implode(' | ', $blockers));

            $component
                ->callAction('sendShipment')
                ->assertHasNoActionErrors();

            $session = OutboundShippingSession::query()->findOrFail($sessionId);
            $this->assertSame('completed', $session->status);
            $this->assertNotNull($session->epcis_document_id);
            $this->documentIds[] = (int) $session->epcis_document_id;

            $partner = $partner->fresh();
            $this->assertNotNull($partner->customer_portal_uuid);
            $this->assertNull($partner->gln);
            $this->assertNull($partner->sgln);

            $destSite = Site::query()->find($session->ship_to_site_id);
            $this->assertNotNull($destSite);
            $this->siteIds[] = (int) $destSite->getKey();
            $this->assertSame((int) $partner->getKey(), (int) $destSite->trading_partner_id);
            $this->assertSame($partner->name, $destSite->name);
            $this->assertSame($destGln, Sgln::normalizeGln($destSite->gln));
            $this->assertSame($destSgln, $destSite->sgln);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function cg7_gln_only_paste_does_not_persist_invented_sgln(): void
    {
        $tenant = $this->initializeTenant(TenantProfile::DrugWholesaler);

        try {
            $this->actingAs($this->createOwner(TenantProfile::DrugWholesaler));
            $this->createShipSite($tenant, '036615');

            $partner = TradingPartner::query()->create([
                'name' => 'Prefix Overlap Dispenser '.Str::random(6),
                'partner_type' => PartnerType::Pharmacy,
                'country_code' => 'US',
                'is_active' => true,
                'gln' => null,
                'sgln' => null,
            ]);
            $this->partnerIds[] = (int) $partner->getKey();

            $session = app(OpenOutboundShippingSession::class)->handle();
            $this->sessionIds[] = (int) $session->getKey();

            $destGln = $this->uniqueGln('036615');

            try {
                app(RecordOutboundDestIdentity::class)->handle($session, [
                    'trading_partner_id' => (int) $partner->getKey(),
                    'dest_gln' => $destGln,
                    'dest_sgln' => null,
                ]);
                $this->fail('Expected GLN-only paste to be refused.');
            } catch (DomainException $e) {
                $this->assertStringContainsString('Paste both the customer GLN and the SGLN', $e->getMessage());
            }

            $invented = Site::query()
                ->where('trading_partner_id', (int) $partner->getKey())
                ->whereNotNull('sgln')
                ->where('sgln', '!=', '')
                ->count();
            $this->assertSame(0, $invented);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function cg8_desk_shows_atp_blocker_after_dest_collect(): void
    {
        $tenant = $this->initializeTenant(TenantProfile::Pharmacy);

        try {
            config(['tracepharma.epcis.enforce_atp_outbound_gate' => true]);

            Filament::setCurrentPanel(Filament::getPanel('app'));
            $this->actingAs($this->createOwner(TenantProfile::Pharmacy));
            $this->createShipSite($tenant);

            $partner = TradingPartner::query()->create([
                'name' => 'Atp Blocked Dispenser '.Str::random(6),
                'partner_type' => PartnerType::Pharmacy,
                'country_code' => 'US',
                'is_active' => true,
                'gln' => null,
                'sgln' => null,
            ]);
            $this->partnerIds[] = (int) $partner->getKey();

            $destGln = $this->uniqueGln('037089');
            $destSgln = Sgln::toUrn($destGln, 6);
            $this->assertNotNull($destSgln);

            $component = Livewire::test(PharmacyOutboundDesk::class)
                ->assertSuccessful()
                ->callAction('startShipOrder')
                ->assertHasNoActionErrors();

            $sessionId = (int) $component->get('sessionId');
            $this->assertGreaterThan(0, $sessionId);
            $this->sessionIds[] = $sessionId;

            $component
                ->set('tradingPartnerId', (int) $partner->getKey())
                ->set('destGln', $destGln)
                ->set('destSgln', $destSgln)
                ->set('asn', 'ASN-CG8-'.Str::random(4))
                ->set('po', 'PO-CG8-'.Str::random(4))
                ->set('dscsaAffirm', true)
                ->callAction('saveRefs')
                ->assertHasNoActionErrors()
                ->assertSee(AtpDisclosure::SHORT, false)
                ->assertSee('ATP license', false);

            $session = OutboundShippingSession::query()->findOrFail($sessionId);
            $this->assertNotSame('completed', $session->status);
        } finally {
            $this->cleanup();
        }
    }

    /**
     * @return array{0: OutboundShippingSession, 1: Site}
     */
    private function readySessionWithBlankDestSgln(Tenant $tenant): array
    {
        $this->actingAs($this->createOwner(TenantProfile::DrugWholesaler));

        $site = $this->createShipSite($tenant);
        $ssccUri = $this->makeUniqueSsccShippableAtSite($site);

        $customer = TradingPartner::query()->create([
            'name' => 'Collect Gln Customer '.Str::random(6),
            'gln' => $this->uniqueGln('037100'),
            'sgln' => null,
            'partner_type' => PartnerType::Pharmacy,
            'country_code' => 'US',
            'is_active' => true,
        ]);
        $this->partnerIds[] = (int) $customer->getKey();

        $shipTo = Site::query()->create([
            'trading_partner_id' => (int) $customer->getKey(),
            'name' => 'Ship-To '.Str::random(6),
            'gln' => $this->uniqueGln('037020'),
            'sgln' => null,
            'street_address' => Str::random(8).' Market St',
            'city' => 'Austin',
            'state' => 'TX',
            'zipcode' => '73301',
            'country_code' => 'US',
            'is_active' => true,
            'is_organization_facility' => false,
        ]);
        $this->siteIds[] = (int) $shipTo->getKey();
        $shipTo->forceFill(['sgln' => null])->save();
        $customer->forceFill(['sgln' => null])->save();

        $license = AtpLicense::query()->create([
            'site_id' => (int) $shipTo->getKey(),
            'facility_type' => FacilityType::Wdd,
            'license_number' => 'LIC-'.Str::random(8),
            'license_state' => 'TX',
            'license_expiration_date' => now()->addYear(),
            'reporting_year' => (int) now()->year,
        ]);
        $this->atpLicenseIds[] = (int) $license->getKey();

        $session = app(OpenOutboundShippingSession::class)->handle((int) $site->getKey());
        $this->sessionIds[] = (int) $session->getKey();

        $confirmed = app(ConfirmOutboundShippingScan::class)->handle($session, $ssccUri);
        $this->assertTrue($confirmed['ok'], 'Unique SSCC was not confirmable: '.$confirmed['message']);

        app(UpdateOutboundShippingParty::class)->handle($session->fresh(), [
            'trading_partner_id' => (int) $customer->getKey(),
            'ship_to_site_id' => (int) $shipTo->getKey(),
            'ship_to_gln' => (string) $shipTo->gln,
        ]);
        app(UpdateOutboundShippingReferences::class)->handle($session->fresh(), [
            'asn_number' => 'ASN-CG-'.Str::random(4),
            'customer_po' => 'PO-CG-'.Str::random(4),
            'dscsa_affirm' => true,
        ]);

        return [$session->fresh(), $shipTo->fresh()];
    }

    private function initializeTenant(TenantProfile $profile): Tenant
    {
        $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);

        if ($tenant === null) {
            $tenant = Tenant::withoutEvents(fn () => Tenant::query()->create([
                'id' => self::DEMO2_TENANT_ID,
                'name' => 'Demo Collect Gln',
                'profile' => $profile,
                'status' => 'active',
                'tenancy_db_name' => self::DEMO2_DATABASE,
            ]));
            $tenant->domains()->firstOrCreate(['domain' => self::DEMO2_DOMAIN]);
        } else {
            $tenant->domains()->firstOrCreate(['domain' => self::DEMO2_DOMAIN]);
        }

        $this->priorProfile = $tenant->profile instanceof TenantProfile
            ? $tenant->profile
            : TenantProfile::tryFrom((string) $tenant->profile);

        $tenant->forceFill(['profile' => $profile])->save();

        if (! self::$demo2TenantReady) {
            $this->artisan('tenants:migrate', [
                '--tenants' => [self::DEMO2_TENANT_ID],
                '--force' => true,
            ])->assertSuccessful();
            self::$demo2TenantReady = true;
        }

        tenancy()->initialize($tenant->fresh());

        if (! $this->receivingStateCaptured) {
            $this->setTenantReceivingState($tenant, 'TX');
        }

        return tenant() instanceof Tenant ? tenant() : $tenant;
    }

    private function createOwner(TenantProfile $profile): User
    {
        app(TenantRoleSeeder::class)->seedForProfile($profile);
        $user = User::factory()->create([
            'email' => 'collect-gln-'.uniqid('', true).'@example.test',
        ]);
        $user->assignRole(TenantRole::Owner->value);
        $this->userIds[] = (int) $user->getKey();

        return $user;
    }

    private function createShipSite(Tenant $tenant, string $companyPrefix = '036615'): Site
    {
        $liveTenant = tenant() instanceof Tenant ? tenant() : $tenant;
        $settings = TenantSettings::forTenant($liveTenant);
        if ($this->priorDefaultShipFromSiteId === null) {
            $this->priorDefaultShipFromSiteId = $settings->defaultShipFromSiteId();
        }
        if ($this->priorDefaultReceiveSiteId === null) {
            $this->priorDefaultReceiveSiteId = $settings->defaultReceiveSiteId();
        }

        $siteGln = $this->uniqueGln($companyPrefix);
        $site = Site::query()->create([
            'name' => 'Collect Gln Ship '.Str::random(6),
            'gln' => $siteGln,
            'is_active' => true,
            'is_headquarters' => true,
            'is_organization_facility' => true,
            'trading_partner_id' => null,
        ]);
        $this->siteIds[] = (int) $site->getKey();

        $settings->saveOrganization([
            'gln' => $siteGln,
            'company_prefix' => $companyPrefix,
            'default_ship_from_site_id' => (int) $site->getKey(),
            'default_receive_site_id' => (int) $site->getKey(),
        ]);

        return $site;
    }

    private function makeUniqueSsccShippableAtSite(Site $site): string
    {
        do {
            $ssccUri = 'urn:epc:id:sscc:030116.0'.str_pad((string) random_int(0, 9_999_999_999), 10, '0', STR_PAD_LEFT);
        } while (Epc::query()->where('epc_uri', $ssccUri)->exists());
        do {
            $sgtinUri = 'urn:epc:id:sgtin:030116.0200116.'.(string) random_int(10_000_000_000_000, 99_999_999_999_999);
        } while (Epc::query()->where('epc_uri', $sgtinUri)->exists());

        $fixture = base_path('tests/Fixtures/epcis/minimal_object_shipping.xml');
        $xml = (string) file_get_contents($fixture);
        $xml = str_replace(
            [
                '11111111-2222-3333-4444-555555555555',
                'urn:epc:id:sscc:030116.01001227052',
                'urn:epc:id:sgtin:030116.0200116.10000082001560',
            ],
            [(string) Str::uuid(), $ssccUri, $sgtinUri],
            $xml,
        );

        $tmp = tempnam(sys_get_temp_dir(), 'epcis_');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, $xml);

        try {
            $document = app(IngestEpcisXmlDocument::class)->handle($tmp, [
                'direction' => 'inbound',
                'original_filename' => 'collect-gln-'.Str::random(6).'.xml',
            ]);
        } finally {
            @unlink($tmp);
        }

        $this->documentIds[] = (int) $document->getKey();
        $this->assertSame('validated', $document->status, (string) $document->error_message);

        $epcIds = Epc::query()
            ->whereIn('epc_uri', [$ssccUri, $sgtinUri])
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
        $this->epcIds = array_values(array_unique([...$this->epcIds, ...$epcIds]));

        $session = app(OpenReceivingSessionFromDocument::class)->handle($document);
        $this->receivingSessionIds[] = (int) $session->getKey();
        $session->forceFill(['site_id' => (int) $site->getKey()])->save();

        app(ConfirmReceivingScan::class)->handle(
            $session->fresh(),
            $ssccUri,
            userId: null,
            autoConfirmChildren: true,
        );

        $session->fresh()?->forceFill([
            'status' => 'completed',
            'completed_at' => now(),
            'receiving_events_generated_at' => now(),
        ])->save();

        return $ssccUri;
    }

    private function setTenantReceivingState(Tenant $tenant, ?string $state): void
    {
        $target = tenant() instanceof Tenant ? tenant() : $tenant;

        if (! $this->receivingStateCaptured) {
            $this->priorReceivingState = $target->receiving_state !== null
                ? (string) $target->receiving_state
                : null;
            $this->receivingStateCaptured = true;
        }

        $target->receiving_state = $state;
        $target->save();

        if (tenancy()->initialized) {
            tenancy()->end();
        }

        tenancy()->initialize($target->fresh());
    }

    private function uniqueGln(string $companyPrefix): string
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $body12 = $companyPrefix.str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $gln = $body12.$this->gs1CheckDigit($body12);

            $taken = Site::query()->where('gln', $gln)->exists()
                || TradingPartner::query()->where('gln', $gln)->exists();

            if (! $taken) {
                return $gln;
            }
        }

        throw new \RuntimeException('Unable to allocate a unique GLN for the test.');
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

        if ($this->sessionIds !== []) {
            OutboundShippingSession::query()->whereIn('id', $this->sessionIds)->delete();
        }
        $this->sessionIds = [];

        if ($this->receivingSessionIds !== []) {
            ReceivingSession::query()->whereIn('id', $this->receivingSessionIds)->delete();
        }
        $this->receivingSessionIds = [];

        if ($this->documentIds !== [] || $this->epcIds !== []) {
            if (Schema::hasTable('document_epcs')) {
                DB::table('document_epcs')->where(function ($query): void {
                    if ($this->documentIds !== []) {
                        $query->whereIn('document_id', $this->documentIds);
                    }
                    if ($this->epcIds !== []) {
                        $query->orWhereIn('epc_id', $this->epcIds);
                    }
                })->delete();
            }
            if ($this->documentIds !== [] && Schema::hasTable('epcis_events')) {
                $eventIds = DB::table('epcis_events')
                    ->whereIn('document_id', $this->documentIds)
                    ->pluck('id')
                    ->all();
                if ($eventIds !== [] && Schema::hasTable('event_epcs')) {
                    DB::table('event_epcs')->whereIn('event_id', $eventIds)->delete();
                }
                if ($eventIds !== [] && Schema::hasTable('event_locations')) {
                    DB::table('event_locations')->whereIn('event_id', $eventIds)->delete();
                }
                if ($eventIds !== [] && Schema::hasTable('event_parties')) {
                    DB::table('event_parties')->whereIn('event_id', $eventIds)->delete();
                }
                DB::table('epcis_events')->whereIn('document_id', $this->documentIds)->delete();
            }
            if ($this->documentIds !== []) {
                EpcisDocument::query()->whereIn('id', $this->documentIds)->delete();
            }
        }
        $this->documentIds = [];

        if ($this->epcIds !== []) {
            if (Schema::hasTable('event_epcs')) {
                DB::table('event_epcs')->whereIn('epc_id', $this->epcIds)->delete();
            }
            if (Schema::hasTable('document_epcs')) {
                DB::table('document_epcs')->whereIn('epc_id', $this->epcIds)->delete();
            }
            if (Schema::hasTable('receiving_scan_lines')) {
                DB::table('receiving_scan_lines')->whereIn('epc_id', $this->epcIds)->delete();
            }
            if (Schema::hasTable('outbound_shipping_scan_lines')) {
                DB::table('outbound_shipping_scan_lines')->whereIn('epc_id', $this->epcIds)->delete();
            }
            AggregationLink::query()
                ->where(function ($query): void {
                    $query->whereIn('parent_epc_id', $this->epcIds)
                        ->orWhereIn('child_epc_id', $this->epcIds);
                })
                ->delete();
            if (Schema::hasTable('epc_ilmd')) {
                DB::table('epc_ilmd')->whereIn('epc_id', $this->epcIds)->delete();
            }
            Epc::query()->whereIn('id', $this->epcIds)->delete();
        }
        $this->epcIds = [];

        if ($this->atpLicenseIds !== []) {
            AtpLicense::query()->whereIn('id', $this->atpLicenseIds)->delete();
        }
        $this->atpLicenseIds = [];

        if ($this->priorDefaultShipFromSiteId !== null || $this->priorDefaultReceiveSiteId !== null) {
            TenantSettings::forTenant($tenant)->saveOrganization(array_filter([
                'default_ship_from_site_id' => $this->priorDefaultShipFromSiteId,
                'default_receive_site_id' => $this->priorDefaultReceiveSiteId,
            ], static fn (mixed $value): bool => $value !== null));
            $this->priorDefaultShipFromSiteId = null;
            $this->priorDefaultReceiveSiteId = null;
        }

        if ($this->partnerIds !== []) {
            $partnerSiteIds = Site::query()
                ->whereIn('trading_partner_id', $this->partnerIds)
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();
            $this->siteIds = array_values(array_unique([...$this->siteIds, ...$partnerSiteIds]));
        }

        if ($this->siteIds !== []) {
            Site::query()->whereIn('id', $this->siteIds)->delete();
        }
        $this->siteIds = [];

        if ($this->partnerIds !== []) {
            TradingPartner::query()->whereKey($this->partnerIds)->update([
                'customer_portal_uuid' => null,
                'portal_share_uuid' => null,
            ]);
            TradingPartner::query()->whereIn('id', $this->partnerIds)->delete();
        }
        $this->partnerIds = [];

        if ($this->userIds !== []) {
            User::query()->whereIn('id', $this->userIds)->delete();
        }
        $this->userIds = [];

        if ($this->receivingStateCaptured && $tenant instanceof Tenant) {
            $tenant->forceFill(['receiving_state' => $this->priorReceivingState])->save();
            $this->receivingStateCaptured = false;
            $this->priorReceivingState = null;
        }

        if ($this->priorProfile !== null && $tenant instanceof Tenant) {
            $tenant->forceFill(['profile' => $this->priorProfile])->save();
            $this->priorProfile = null;
        }

        tenancy()->end();
    }
}
