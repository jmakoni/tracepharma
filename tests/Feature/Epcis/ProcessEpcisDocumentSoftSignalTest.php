<?php

namespace Tests\Feature\Epcis;

use App\Actions\Epcis\ReceiveEpcisUpload;
use App\Actions\Epcis\RecordAtpSoftWarning;
use App\Actions\Epcis\RecordSbdhOwningPartyMismatch;
use App\Actions\Epcis\ReprocessEpcisDocument;
use App\Enums\FacilityType;
use App\Enums\PartnerType;
use App\Enums\TenantProfile;
use App\Models\AtpLicense;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisException;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Services\Epcis\EpcisIngestionService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Confirms ProcessEpcisDocument's clearSoftSignalExceptions() step drops stale open
 * atp_soft_warning / sbdh_source_owning_party_mismatch / MASTER_DATA_SYNC_LAG rows on reprocess. Both signals' writers
 * (RecordAtpSoftWarning, RecordSbdhOwningPartyMismatch, recordIncompleteProductMasterDataExceptions) early-return once
 * one is already open, so without this clear step a stale row would stay open forever
 * across reprocesses instead of being refreshed.
 */
class ProcessEpcisDocumentSoftSignalTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    /** Matches minimal_with_shipping_refs SBDH sender and source owning_party SGLN. */
    private const SOURCE_OWNING_PARTY_GLN = '0301160000009';

    private const MISMATCHED_SENDER_GLN = '0096295000009';

    private static bool $demo2TenantReady = false;

    private ?int $documentId = null;

    /** @var list<int> */
    private array $tenantPartnerIds = [];

    /** @var list<int> */
    private array $tenantSiteIds = [];

    #[Test]
    public function reprocess_clears_stale_open_atp_soft_warning_and_master_data_sync_lag(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $fixture = base_path('tests/Fixtures/epcis/minimal_object_shipping.xml');
            $this->assertFileExists($fixture);

            $tmp = tempnam(sys_get_temp_dir(), 'epcis_soft_').'.xml';
            $xml = file_get_contents($fixture);
            $this->assertNotFalse($xml);
            $xml = str_replace('11111111-2222-3333-4444-555555555555', (string) str()->uuid(), $xml);
            file_put_contents($tmp, $xml);

            $document = app(ReceiveEpcisUpload::class)->handle($tmp, [
                'direction' => 'inbound',
                'original_filename' => 'soft-signal-refresh.xml',
                'dispatch' => false,
            ]);
            $this->documentId = (int) $document->getKey();

            app(EpcisIngestionService::class)->process($document);

            $staleAtp = EpcisException::query()->create([
                'document_id' => $document->id,
                'exception_type' => 'atp_soft_warning',
                'severity' => 'warning',
                'description' => 'Stale ATP soft warning from a prior ingest generation.',
                'status' => 'open',
            ]);
            $staleSbdh = EpcisException::query()->create([
                'document_id' => $document->id,
                'exception_type' => 'sbdh_source_owning_party_mismatch',
                'severity' => 'warning',
                'description' => 'Stale SBDH mismatch from a prior ingest generation.',
                'status' => 'open',
            ]);
            $staleMasterData = EpcisException::query()->create([
                'document_id' => $document->id,
                'exception_type' => 'MASTER_DATA_SYNC_LAG',
                'severity' => 'warning',
                'description' => 'Stale master data sync lag from a prior ingest generation.',
                'status' => 'open',
            ]);
            $staleInvalidEpc = EpcisException::query()->create([
                'document_id' => $document->id,
                'exception_type' => 'INVALID_EPC_URI',
                'severity' => 'warning',
                'description' => 'Stale invalid EPC URI soft signal from a prior ingest generation.',
                'status' => 'open',
            ]);

            app(ReprocessEpcisDocument::class)->handle($document->fresh(), sync: true);

            $this->assertFalse(
                EpcisException::query()->whereKey($staleAtp->getKey())->exists(),
                'Expected stale atp_soft_warning to be cleared (deleted) on reprocess',
            );
            $this->assertFalse(
                EpcisException::query()->whereKey($staleSbdh->getKey())->exists(),
                'Expected stale sbdh_source_owning_party_mismatch to be cleared (deleted) on reprocess',
            );
            $this->assertFalse(
                EpcisException::query()->whereKey($staleMasterData->getKey())->exists(),
                'Expected stale MASTER_DATA_SYNC_LAG to be cleared (deleted) on reprocess',
            );
            $this->assertFalse(
                EpcisException::query()->whereKey($staleInvalidEpc->getKey())->exists(),
                'Expected stale INVALID_EPC_URI to be cleared (deleted) on reprocess',
            );

            @unlink($tmp);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function process_creates_sbdh_source_owning_party_mismatch_when_sender_gln_differs(): void
    {
        $this->initializeDemo2Tenant();

        try {
            [$tmp] = $this->uniqueShippingRefsFixture(
                senderGlnReplacement: [self::SOURCE_OWNING_PARTY_GLN, self::MISMATCHED_SENDER_GLN],
            );

            $document = app(ReceiveEpcisUpload::class)->handle($tmp, [
                'direction' => 'inbound',
                'original_filename' => 'sbdh-mismatch-soft-signal.xml',
                'dispatch' => false,
            ]);
            $this->documentId = (int) $document->getKey();

            app(EpcisIngestionService::class)->process($document->fresh());
            $document->refresh();

            $this->assertSame(self::MISMATCHED_SENDER_GLN, $document->sender_gln);

            $exception = EpcisException::query()
                ->where('document_id', $document->id)
                ->where('exception_type', RecordSbdhOwningPartyMismatch::EXCEPTION_TYPE)
                ->where('status', 'open')
                ->first();

            $this->assertNotNull($exception);
            $this->assertSame('warning', $exception->severity);
            $this->assertStringContainsString(self::MISMATCHED_SENDER_GLN, $exception->description);
            $this->assertStringContainsString(self::SOURCE_OWNING_PARTY_GLN, $exception->description);
            $this->assertStringContainsString('does not match shipping event source owning_party GLN', $exception->description);

            @unlink($tmp);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function process_does_not_create_sbdh_mismatch_when_sender_matches_source_owning_party(): void
    {
        $this->initializeDemo2Tenant();

        try {
            [$tmp] = $this->uniqueShippingRefsFixture();

            $document = app(ReceiveEpcisUpload::class)->handle($tmp, [
                'direction' => 'inbound',
                'original_filename' => 'sbdh-match-soft-signal.xml',
                'dispatch' => false,
            ]);
            $this->documentId = (int) $document->getKey();

            app(EpcisIngestionService::class)->process($document->fresh());
            $document->refresh();

            $this->assertSame(self::SOURCE_OWNING_PARTY_GLN, $document->sender_gln);

            $this->assertFalse(
                EpcisException::query()
                    ->where('document_id', $document->id)
                    ->where('exception_type', RecordSbdhOwningPartyMismatch::EXCEPTION_TYPE)
                    ->exists(),
                'Expected no sbdh_source_owning_party_mismatch when sender matches source owning_party',
            );

            @unlink($tmp);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function record_atp_soft_warning_creates_sold_to_warning_when_ship_to_partner_lacks_valid_atp(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $this->setTenantReceivingState($tenant, 'AK');
        config(['tracepharma.epcis.enforce_atp_soft_gate' => true]);

        try {
            $sellerPartner = $this->createPartnerWithSiteLicenses([
                ['license_state' => 'AK', 'license_expiration_date' => now()->addYear()],
            ]);
            $shipToPartner = $this->createPartnerWithSiteLicenses([
                ['license_state' => 'AK', 'license_expiration_date' => now()->subDay()],
            ]);

            $document = EpcisDocument::query()->create([
                'document_uuid' => (string) str()->uuid(),
                'schema_version' => '1.2',
                'creation_date' => now(),
                'direction' => 'inbound',
                'status' => 'parsed',
                'trading_partner_id' => $sellerPartner->id,
                'ship_to_partner_id' => $shipToPartner->id,
                'sender_gln' => $sellerPartner->gln,
                'received_at' => now(),
                'processed_at' => now(),
            ]);
            $this->documentId = (int) $document->getKey();

            $exception = app(RecordAtpSoftWarning::class)->handle($document->fresh());

            $this->assertNotNull($exception);
            $this->assertSame('atp_soft_warning', $exception->exception_type);
            $this->assertSame('warning', $exception->severity);
            $this->assertSame('open', $exception->status);
            $this->assertStringContainsString('sold-to', strtolower($exception->description));
            $this->assertStringContainsString('destination', strtolower($exception->description));
            $this->assertStringNotContainsString('seller', strtolower($exception->description));

            $this->assertTrue(
                EpcisException::query()
                    ->where('document_id', $document->id)
                    ->where('exception_type', 'atp_soft_warning')
                    ->where('status', 'open')
                    ->exists(),
            );
        } finally {
            config(['tracepharma.epcis.enforce_atp_soft_gate' => true]);
            $this->cleanup();
        }
    }

    #[Test]
    public function record_atp_soft_warning_creates_seller_warning_when_trading_partner_lacks_valid_atp(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $this->setTenantReceivingState($tenant, 'AK');
        config(['tracepharma.epcis.enforce_atp_soft_gate' => true]);

        try {
            $sellerPartner = $this->createPartnerWithSiteLicenses([
                ['license_state' => 'AK', 'license_expiration_date' => now()->subDay()],
            ]);

            $document = EpcisDocument::query()->create([
                'document_uuid' => (string) str()->uuid(),
                'schema_version' => '1.2',
                'creation_date' => now(),
                'direction' => 'inbound',
                'status' => 'parsed',
                'trading_partner_id' => $sellerPartner->id,
                'ship_to_partner_id' => null,
                'sender_gln' => $sellerPartner->gln,
                'received_at' => now(),
                'processed_at' => now(),
            ]);
            $this->documentId = (int) $document->getKey();

            $exception = app(RecordAtpSoftWarning::class)->handle($document->fresh());

            $this->assertNotNull($exception);
            $this->assertSame('atp_soft_warning', $exception->exception_type);
            $this->assertSame('warning', $exception->severity);
            $this->assertStringContainsString('seller', strtolower($exception->description));
            $this->assertStringContainsString('source owning party', strtolower($exception->description));
        } finally {
            config(['tracepharma.epcis.enforce_atp_soft_gate' => true]);
            $this->cleanup();
        }
    }

    #[Test]
    public function record_atp_soft_warning_judges_the_facility_the_document_names(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $this->setTenantReceivingState($tenant, 'AK');
        config(['tracepharma.epcis.enforce_atp_soft_gate' => true]);

        try {
            $shipToPartner = $this->createPartnerWithSiteLicenses([]);
            $unlicensedHq = $this->partnerSites($shipToPartner)->first();
            $this->assertNotNull($unlicensedHq);

            $namedDock = $this->addSiteWithLicenses($shipToPartner, 'Named Dock', [
                ['license_state' => 'AK', 'license_expiration_date' => now()->addYear()],
            ]);

            $document = $this->shipmentDocument($shipToPartner, shipToSiteId: (int) $namedDock->id);

            // The dock the document addresses holds a valid licence. HQ's gap is not this
            // shipment's problem, and warning about it would train operators to ignore the signal.
            $this->assertNull(app(RecordAtpSoftWarning::class)->handle($document->fresh()));

            EpcisException::query()->where('document_id', $document->id)->delete();

            // Addressed to the dock without a licence, the same partner does warn.
            $document->forceFill(['ship_to_site_id' => (int) $unlicensedHq->id])->save();

            $warning = app(RecordAtpSoftWarning::class)->handle($document->fresh());

            $this->assertNotNull($warning);
            $this->assertStringContainsString('sold-to', strtolower($warning->description));
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function record_atp_soft_warning_clears_a_party_when_any_unnamed_destination_is_ready(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $this->setTenantReceivingState($tenant, 'AK');
        config(['tracepharma.epcis.enforce_atp_soft_gate' => true]);

        try {
            $shipToPartner = $this->createPartnerWithSiteLicenses([]);
            $this->addSiteWithLicenses($shipToPartner, 'Licensed Dock', [
                ['license_state' => 'AK', 'license_expiration_date' => now()->addYear()],
            ]);

            $document = $this->shipmentDocument($shipToPartner);

            // The document names no facility, so the whole party stands in and one licensed
            // address is enough — the same rule the outbound send gate applies to a shipment
            // with no named destination.
            $this->assertNull(app(RecordAtpSoftWarning::class)->handle($document->fresh()));
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function record_atp_soft_warning_faults_a_party_whose_every_site_is_unready(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $this->setTenantReceivingState($tenant, 'AK');
        config(['tracepharma.epcis.enforce_atp_soft_gate' => true]);

        try {
            $shipToPartner = $this->createPartnerWithSiteLicenses([
                ['license_state' => 'AK', 'license_expiration_date' => now()->subDay()],
            ]);
            $this->addSiteWithLicenses($shipToPartner, 'Unlicensed Dock', []);

            $document = $this->shipmentDocument($shipToPartner);

            $warning = app(RecordAtpSoftWarning::class)->handle($document->fresh());

            $this->assertNotNull($warning);
            $this->assertStringContainsString('sold-to', strtolower($warning->description));
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function record_atp_soft_warning_ignores_inactive_partner_sites(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $this->setTenantReceivingState($tenant, 'AK');
        config(['tracepharma.epcis.enforce_atp_soft_gate' => true]);

        try {
            $shipToPartner = $this->createPartnerWithSiteLicenses([
                ['license_state' => 'AK', 'license_expiration_date' => now()->addYear()],
            ]);

            $retired = $this->addSiteWithLicenses($shipToPartner, 'Retired Dock', []);
            $retired->forceFill(['is_active' => false])->save();

            $document = $this->shipmentDocument($shipToPartner);

            // A closed address authorizes nothing and needs nothing; it must not drag the
            // partner's readiness down.
            $this->assertNull(app(RecordAtpSoftWarning::class)->handle($document->fresh()));
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function record_atp_soft_warning_surfaces_an_unset_receiving_state(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $this->setTenantReceivingState($tenant, null);
        config(['tracepharma.epcis.enforce_atp_soft_gate' => true]);

        try {
            $shipToPartner = $this->createPartnerWithSiteLicenses([
                ['license_state' => 'AK', 'license_expiration_date' => now()->addYear()],
            ]);

            $document = $this->shipmentDocument($shipToPartner);

            // Without a receiving state every partner reads as "set receiving state", which is
            // not evidence of a licence — say so rather than passing the document silently.
            $warning = app(RecordAtpSoftWarning::class)->handle($document->fresh());

            $this->assertNotNull($warning);
            $this->assertSame('atp_soft_warning', $warning->exception_type);
            $this->assertSame('warning', $warning->severity);
            $this->assertStringContainsString('receiving state is not set', $warning->description);
        } finally {
            $this->setTenantReceivingState($tenant, 'IL');
            $this->cleanup();
        }
    }

    private function shipmentDocument(TradingPartner $shipToPartner, ?int $shipToSiteId = null): EpcisDocument
    {
        $document = EpcisDocument::query()->create([
            'document_uuid' => (string) str()->uuid(),
            'schema_version' => '1.2',
            'creation_date' => now(),
            'direction' => 'inbound',
            'status' => 'parsed',
            'trading_partner_id' => null,
            'ship_to_partner_id' => $shipToPartner->id,
            'ship_to_site_id' => $shipToSiteId,
            'received_at' => now(),
            'processed_at' => now(),
        ]);
        $this->documentId = (int) $document->getKey();

        return $document;
    }

    /**
     * @return Collection<int, Site>
     */
    private function partnerSites(TradingPartner $partner): Collection
    {
        return Site::query()->where('trading_partner_id', $partner->id)->orderBy('id')->get();
    }

    /**
     * @param  list<array{license_state: string, license_expiration_date: Carbon}>  $licenses
     */
    private function addSiteWithLicenses(TradingPartner $partner, string $name, array $licenses): Site
    {
        $site = Site::query()->create([
            'trading_partner_id' => $partner->id,
            'is_headquarters' => false,
            'name' => $name,
            'country_code' => 'US',
            'is_active' => true,
        ]);
        $this->tenantSiteIds[] = $site->id;

        foreach ($licenses as $license) {
            AtpLicense::query()->create([
                'site_id' => $site->id,
                'facility_type' => FacilityType::Wdd,
                'license_number' => 'LIC-'.uniqid(),
                'license_state' => $license['license_state'],
                'license_expiration_date' => $license['license_expiration_date'],
                'reporting_year' => (int) now()->year,
            ]);
        }

        return $site;
    }

    /**
     * @param  array{0: string, 1: string}|null  $senderGlnReplacement  [search, replace]
     * @return array{0: string}
     */
    private function uniqueShippingRefsFixture(?array $senderGlnReplacement = null): array
    {
        $fixture = base_path('tests/Fixtures/epcis/minimal_with_shipping_refs.xml');
        $this->assertFileExists($fixture);

        $tmp = tempnam(sys_get_temp_dir(), 'epcis_soft_ship_');
        $this->assertNotFalse($tmp);
        $xmlPath = $tmp.'.xml';
        rename($tmp, $xmlPath);

        $xml = file_get_contents($fixture);
        $this->assertNotFalse($xml);
        $xml = str_replace('22222222-3333-4444-5555-666666666666', (string) str()->uuid(), $xml);

        if ($senderGlnReplacement !== null) {
            $xml = str_replace($senderGlnReplacement[0], $senderGlnReplacement[1], $xml);
        }

        file_put_contents($xmlPath, $xml);

        return [$xmlPath];
    }

    /**
     * @param  list<array{license_state: string, license_expiration_date: Carbon}>  $licenses
     */
    private function createPartnerWithSiteLicenses(array $licenses): TradingPartner
    {
        $partner = TradingPartner::query()->create([
            'name' => 'Soft Signal ATP '.uniqid(),
            'gln' => fake()->unique()->numerify('#############'),
            'partner_type' => PartnerType::Wholesaler,
            'country_code' => 'US',
            'is_active' => true,
        ]);
        $this->tenantPartnerIds[] = $partner->id;

        $site = Site::query()->create([
            'trading_partner_id' => $partner->id,
            'is_headquarters' => true,
            'name' => 'HQ',
            'country_code' => 'US',
            'is_active' => true,
        ]);
        $this->tenantSiteIds[] = $site->id;

        foreach ($licenses as $index => $license) {
            AtpLicense::query()->create([
                'site_id' => $site->id,
                'facility_type' => FacilityType::Wdd,
                'license_number' => 'LIC-'.uniqid(),
                'license_state' => $license['license_state'],
                'license_expiration_date' => $license['license_expiration_date'],
                'reporting_year' => (int) now()->year,
            ]);
        }

        return $partner;
    }

    private function setTenantReceivingState(Tenant $tenant, ?string $state): void
    {
        $tenant->receiving_state = $state;
        $tenant->save();

        tenancy()->end();
        tenancy()->initialize($tenant->fresh());
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
        if (! tenancy()->initialized) {
            return;
        }

        if ($this->documentId !== null) {
            EpcisException::query()->where('document_id', $this->documentId)->delete();
            EpcisDocument::query()->whereKey($this->documentId)->delete();
            $this->documentId = null;
        }

        if ($this->tenantSiteIds !== []) {
            AtpLicense::query()->whereIn('site_id', $this->tenantSiteIds)->delete();
            Site::query()->whereIn('id', $this->tenantSiteIds)->delete();
            $this->tenantSiteIds = [];
        }

        if ($this->tenantPartnerIds !== []) {
            TradingPartner::query()->whereIn('id', $this->tenantPartnerIds)->delete();
            $this->tenantPartnerIds = [];
        }

        $epc = Epc::query()->where('epc_uri', 'urn:epc:id:sgtin:030116.0200116.10000082001560')->first();
        if ($epc !== null && ! DB::table('event_epcs')->where('epc_id', $epc->id)->exists()) {
            $epc->delete();
        }

        $sscc = Epc::query()->where('epc_uri', 'urn:epc:id:sscc:030116.01001227052')->first();
        if ($sscc !== null && ! DB::table('event_epcs')->where('epc_id', $sscc->id)->exists()) {
            $sscc->delete();
        }

        tenancy()->end();
    }
}
