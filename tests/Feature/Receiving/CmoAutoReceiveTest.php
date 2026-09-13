<?php

declare(strict_types=1);

namespace Tests\Feature\Receiving;

use App\Actions\Epcis\IngestEpcisXmlDocument;
use App\Actions\Epcis\ValidateEpcis12Document;
use App\Actions\Receiving\AutoReceiveCmoInboundDocument;
use App\Actions\Receiving\ConfirmReceivingScan;
use App\Actions\Receiving\OpenReceivingSessionFromDocument;
use App\Enums\CmoOwnership;
use App\Enums\EpcisReceivedVia;
use App\Enums\PartnerType;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisException;
use App\Models\Receiving\ReceivingSession;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\Shipping\ShippableEpcsAtSite;
use App\Support\TenantSettings;
use DomainException;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\PreparesDemo2ReceivingState;
use Tests\TestCase;

class CmoAutoReceiveTest extends TestCase
{
    use PreparesDemo2ReceivingState;

    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private const SSCC_URI = 'urn:epc:id:sscc:030116.01001227052';

    private const SGTIN_URI = 'urn:epc:id:sgtin:030116.0200116.10000082001560';

    private const RECEIVE_SITE_GLN = '0366159000088';

    private static bool $demo2TenantReady = false;

    private ?int $documentId = null;

    private ?int $sessionId = null;

    private ?int $siteId = null;

    private ?int $partnerId = null;

    private ?int $userId = null;

    private ?bool $priorAutoReceiveFromCmo = null;

