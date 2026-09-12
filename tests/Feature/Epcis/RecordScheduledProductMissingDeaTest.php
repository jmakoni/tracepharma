<?php

namespace Tests\Feature\Epcis;

use App\Actions\Epcis\RecordScheduledProductMissingDea;
use App\Actions\Receiving\OpenReceivingSessionFromDocument;
use App\Enums\TenantProfile;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisException;
use App\Models\Fda\FdaProduct;
use App\Models\Product;
use App\Models\Receiving\ReceivingSession;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TradingPartner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RecordScheduledProductMissingDeaTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private const CII_GTIN = '88884000001001';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $fdaProductIds = [];

    /** @var list<int> */
    private array $tenantProductIds = [];

    /** @var list<int> */
    private array $partnerIds = [];

    /** @var list<int> */
    private array $siteIds = [];

    /** @var list<int> */
    private array $documentIds = [];

    /** @var list<int> */
    private array $epcIds = [];

    /** @var list<int> */
    private array $sessionIds = [];

    protected function tearDown(): void
    {
        $this->cleanup();

        parent::tearDown();
    }

    #[Test]
    public function inbound_cii_without_seller_dea_opens_warning_and_receive_still_opens(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->seedScheduledCiiProduct();
            $partner = TradingPartner::factory()->create([
                'dea_number' => null,
            ]);
            $this->partnerIds[] = (int) $partner->getKey();

            $document = $this->makeInboundDocumentWithScheduledEpc($partner);

            $created = app(RecordScheduledProductMissingDea::class)->handle($document);

            $this->assertCount(1, $created);
            $this->assertTrue(
                EpcisException::query()
                    ->where('document_id', $document->getKey())
                    ->where('exception_type', RecordScheduledProductMissingDea::EXCEPTION_TYPE)
                    ->where('status', 'open')
                    ->exists(),
            );

            $session = app(OpenReceivingSessionFromDocument::class)->handle($document->fresh());
            $this->sessionIds[] = (int) $session->getKey();

            $this->assertSame('open', $session->status);
            $this->assertSame((int) $document->getKey(), (int) $session->epcis_document_id);
        } finally {
            $this->cleanupTenant();
        }
    }

    #[Test]
    public function inbound_cii_with_seller_dea_does_not_signal(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->seedScheduledCiiProduct();
            $partner = TradingPartner::factory()->create([
                'dea_number' => 'AB1234567',
            ]);
            $this->partnerIds[] = (int) $partner->getKey();

            $document = $this->makeInboundDocumentWithScheduledEpc($partner);

            $created = app(RecordScheduledProductMissingDea::class)->handle($document);

            $this->assertSame([], $created);
            $this->assertFalse(
                EpcisException::query()
                    ->where('document_id', $document->getKey())
                    ->where('exception_type', RecordScheduledProductMissingDea::EXCEPTION_TYPE)
                    ->exists(),
            );
        } finally {
            $this->cleanupTenant();
        }
    }

    #[Test]
    public function inbound_cii_with_seller_site_dea_does_not_signal(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->seedScheduledCiiProduct();
            $partner = TradingPartner::factory()->create([
                'dea_number' => null,
            ]);
            $this->partnerIds[] = (int) $partner->getKey();

            $site = Site::factory()->create([
                'trading_partner_id' => $partner->getKey(),
                'dea_number' => 'XY9876543',
            ]);
            $this->siteIds[] = (int) $site->getKey();

            $document = $this->makeInboundDocumentWithScheduledEpc($partner);

            $created = app(RecordScheduledProductMissingDea::class)->handle($document);

            $this->assertSame([], $created);
            $this->assertFalse(
                EpcisException::query()
                    ->where('document_id', $document->getKey())
                    ->where('exception_type', RecordScheduledProductMissingDea::EXCEPTION_TYPE)
                    ->exists(),
            );
        } finally {
            $this->cleanupTenant();
        }
    }

    #[Test]
    public function handle_clears_stale_open_signal_when_seller_dea_becomes_present(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->seedScheduledCiiProduct();
            $partner = TradingPartner::factory()->create([
                'dea_number' => null,
            ]);
            $this->partnerIds[] = (int) $partner->getKey();

            $document = $this->makeInboundDocumentWithScheduledEpc($partner);

            app(RecordScheduledProductMissingDea::class)->handle($document);
            $this->assertSame(
                1,
                EpcisException::query()
                    ->where('document_id', $document->getKey())
                    ->where('exception_type', RecordScheduledProductMissingDea::EXCEPTION_TYPE)
                    ->where('status', 'open')
                    ->count(),
            );

            $partner->forceFill(['dea_number' => 'AB1234567'])->save();

            $created = app(RecordScheduledProductMissingDea::class)->handle($document->fresh());
            $this->assertSame([], $created);
            $this->assertSame(
                0,
                EpcisException::query()
                    ->where('document_id', $document->getKey())
                    ->where('exception_type', RecordScheduledProductMissingDea::EXCEPTION_TYPE)
                    ->where('status', 'open')
                    ->count(),
            );
        } finally {
            $this->cleanupTenant();
        }
    }

    #[Test]
    public function outbound_ingest_skips_signal(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->seedScheduledCiiProduct();
            $partner = TradingPartner::factory()->create([
                'dea_number' => null,
            ]);
            $this->partnerIds[] = (int) $partner->getKey();

            $document = $this->makeOutboundDocumentWithScheduledEpc($partner);

            $created = app(RecordScheduledProductMissingDea::class)->handle($document);

            $this->assertSame([], $created);
            $this->assertFalse(
                EpcisException::query()
                    ->where('document_id', $document->getKey())
                    ->where('exception_type', RecordScheduledProductMissingDea::EXCEPTION_TYPE)
                    ->exists(),
            );
        } finally {
            $this->cleanupTenant();
        }
    }

    private function seedScheduledCiiProduct(): void
    {
        $listing = FdaProduct::query()->create([
            'product_id' => 'SSOR-SCHED-CII-DEA',
            'product_ndc' => '88884-503',
            'name' => 'SSOR Scheduled CII DEA Test',
            'dea_schedule' => '2',
            'is_active' => true,
        ]);
        $this->fdaProductIds[] = (int) $listing->id;

        $product = Product::query()->create([
            'name' => 'SSOR Scheduled CII DEA SKU',
            'gtin' => self::CII_GTIN,
            'fda_product_id' => $listing->id,
            'is_active' => true,
        ]);
        $this->tenantProductIds[] = (int) $product->id;
    }

    private function makeInboundDocumentWithScheduledEpc(TradingPartner $partner): EpcisDocument
    {
        $document = EpcisDocument::query()->create([
            'document_uuid' => (string) str()->uuid(),
            'direction' => 'inbound',
            'status' => 'validated',
            'format' => 'xml',
            'original_filename' => 'scheduled-dea-missing-test.xml',
            'dscsa_affirm' => true,
            'trading_partner_id' => $partner->getKey(),
            'creation_date' => now(),
            'received_at' => now(),
            'ingest_generation' => 1,
        ]);
        $this->documentIds[] = (int) $document->getKey();

        $this->attachScheduledSgtinEpc($document);

        return $document->fresh();
    }

    private function makeOutboundDocumentWithScheduledEpc(TradingPartner $partner): EpcisDocument
    {
        $document = EpcisDocument::query()->create([
            'document_uuid' => (string) str()->uuid(),
            'direction' => 'outbound',
            'status' => 'validated',
            'format' => 'xml',
            'original_filename' => 'scheduled-dea-outbound-skip.xml',
            'dscsa_affirm' => true,
            'trading_partner_id' => $partner->getKey(),
            'creation_date' => now(),
            'received_at' => now(),
            'ingest_generation' => 1,
        ]);
        $this->documentIds[] = (int) $document->getKey();

        $this->attachScheduledSgtinEpc($document);

        return $document->fresh();
    }

    private function attachScheduledSgtinEpc(EpcisDocument $document): void
    {
        $serial = str_replace('-', '', (string) str()->uuid());

        $epc = Epc::query()->create([
            'epc_uri' => 'urn:epc:id:sgtin:888840.0000100.'.$serial,
            'epc_type' => 'sgtin',
            'company_prefix' => '888840',
            'indicator_digit' => 0,
            'gtin14' => self::CII_GTIN,
            'serial_number' => $serial,
            'product_id' => null,
            'first_seen_at' => now(),
        ]);
        $this->epcIds[] = (int) $epc->getKey();

        if (Schema::hasTable('document_epcs')) {
            DB::table('document_epcs')->insert([
                'document_id' => $document->getKey(),
                'epc_id' => $epc->getKey(),
                'ingest_generation' => (int) ($document->ingest_generation ?? 1),
            ]);
        }
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

    private function cleanupTenant(): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        foreach ($this->sessionIds as $sessionId) {
            ReceivingSession::query()->whereKey($sessionId)->delete();
        }
        $this->sessionIds = [];

        foreach ($this->documentIds as $documentId) {
            EpcisException::query()->where('document_id', $documentId)->delete();
            if (Schema::hasTable('document_epcs')) {
                DB::table('document_epcs')->where('document_id', $documentId)->delete();
            }
            EpcisDocument::query()->whereKey($documentId)->delete();
        }
        $this->documentIds = [];

        foreach ($this->epcIds as $epcId) {
            Epc::query()->whereKey($epcId)->delete();
        }
        $this->epcIds = [];

        foreach ($this->siteIds as $siteId) {
            Site::query()->whereKey($siteId)->delete();
        }
        $this->siteIds = [];

        foreach ($this->partnerIds as $partnerId) {
            TradingPartner::query()->whereKey($partnerId)->delete();
        }
        $this->partnerIds = [];

        if ($this->tenantProductIds !== []) {
            Product::query()->whereIn('id', $this->tenantProductIds)->delete();
        }
        $this->tenantProductIds = [];

        tenancy()->end();
    }

    private function cleanup(): void
    {
        $this->cleanupTenant();

        if ($this->fdaProductIds !== []) {
            FdaProduct::query()->whereIn('id', $this->fdaProductIds)->delete();
        }
        $this->fdaProductIds = [];
    }
}
