<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Actions\Epcis\IngestEpcisXmlDocument;
use App\Actions\Receiving\ConfirmReceivingScan;
use App\Actions\Receiving\OpenReceivingSessionFromDocument;
use App\Actions\Shipping\ConfirmOutboundShippingScan;
use App\Actions\Shipping\GenerateShippingEpcisEvents;
use App\Actions\Shipping\OpenOutboundShippingSession;
use App\Actions\Shipping\UpdateOutboundShippingParty;
use App\Actions\Shipping\UpdateOutboundShippingReferences;
use App\Enums\FacilityType;
use App\Enums\OutboundTransport;
use App\Enums\PartnerType;
use App\Enums\SerializationProvider;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Models\AtpLicense;
use App\Models\Epcis\AggregationLink;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisException;
use App\Models\OutboundConnection;
use App\Models\Quarantine\QuarantineHold;
use App\Models\Receiving\ReceivingScanLine;
use App\Models\Receiving\ReceivingSession;
use App\Models\Shipping\OutboundShippingScanLine;
use App\Models\Shipping\OutboundShippingSession;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Models\Transferring\TransferringScanLine;
use App\Models\User;
use App\Services\Epcis\Contracts\OutboundEpcisTransmitter;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\Epcis\EpcisSchemaVersion;
use App\Support\Gs1\Sgln;
use App\Support\TenantSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DualStackShipAuthoringTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private const SSCC_URI = 'urn:epc:id:sscc:030116.01001227052';

    private const SGTIN_URI = 'urn:epc:id:sgtin:030116.0200116.10000082001560';

    private const DEMO_PARTNER_GLN = '0614141000005';

    private const DEMO_PARTNER_SGLN = 'urn:epc:id:sgln:0614141.00000.0';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $sessionIds = [];

    /** @var list<int> */
    private array $receivingSessionIds = [];

    /** @var list<int> */
    private array $documentIds = [];

    /** @var list<int> */
    private array $connectionIds = [];

    /** @var list<int> */
    private array $siteIds = [];

    /** @var list<int> */
    private array $userIds = [];

    /** @var list<int> */
    private array $atpLicenseIds = [];

    #[Test]
    public function partner_12_gets_xml_on_ship_authoring(): void
    {
        $tenant = $this->initializeWholesalerTenant();

        try {
            config(['tracepharma.epcis_jobs.enabled' => false]);

            Http::fake([
                'https://partner.example/epcis' => Http::response('OK', 202),
            ]);

            $site = $this->createShipSite($tenant);
            $this->makeEpcShippableAtSite($site);
            $partner = $this->ensureDemoPartner();
            $connection = $this->createHttpsConnection([
                'epcis_document_version' => '1.2',
            ]);

            $document = $this->authorShippingDocument($site, $partner, $connection);

            $this->assertSame('1.2', $document->schema_version);
            $this->assertSame('xml', $document->format);

            $payload = (string) Storage::disk($document->payload_disk)->get($document->payload_path);
            $this->assertStringContainsString('epcis:EPCISDocument', $payload);
            $this->assertStringContainsString('<ObjectEvent>', $payload);
            $this->assertStringContainsString('urn:epcglobal:cbv:bizstep:shipping', $payload);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function partner_20_gets_json_ld_on_ship_authoring(): void
    {
        $tenant = $this->initializeWholesalerTenant();

        try {
            config([
                'tracepharma.epcis.accept_20' => true,
                'tracepharma.epcis_jobs.enabled' => false,
            ]);
            TenantSettings::forTenant($tenant)->setEpcisAccept20(true);
            $tenant->save();
            $this->assertTrue(EpcisSchemaVersion::accepts20());

            Http::fake([
                'https://partner.example/epcis' => Http::response('OK', 202),
            ]);

            $site = $this->createShipSite($tenant);
            $this->makeEpcShippableAtSite($site);
            $partner = $this->ensureDemoPartner();
            $connection = $this->createHttpsConnection([
                'epcis_document_version' => '2.0',
            ]);

            $document = $this->authorShippingDocument($site, $partner, $connection);

            $this->assertSame('2.0', $document->schema_version);
            $this->assertSame('json', $document->format);

            $payload = (string) Storage::disk($document->payload_disk)->get($document->payload_path);
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);

            $this->assertSame('EPCISDocument', $decoded['type']);
            $this->assertNotEmpty($decoded['epcisBody']['eventList']);
            $this->assertTrue(
                ($decoded['gs1ushc:dscsaTransactionStatement']['gs1ushc:affirmTransactionStatement'] ?? false) === true,
                'JSON-LD ship with dscsa_affirm must include the DSCSA transaction statement.',
            );

            $shippingEvents = array_values(array_filter(
                $decoded['epcisBody']['eventList'],
                static fn (array $event): bool => ($event['type'] ?? null) === 'ObjectEvent'
                    && ($event['bizStep'] ?? null) === 'urn:epcglobal:cbv:bizstep:shipping',
            ));
            $this->assertCount(1, $shippingEvents);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function invalid_xml_outbound_payload_does_not_transmit(): void
    {
        $this->initializeWholesalerTenant();

        try {
            Http::fake([
                'https://partner.example/epcis' => Http::response('OK', 202),
            ]);

            $connection = $this->createHttpsConnection(['epcis_document_version' => '1.2']);
            $badXml = '<?xml version="1.0"?><epcis:EPCISDocument xmlns:epcis="urn:epcglobal:epcis:xsd:1"></epcis:EPCISDocument>';
            $document = $this->createOutboundDocument($connection, $badXml, '1.2', 'xml');

            app(OutboundEpcisTransmitter::class)->transmit($document->fresh());

            $document->refresh();
            $this->assertSame('failed', $document->transmission_status);
            $this->assertNull($document->sent_at);
            $this->assertStringContainsString('Pre-transmit EPCIS validation failed', (string) $document->error_message);
            Http::assertNothingSent();
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function invalid_json_outbound_payload_does_not_transmit(): void
    {
        $this->initializeWholesalerTenant();

        try {
            config(['tracepharma.epcis.accept_20' => true]);

            Http::fake([
                'https://partner.example/epcis' => Http::response('OK', 202),
            ]);

            $connection = $this->createHttpsConnection(['epcis_document_version' => '2.0']);
            $badJson = '{"type":"EPCISDocument","schemaVersion":"2.0","epcisBody":{"eventList":[]}}';
            $document = $this->createOutboundDocument($connection, $badJson, '2.0', 'json');

            app(OutboundEpcisTransmitter::class)->transmit($document->fresh());

            $document->refresh();
            $this->assertSame('failed', $document->transmission_status);
            $this->assertNull($document->sent_at);
            $this->assertStringContainsString('Pre-transmit EPCIS validation failed', (string) $document->error_message);
            Http::assertNothingSent();
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function partner_12_cannot_transmit_json_ld_payload(): void
    {
        $this->initializeWholesalerTenant();

        try {
            config(['tracepharma.epcis.accept_20' => true]);

            Http::fake([
                'https://partner.example/epcis' => Http::response('OK', 202),
            ]);

            $connection = $this->createHttpsConnection(['epcis_document_version' => '1.2']);
            $json = file_get_contents(base_path('tests/Fixtures/epcis/minimal_object_packing_2.0.json'));
            $this->assertNotFalse($json);
            $json = str_replace('22222222-3333-4444-5555-666666666666', (string) Str::uuid(), $json);

            $document = $this->createOutboundDocument($connection, $json, '2.0', 'json');

            app(OutboundEpcisTransmitter::class)->transmit($document->fresh());

            $document->refresh();
            $this->assertSame('failed', $document->transmission_status);
            $this->assertNull($document->sent_at);
            $this->assertStringContainsString('JSON-LD', (string) $document->error_message);
            $this->assertStringContainsString('1.2', (string) $document->error_message);
            Http::assertNothingSent();
        } finally {
            $this->cleanup();
        }
    }

    private function authorShippingDocument(
        Site $site,
        TradingPartner $partner,
        OutboundConnection $connection,
    ): EpcisDocument {
        $session = app(OpenOutboundShippingSession::class)->handle((int) $site->getKey());
        $this->sessionIds[] = (int) $session->getKey();

        $confirmed = app(ConfirmOutboundShippingScan::class)->handle($session, self::SSCC_URI);
        $this->assertTrue($confirmed['ok'], $confirmed['message']);

        app(UpdateOutboundShippingParty::class)->handle($session->fresh(), [
            'trading_partner_id' => (int) $partner->getKey(),
            'outbound_connection_id' => (int) $connection->getKey(),
        ]);
        app(UpdateOutboundShippingReferences::class)->handle($session->fresh(), [
            'asn_number' => 'ASN-DUAL-'.Str::random(4),
            'customer_po' => 'PO-DUAL-'.Str::random(4),
            'dscsa_affirm' => true,
        ]);

        $session->fresh()->forceFill([
            'status' => 'completed',
            'completed_at' => now(),
        ])->save();

        $generated = app(GenerateShippingEpcisEvents::class)->handle($session->fresh());
        $this->assertTrue($generated['generated']);
        $this->assertNotNull($generated['document']);

        $document = $generated['document'];
        $this->documentIds[] = (int) $document->getKey();

        return $document->fresh();
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function createHttpsConnection(array $settings = []): OutboundConnection
    {
        $connection = OutboundConnection::query()->create([
            'name' => 'Dual-stack HTTPS '.Str::random(4),
            'serialization_provider' => SerializationProvider::CustomHttps,
            'transport' => OutboundTransport::Https,
            'is_active' => true,
            'settings' => array_merge(['endpoint_url' => 'https://partner.example/epcis'], $settings),
            'credentials' => ['webhook_token' => 'dual-stack-token'],
        ]);
        $this->connectionIds[] = (int) $connection->getKey();

        return $connection;
    }

    private function createOutboundDocument(
        OutboundConnection $connection,
        string $payload,
        string $schemaVersion,
        string $format,
    ): EpcisDocument {
        $extension = $format === 'json' ? 'json' : 'xml';
        $path = 'epcis/outbound/dual-stack-'.Str::uuid().'.'.$extension;
        Storage::disk('local')->put($path, $payload);

        $document = EpcisDocument::query()->create([
            'document_uuid' => (string) Str::uuid(),
            'schema_version' => $schemaVersion,
            'creation_date' => now(),
            'direction' => 'outbound',
            'format' => $format,
            'original_filename' => 'dual-stack.'.$extension,
            'payload_disk' => 'local',
            'payload_path' => $path,
            'file_sha256' => hash('sha256', $payload),
            'dscsa_affirm' => true,
            'status' => 'parsed',
            'reprocess_count' => 0,
            'event_count' => 1,
            'epc_count' => 1,
            'received_at' => now(),
            'outbound_connection_id' => $connection->getKey(),
        ]);
        $this->documentIds[] = (int) $document->getKey();

        return $document;
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

        $tenant->forceFill(['profile' => TenantProfile::DrugWholesaler])->save();

        if (! self::$demo2TenantReady) {
            $this->artisan('tenants:migrate', [
                '--tenants' => [self::DEMO2_TENANT_ID],
                '--force' => true,
            ])->assertSuccessful();

            self::$demo2TenantReady = true;
        }

        tenancy()->initialize($tenant->fresh());

        $tenant->forceFill(['receiving_state' => 'TX'])->save();
        tenancy()->end();
        tenancy()->initialize($tenant->fresh());

        return $tenant;
    }

    private function createShipSite(Tenant $tenant, string $companyPrefix = '036615'): Site
    {
        $liveTenant = tenant() instanceof Tenant ? tenant() : $tenant;
        $settings = TenantSettings::forTenant($liveTenant);
        $siteGln = $this->uniqueOrgGln($companyPrefix);

        $site = Site::query()->create([
            'name' => 'Dual-stack Ship Site '.Str::random(6),
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

    private function makeEpcShippableAtSite(Site $site): void
    {
        if (auth()->user() === null) {
            $this->actingAs($this->createShippingUser());
        }

        $this->prepareFixtureReceivingState();

        $document = $this->ingestMinimalFixture();
        $this->documentIds[] = (int) $document->getKey();
        $this->assertSame('validated', $document->status, (string) $document->error_message);

        $session = app(OpenReceivingSessionFromDocument::class)->handle($document);
        $this->receivingSessionIds[] = (int) $session->getKey();
        $session->forceFill(['site_id' => (int) $site->getKey()])->save();

        app(ConfirmReceivingScan::class)->handle(
            $session->fresh(),
            self::SSCC_URI,
            userId: null,
            autoConfirmChildren: true,
        );

        $session->fresh()->forceFill([
            'status' => 'completed',
            'completed_at' => now(),
            'receiving_events_generated_at' => $session->receiving_events_generated_at ?? now(),
        ])->save();

        if ($session->receiving_epcis_document_id !== null) {
            $this->documentIds[] = (int) $session->receiving_epcis_document_id;
        }
    }

    private function ensureDemoPartner(): TradingPartner
    {
        TradingPartner::query()
            ->where('gln', '0614141000003')
            ->update([
                'is_active' => false,
                'name' => '[LEGACY] Demo Downstream Pharmacy',
            ]);

        $partner = TradingPartner::query()->updateOrCreate(
            ['gln' => self::DEMO_PARTNER_GLN],
            [
                'name' => 'Demo Downstream Pharmacy',
                'sgln' => self::DEMO_PARTNER_SGLN,
                'partner_type' => PartnerType::Pharmacy,
                'is_active' => true,
            ],
        );

        $this->ensureDemoPartnerHasShipToSite($partner);

        return $partner;
    }

    private function ensureDemoPartnerHasShipToSite(TradingPartner $partner): Site
    {
        $existing = Site::query()
            ->where('trading_partner_id', (int) $partner->getKey())
            ->where('is_active', true)
            ->where('is_organization_facility', false)
            ->first();

        if ($existing instanceof Site) {
            $site = $existing;
        } else {
            $gln = $this->uniqueOrgGln('061414');
            $site = Site::query()->create([
                'trading_partner_id' => (int) $partner->getKey(),
                'name' => 'Demo Ship-To '.Str::random(6),
                'gln' => $gln,
                'sgln' => Sgln::toUrn($gln, 6),
                'street_address' => '100 Market St',
                'city' => 'Austin',
                'state' => 'TX',
                'zipcode' => '73301',
                'country_code' => 'US',
                'is_active' => true,
                'is_organization_facility' => false,
            ]);
            $this->siteIds[] = (int) $site->getKey();
        }

        if (! AtpLicense::query()
            ->where('site_id', (int) $site->getKey())
            ->where('license_state', 'TX')
            ->exists()) {
            $license = AtpLicense::query()->create([
                'site_id' => (int) $site->getKey(),
                'facility_type' => FacilityType::Wdd,
                'license_number' => 'DEMO-'.Str::random(8),
                'license_state' => 'TX',
                'license_expiration_date' => now()->addYear(),
                'reporting_year' => (int) now()->year,
            ]);
            $this->atpLicenseIds[] = (int) $license->getKey();
        }

        return $site;
    }

    /**
     * @param  list<string>  $epcUris
     */
    private function prepareFixtureReceivingState(array $epcUris = [self::SSCC_URI, self::SGTIN_URI]): void
    {
        $epcIds = Epc::query()
            ->whereIn('epc_uri', $epcUris)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($epcIds === []) {
            return;
        }

        $ssccId = Epc::query()->where('epc_uri', self::SSCC_URI)->value('id');
        if ($ssccId !== null) {
            AggregationLink::query()
                ->where('child_epc_id', (int) $ssccId)
                ->whereNull('valid_to')
                ->update(['valid_to' => now()]);
        }

        foreach ($epcIds as $epcId) {
            QuarantineHold::query()->where('epc_id', $epcId)->delete();
        }

        OutboundShippingScanLine::query()->whereIn('epc_id', $epcIds)->delete();
        TransferringScanLine::query()->whereIn('epc_id', $epcIds)->delete();

        $sessionIds = ReceivingScanLine::query()
            ->whereIn('epc_id', $epcIds)
            ->distinct()
            ->pluck('receiving_session_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        foreach ($sessionIds as $sessionId) {
            $session = ReceivingSession::query()->find($sessionId);
            if ($session === null) {
                continue;
            }

            if ($session->receiving_epcis_document_id !== null) {
                EpcisDocument::query()->whereKey($session->receiving_epcis_document_id)->delete();
            }

            ReceivingScanLine::query()->where('receiving_session_id', $sessionId)->delete();
            $session->delete();
        }
    }

    private function ingestMinimalFixture(): EpcisDocument
    {
        $fixture = base_path('tests/Fixtures/epcis/minimal_object_shipping.xml');
        $this->assertFileExists($fixture);

        $tmp = tempnam(sys_get_temp_dir(), 'epcis_');
        $this->assertNotFalse($tmp);
        $xml = file_get_contents($fixture);
        $this->assertNotFalse($xml);
        $xml = str_replace('11111111-2222-3333-4444-555555555555', (string) Str::uuid(), $xml);
        file_put_contents($tmp, $xml);

        try {
            return app(IngestEpcisXmlDocument::class)->handle($tmp, [
                'direction' => 'inbound',
                'original_filename' => 'minimal_object_shipping.xml',
            ]);
        } finally {
            @unlink($tmp);
        }
    }

    private function createShippingUser(): User
    {
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::DrugWholesaler);

        $user = User::factory()->create([
            'email' => 'dual-stack-ship-'.uniqid('', true).'@example.test',
        ]);
        $user->assignRole(TenantRole::Owner->value);
        $this->userIds[] = (int) $user->getKey();

        return $user;
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

    private function gs1CheckDigit(string $bodyWithoutCheck): string
    {
        $sum = 0;
        for ($i = 0, $len = strlen($bodyWithoutCheck); $i < $len; $i++) {
            $digit = (int) $bodyWithoutCheck[$len - 1 - $i];
            $sum += $digit * ($i % 2 === 0 ? 3 : 1);
        }

        return (string) ((10 - ($sum % 10)) % 10);
    }

    private function cleanup(?Tenant $tenant = null): void
    {
        if (tenancy()->initialized) {
            if ($this->sessionIds !== []) {
                OutboundShippingSession::query()->whereIn('id', $this->sessionIds)->delete();
            }

            if ($this->receivingSessionIds !== []) {
                ReceivingSession::query()->whereIn('id', $this->receivingSessionIds)->delete();
            }

            if ($this->documentIds !== []) {
                EpcisException::query()->whereIn('document_id', $this->documentIds)->delete();

                if (Schema::hasTable('document_epcs')) {
                    DB::table('document_epcs')
                        ->whereIn('document_id', $this->documentIds)
                        ->delete();
                }

                if (Schema::hasTable('epcis_events')) {
                    $eventIds = DB::table('epcis_events')
                        ->whereIn('document_id', $this->documentIds)
                        ->pluck('id')
                        ->all();

                    if ($eventIds !== [] && Schema::hasTable('event_epcs')) {
                        DB::table('event_epcs')->whereIn('event_id', $eventIds)->delete();
                    }

                    if ($eventIds !== [] && Schema::hasTable('event_biz_transactions')) {
                        DB::table('event_biz_transactions')->whereIn('event_id', $eventIds)->delete();
                    }

                    if ($eventIds !== [] && Schema::hasTable('event_parties')) {
                        DB::table('event_parties')->whereIn('event_id', $eventIds)->delete();
                    }

                    DB::table('epcis_events')->whereIn('document_id', $this->documentIds)->delete();
                }

                EpcisDocument::query()->whereIn('id', $this->documentIds)->delete();
            }

            if ($this->connectionIds !== []) {
                OutboundConnection::query()->whereIn('id', $this->connectionIds)->delete();
            }

            if ($this->userIds !== []) {
                User::query()->whereIn('id', $this->userIds)->delete();
            }

            if ($this->atpLicenseIds !== []) {
                AtpLicense::query()->whereIn('id', $this->atpLicenseIds)->delete();
            }

            if ($this->siteIds !== []) {
                Site::query()->whereIn('id', $this->siteIds)->delete();
            }

            tenancy()->end();
        }

        if ($tenant !== null) {
            TenantSettings::forTenant($tenant)->setEpcisAccept20(null);
            $tenant->save();
        }

        $this->sessionIds = [];
        $this->receivingSessionIds = [];
        $this->documentIds = [];
        $this->connectionIds = [];
        $this->siteIds = [];
        $this->userIds = [];
        $this->atpLicenseIds = [];
    }
}