    #[Test]
    public function master_off_does_not_auto_receive(): void
    {
        $this->initializeAsManufacturer();

        try {
            TenantSettings::forTenant(tenant())->setAutoReceiveFromCmo(false);
            tenant()->save();

            $partner = $this->createCmoPartner(autoReceive: true);
            $document = $this->ingestPartnerShippingDocument($partner);

            $session = app(AutoReceiveCmoInboundDocument::class)->handle($document);

            $this->assertNull($session);
            $this->assertFalse(app(AutoReceiveCmoInboundDocument::class)->shouldAutoReceive($document));
            $this->assertSame(0, ReceivingSession::query()->where('epcis_document_id', $document->getKey())->count());
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function master_on_but_partner_not_cmo_does_not_auto_receive(): void
    {
        $this->initializeAsManufacturer();

        try {
            TenantSettings::forTenant(tenant())->setAutoReceiveFromCmo(true);
            tenant()->save();

            $partner = TradingPartner::factory()->create([
                'name' => 'Non-CMO Partner',
                'partner_type' => PartnerType::Manufacturer,
                'is_active' => true,
                'is_cmo' => false,
                'auto_receive_inbound' => true,
            ]);
            $this->partnerId = (int) $partner->getKey();

            $document = $this->ingestPartnerShippingDocument($partner);

            $this->assertFalse(app(AutoReceiveCmoInboundDocument::class)->shouldAutoReceive($document));
            $this->assertNull(app(AutoReceiveCmoInboundDocument::class)->handle($document));
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function both_gates_on_auto_receive_puts_serials_on_hand(): void
    {
        $this->initializeAsManufacturer();

        try {
            TenantSettings::forTenant(tenant())->setAutoReceiveFromCmo(true);
            tenant()->save();

            $partner = $this->createCmoPartner(autoReceive: true);
            $document = $this->ingestPartnerShippingDocument($partner);

            $this->assertTrue(
                app(AutoReceiveCmoInboundDocument::class)->shouldAutoReceive($document),
                'expected dual-gate auto-receive eligibility for validated CMO shipping ASN',
            );

            $session = app(AutoReceiveCmoInboundDocument::class)->handle($document);
            $this->assertNotNull($session, 'auto-receive handle returned null — check receiving.auto_cmo_receive_failed logs');
            $this->sessionId = (int) $session->getKey();

            $session->refresh();
            $this->assertSame('completed', $session->status);
            $this->assertSame($this->siteId, $session->site_id);

            $epc = Epc::query()->where('epc_uri', self::SGTIN_URI)->firstOrFail();
            $this->assertTrue(
                app(ShippableEpcsAtSite::class)->contains($this->siteId, (int) $epc->getKey()),
                'CMO auto-receive must leave serials on-hand at the destination site.',
            );
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function guardian_lot_close_documents_are_not_auto_received(): void
    {
        $this->initializeAsManufacturer();

        try {
            TenantSettings::forTenant(tenant())->setAutoReceiveFromCmo(true);
            tenant()->save();

            $partner = $this->createCmoPartner(autoReceive: true);
            $document = $this->ingestPartnerShippingDocument($partner);
            $document->forceFill(['received_via' => EpcisReceivedVia::GuardianLotClose])->save();

            $this->assertFalse(app(AutoReceiveCmoInboundDocument::class)->shouldAutoReceive($document->fresh()));
            $this->assertNull(app(AutoReceiveCmoInboundDocument::class)->handle($document->fresh()));
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function own_product_cmo_inbound_without_ts_is_soft_and_auto_receivable(): void
    {
        $this->initializeAsManufacturer();

        try {
            TenantSettings::forTenant(tenant())->setAutoReceiveFromCmo(true);
            tenant()->save();
            config(['tracepharma.epcis.enforce_ts_for_receiving' => true]);

            $partner = $this->createCmoPartner(autoReceive: true, ownership: CmoOwnership::OwnProduct);
            $document = $this->ingestPartnerShippingDocument($partner, dscsaAffirm: false);

            $findings = app(ValidateEpcis12Document::class)->handle($document->fresh());
            $document = $document->fresh();

            $this->assertSame('validated', $document->status, 'own-product CMO missing TS must not hard-fail validation');
            $this->assertFalse((bool) $document->dscsa_affirm);

            $tsFindings = array_values(array_filter(
                $findings,
                static fn ($f): bool => $f->exceptionType === 'MISSING_DSCSA_STATEMENT',
            ));
            $this->assertNotEmpty($tsFindings);
            $this->assertSame('warning', $tsFindings[0]->severity);
            $this->assertFalse($tsFindings[0]->isBlocking());

            $openTs = EpcisException::query()
                ->where('document_id', $document->getKey())
                ->where('exception_type', 'MISSING_DSCSA_STATEMENT')
                ->where('status', 'open')
                ->get();
            $this->assertTrue($openTs->isNotEmpty());
            $this->assertTrue($openTs->every(fn (EpcisException $e): bool => $e->severity === 'warning'));

            $this->assertSame(
                \App\Enums\ExceptionReceiveImpact::Soft,
                \App\Support\Exceptions\ExceptionReceiveImpactMap::forCodeOnDocument(
                    'MISSING_DSCSA_STATEMENT',
                    $document,
                ),
            );

            $this->assertTrue(app(AutoReceiveCmoInboundDocument::class)->shouldAutoReceive($document));
            $session = app(AutoReceiveCmoInboundDocument::class)->handle($document);
            $this->assertNotNull($session, 'own-product CMO without TS must still auto-receive');
            $this->sessionId = (int) $session->getKey();
            $this->assertSame('completed', $session->fresh()->status);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function cmo_sells_inbound_without_ts_still_hard_blocks(): void
    {
        $this->initializeAsManufacturer();

        try {
            TenantSettings::forTenant(tenant())->setAutoReceiveFromCmo(true);
            tenant()->save();
            config(['tracepharma.epcis.enforce_ts_for_receiving' => true]);

            $partner = $this->createCmoPartner(autoReceive: true, ownership: CmoOwnership::CmoSells);
            $document = $this->ingestPartnerShippingDocument($partner, dscsaAffirm: false);

            $findings = app(ValidateEpcis12Document::class)->handle($document->fresh());
            $document = $document->fresh();

            $this->assertSame('error', $document->status);
            $tsFindings = array_values(array_filter(
                $findings,
                static fn ($f): bool => $f->exceptionType === 'MISSING_DSCSA_STATEMENT',
            ));
            $this->assertNotEmpty($tsFindings);
            $this->assertTrue($tsFindings[0]->isBlocking());

            $this->assertFalse(app(AutoReceiveCmoInboundDocument::class)->shouldAutoReceive($document));
            $this->assertNull(app(AutoReceiveCmoInboundDocument::class)->handle($document));

            // Even if status were forced validated, open receive must still enforce TS for cmo_sells.
            $document->forceFill(['status' => 'validated'])->save();
            $this->expectException(DomainException::class);
            $this->expectExceptionMessage('DSCSA transaction statement');
            app(OpenReceivingSessionFromDocument::class)->handle($document->fresh(), asSystem: true);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function wholesaler_inbound_without_ts_remains_hard_block(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $tenant->forceFill(['profile' => TenantProfile::DrugWholesaler])->save();

        $settings = TenantSettings::forTenant($tenant);
        $this->priorAutoReceiveFromCmo = $settings->autoReceiveFromCmo();
        $settings->setAutoReceiveFromCmo(true);
        $tenant->save();

        Filament::setCurrentPanel(Filament::getPanel('app'));
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::DrugWholesaler);

        $user = User::factory()->create([
            'email' => 'cmo-ws-'.uniqid().'@example.test',
        ]);
        $this->userId = (int) $user->getKey();
        $user->assignRole(TenantRole::Owner->value);
        $this->actingAs($user);
        $this->prepareDemo2ReceivingState([self::SSCC_URI, self::SGTIN_URI]);

        try {
            config(['tracepharma.epcis.enforce_ts_for_receiving' => true]);

            $partner = TradingPartner::factory()->create([
                'name' => 'Wholesaler Supplier '.uniqid(),
                'partner_type' => PartnerType::Manufacturer,
                'is_active' => true,
                'is_cmo' => false,
            ]);
            $this->partnerId = (int) $partner->getKey();

            $document = $this->ingestPartnerShippingDocument($partner, dscsaAffirm: false);
            $findings = app(ValidateEpcis12Document::class)->handle($document->fresh());
            $document = $document->fresh();

            $this->assertSame('error', $document->status);
            $tsFindings = array_values(array_filter(
                $findings,
                static fn ($f): bool => $f->exceptionType === 'MISSING_DSCSA_STATEMENT',
            ));
            $this->assertNotEmpty($tsFindings);
            $this->assertTrue($tsFindings[0]->isBlocking());
            $this->assertFalse(app(AutoReceiveCmoInboundDocument::class)->shouldAutoReceive($document));
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function failed_confirm_cancels_open_session_so_auto_receive_can_retry(): void
    {
        $this->initializeAsManufacturer();

        try {
            TenantSettings::forTenant(tenant())->setAutoReceiveFromCmo(true);
            tenant()->save();

            $partner = $this->createCmoPartner(autoReceive: true);
            $document = $this->ingestPartnerShippingDocument($partner);

            $this->mock(ConfirmReceivingScan::class, function ($mock): void {
                $mock->shouldReceive('handle')
                    ->atLeast()
                    ->once()
                    ->andReturn([
                        'ok' => false,
                        'message' => 'Simulated confirm failure',
                        'tone' => 'error',
                        'line' => null,
                        'epc' => null,
                        'effect' => 'simulated_failure',
                    ]);
            });

            $result = app(AutoReceiveCmoInboundDocument::class)->handle($document->fresh());
            $this->assertNull($result);

            $session = ReceivingSession::query()
                ->where('epcis_document_id', $document->getKey())
                ->first();
            $this->assertNotNull($session);
            $this->sessionId = (int) $session->getKey();
            $this->assertSame(
                'cancelled',
                $session->fresh()->status,
                'Failed auto-receive must cancel the leftover session so serials are not locked',
            );

            $this->assertTrue(
                app(AutoReceiveCmoInboundDocument::class)->shouldAutoReceive($document->fresh()),
                'After cancel, shouldAutoReceive must allow retry',
            );
        } finally {
            $this->cleanup();
        }
    }

    private function createCmoPartner(bool $autoReceive, CmoOwnership $ownership = CmoOwnership::CmoSells): TradingPartner
    {
        $partner = TradingPartner::factory()->create([
            'name' => 'CMO Packager '.uniqid(),
            'partner_type' => PartnerType::Manufacturer,
            'is_active' => true,
            'is_cmo' => true,
            'auto_receive_inbound' => $autoReceive,
            'cmo_ownership' => $ownership,
        ]);
        $this->partnerId = (int) $partner->getKey();

        return $partner;
    }

    private function ingestPartnerShippingDocument(TradingPartner $partner, bool $dscsaAffirm = true): EpcisDocument
    {
        $site = Site::query()->create([
            'name' => 'CMO Auto Dock',
            'gln' => self::RECEIVE_SITE_GLN,
            'is_active' => true,
            'is_headquarters' => false,
            'is_organization_facility' => true,
        ]);
        $this->siteId = (int) $site->getKey();

        $document = $this->ingestMinimalFixtureWithShipping();
        $this->documentId = (int) $document->getKey();

        $document->forceFill([
            'trading_partner_id' => $partner->getKey(),
            'ship_to_site_id' => $this->siteId,
            'direction' => 'inbound',
            'status' => 'validated',
            'dscsa_affirm' => $dscsaAffirm,
            'received_via' => EpcisReceivedVia::HttpsWebhook,
        ])->save();

        return $document->fresh();
    }

    private function ingestMinimalFixtureWithShipping(): EpcisDocument
    {
        $fixture = base_path('tests/Fixtures/epcis/minimal_object_shipping.xml');
        $this->assertFileExists($fixture);

        $tmp = tempnam(sys_get_temp_dir(), 'epcis_');
        $this->assertNotFalse($tmp);
        $xml = file_get_contents($fixture);
        $this->assertNotFalse($xml);
        $uuid = (string) str()->uuid();
        $xml = str_replace('11111111-2222-3333-4444-555555555555', $uuid, $xml);

        $shippingEvent = <<<'XML'
      <ObjectEvent>
        <eventTime>2026-07-15T20:00:00.000Z</eventTime>
        <eventTimeZoneOffset>-05:00</eventTimeZoneOffset>
        <epcList>
          <epc>urn:epc:id:sscc:030116.01001227052</epc>
        </epcList>
        <action>OBSERVE</action>
        <bizStep>urn:epcglobal:cbv:bizstep:shipping</bizStep>
        <disposition>urn:epcglobal:cbv:disp:in_transit</disposition>
        <readPoint>
          <id>urn:epc:id:sgln:030116.000000.0</id>
        </readPoint>
        <bizLocation>
          <id>urn:epc:id:sgln:030116.000000.0</id>
        </bizLocation>
      </ObjectEvent>
XML;
        $xml = str_replace('</EventList>', $shippingEvent."\n    </EventList>", $xml);
        file_put_contents($tmp, $xml);

        try {
            return app(IngestEpcisXmlDocument::class)->handle($tmp, [
                'direction' => 'inbound',
                'original_filename' => 'cmo_partner_shipping.xml',
            ]);
        } finally {
            @unlink($tmp);
        }
    }

    private function initializeAsManufacturer(): Tenant
    {
        $tenant = $this->initializeDemo2Tenant();
        $tenant->forceFill(['profile' => TenantProfile::Manufacturer])->save();

        $settings = TenantSettings::forTenant($tenant);
        $this->priorAutoReceiveFromCmo = $settings->autoReceiveFromCmo();

        Filament::setCurrentPanel(Filament::getPanel('app'));
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Manufacturer);

        $user = User::factory()->create([
            'email' => 'cmo-auto-'.uniqid().'@example.test',
        ]);
        $this->userId = (int) $user->getKey();
        $user->assignRole(TenantRole::Owner->value);
        $this->actingAs($user);

        $this->prepareDemo2ReceivingState([self::SSCC_URI, self::SGTIN_URI]);

        return $tenant;
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

        if (! Schema::hasColumn('trading_partners', 'is_cmo')
            || ! Schema::hasColumn('trading_partners', 'cmo_ownership')) {
            $this->artisan('tenants:migrate', [
                '--tenants' => [self::DEMO2_TENANT_ID],
                '--force' => true,
            ])->assertSuccessful();
        }

        return $tenant;
    }

    private function cleanup(): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        $tenant = tenant();

        if ($this->sessionId !== null) {
            $this->deleteReceivingSessionForIsolation($this->sessionId);
            $this->sessionId = null;
        }

        if ($this->documentId !== null) {
            ReceivingSession::query()->where('epcis_document_id', $this->documentId)->get()
                ->each(fn (ReceivingSession $session) => $this->deleteReceivingSessionForIsolation((int) $session->getKey()));
            EpcisDocument::query()->whereKey($this->documentId)->delete();
            $this->documentId = null;
        }

        if ($this->partnerId !== null) {
            TradingPartner::query()->whereKey($this->partnerId)->delete();
            $this->partnerId = null;
        }

        if ($this->siteId !== null) {
            Site::query()->whereKey($this->siteId)->delete();
            $this->siteId = null;
        }

        if ($this->userId !== null) {
            User::query()->whereKey($this->userId)->delete();
            $this->userId = null;
        }

        if ($this->priorAutoReceiveFromCmo !== null && $tenant !== null) {
            TenantSettings::forTenant($tenant)->setAutoReceiveFromCmo($this->priorAutoReceiveFromCmo);
            $this->priorAutoReceiveFromCmo = null;
        }

        $tenant?->forceFill(['profile' => TenantProfile::Pharmacy])->save();
        tenancy()->end();
    }
}
