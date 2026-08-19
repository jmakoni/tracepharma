<?php

namespace Tests\Feature\Epcis;

use App\Actions\Epcis\AuthorizeMissingDocumentProducts;
use App\Enums\ExceptionSeverity;
use App\Enums\ExceptionStatus;
use App\Enums\PartnerType;
use App\Enums\TenantProfile;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Exceptions\ExceptionType;
use App\Models\Fda\FdaOrganization;
use App\Models\Fda\FdaProduct;
use App\Models\Fda\FdaProductPackaging;
use App\Models\Product;
use App\Models\Receiving\ReceivingSession;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Models\User;
use App\Services\Receiving\ReceivingGate;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\TenantSettings;
use Database\Seeders\ExceptionCaseSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AuthorizeMissingDocumentProductsTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $documentIds = [];

    /** @var list<int> */
    private array $epcIds = [];

    /** @var list<int> */
    private array $productIds = [];

    /** @var list<int> */
    private array $caseIds = [];

    /** @var list<int> */
    private array $sessionIds = [];

    /** @var list<int> */
    private array $tenantPartnerIds = [];

    /** @var list<int> */
    private array $orgIds = [];

    /** @var list<int> */
    private array $fdaProductIds = [];

    /** @var list<int> */
    private array $packagingIds = [];

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    #[Test]
    public function authorizes_fda_packaging_hits_and_reports_misses_for_multi_gtin_document(): void
    {
        $gtinHitA = $this->uniqueGtin();
        $gtinHitB = $this->uniqueGtin();
        $gtinMiss = $this->uniqueGtin();

        $this->createRxPackaging($gtinHitA);
        $this->createRxPackaging($gtinHitB);

        $this->initializeDemo2Tenant();

        $wholesaler = $this->makeWholesalerPartner();
        $document = $this->makeDocumentWithGtins([$gtinHitA, $gtinHitB, $gtinMiss]);

        $unknown = AuthorizeMissingDocumentProducts::unknownGtinsForDocument($document);
        $this->assertEqualsCanonicalizing([$gtinHitA, $gtinHitB, $gtinMiss], $unknown);

        $reprocessBefore = (int) $document->reprocess_count;
        $actor = User::factory()->create();

        $result = app(AuthorizeMissingDocumentProducts::class)->handle(
            $document,
            $wholesaler,
            $actor,
            alsoResolve: false,
            alsoReprocess: true,
        );

        $document->refresh();

        $this->assertSame(3, count($result['unknown_gtins']));
        $this->assertSame(2, $result['catalog_hits']);
        $this->assertSame([$gtinMiss], $result['catalog_misses']);
        $this->assertSame(2, $result['added'] + $result['attached']);
        $this->assertEqualsCanonicalizing([$gtinHitA, $gtinHitB], $result['authorized_gtins']);
        $this->assertTrue($result['reprocessed']);
        $this->assertSame($reprocessBefore + 1, (int) $document->reprocess_count);

        $this->assertTrue(Product::query()->where('gtin', $gtinHitA)->exists());
        $this->assertTrue(Product::query()->where('gtin', $gtinHitB)->exists());
        $this->assertFalse(Product::query()->where('gtin', $gtinMiss)->exists());
    }

    #[Test]
    public function reprocess_is_skipped_when_receiving_session_is_active(): void
    {
        $gtin = $this->uniqueGtin();
        $this->createRxPackaging($gtin);

        $this->initializeDemo2Tenant();

        $wholesaler = $this->makeWholesalerPartner();
        $document = $this->makeDocumentWithGtins([$gtin]);

        ReceivingSession::query()->create([
            'epcis_document_id' => $document->getKey(),
            'status' => 'open',
            'expected_parent_count' => 0,
            'confirmed_parent_count' => 0,
            'expected_child_count' => 0,
            'confirmed_child_count' => 0,
            'opened_at' => now(),
        ]);
        $this->sessionIds[] = (int) ReceivingSession::query()->where('epcis_document_id', $document->getKey())->value('id');

        $reprocessBefore = (int) $document->reprocess_count;

        $result = app(AuthorizeMissingDocumentProducts::class)->handle(
            $document,
            $wholesaler,
            User::factory()->create(),
            alsoResolve: false,
            alsoReprocess: true,
        );

        $this->assertSame([$gtin], $result['authorized_gtins']);
        $this->assertFalse($result['reprocessed']);
        $this->assertSame($reprocessBefore, (int) $document->fresh()->reprocess_count);
    }

    #[Test]
    public function also_resolve_closes_unknown_gtin_cases_and_unblocks_receiving_gate(): void
    {
        $gtin = $this->uniqueGtin();
        $this->createRxPackaging($gtin);

        $this->initializeDemo2Tenant();

        $wholesaler = $this->makeWholesalerPartner();
        $document = $this->makeDocumentWithGtins([$gtin]);
        $type = ExceptionType::query()->where('code', 'UNKNOWN_GTIN')->firstOrFail();

        $case = ExceptionCase::query()->create([
            'exception_type_id' => $type->getKey(),
            'document_id' => $document->getKey(),
            'title' => 'Unknown GTIN encountered',
            'description' => "GTIN not found in product master: {$gtin}",
            'severity' => ExceptionSeverity::High,
            'status' => ExceptionStatus::New,
        ]);
        $this->caseIds[] = (int) $case->getKey();

        $gate = app(ReceivingGate::class);
        $this->assertNotNull($gate->documentBlockedByOpenException($document));

        $result = app(AuthorizeMissingDocumentProducts::class)->handle(
            $document,
            $wholesaler,
            User::factory()->create(),
            alsoResolve: true,
            alsoReprocess: false,
            resolutionNotes: 'Bulk authorized from Products tab test.',
        );

        $this->assertSame(1, $result['resolved_cases']);
        $this->assertSame(ExceptionStatus::Resolved, $case->fresh()->status);
        $this->assertNull($gate->documentBlockedByOpenException($document->fresh()));
    }

    #[Test]
    public function unknown_gtins_ignore_products_without_matching_gtin_even_when_ndc_product_exists(): void
    {
        $gtin = '00301162001165';
        $ndc11 = '00116200116';

        $this->initializeDemo2Tenant();

        Product::query()->where('ndc11', $ndc11)->each(function (Product $existing): void {
            $existing->tradingPartners()->detach();
            $existing->delete();
        });

        $product = Product::factory()->create([
            'name' => 'NDC-only product',
            'gtin' => null,
            'ndc11' => $ndc11,
            'package_ndc' => '0116-2001-16',
            'ndc' => '0116-2001-16',
        ]);
        $this->productIds[] = (int) $product->id;

        $document = $this->makeDocumentWithGtins([$gtin]);

        $unknown = AuthorizeMissingDocumentProducts::unknownGtinsForDocument($document);

        $this->assertSame([$gtin], $unknown);
    }

    private function uniqueGtin(): string
    {
        return '030116'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
    }

    private function createRxPackaging(string $gtin): FdaProductPackaging
    {
        $suffix = uniqid();
        $org = FdaOrganization::query()->create([
            'original_name' => 'SSOR CUT2 Bulk Auth Labeler '.$suffix,
            'canonical_name' => 'SSOR CUT2 BULK AUTH LABELER '.$suffix,
            'name' => 'SSOR CUT2 Bulk Auth Labeler '.$suffix,
            'partner_type' => PartnerType::Manufacturer,
            'is_active' => true,
        ]);
        $this->orgIds[] = (int) $org->getKey();

        $fda = FdaProduct::query()->create([
            'product_id' => 'SSOR-CUT2-BULK-AUTH-'.uniqid(),
            'product_ndc' => fake()->unique()->numerify('#####-###'),
            'brand_name' => 'SSOR CUT2 Bulk Auth Rx',
            'product_type' => FdaProduct::PRODUCT_TYPE_HUMAN_PRESCRIPTION,
            'fda_organization_id' => $org->id,
            'finished' => true,
            'is_active' => true,
        ]);
        $this->fdaProductIds[] = (int) $fda->getKey();

        $packaging = FdaProductPackaging::query()->create([
            'fda_product_id' => $fda->id,
            'package_ndc' => fake()->unique()->numerify('#####-###-##'),
            'gtin' => $gtin,
            'is_active' => true,
        ]);
        $this->packagingIds[] = (int) $packaging->getKey();

        return $packaging;
    }

    private function makeWholesalerPartner(): TradingPartner
    {
        $partner = TradingPartner::query()->create([
            'name' => 'SSOR CUT2 Bulk Auth Wholesaler '.uniqid(),
            'gln' => fake()->unique()->numerify('#############'),
            'partner_type' => PartnerType::Wholesaler,
            'country_code' => 'US',
            'is_active' => true,
        ]);
        $this->tenantPartnerIds[] = (int) $partner->getKey();

        return $partner;
    }

    /**
     * @param  list<string>  $gtins
     */
    private function makeDocumentWithGtins(array $gtins): EpcisDocument
    {
        $path = 'epcis/inbound/bulk-auth-'.(string) str()->uuid().'.xml';
        Storage::disk('local')->put($path, '<epcis/>');

        $document = EpcisDocument::query()->create([
            'document_uuid' => (string) str()->uuid(),
            'schema_version' => '1.2',
            'creation_date' => now(),
            'direction' => 'inbound',
            'format' => 'xml',
            'original_filename' => 'bulk-auth.xml',
            'file_sha256' => hash('sha256', (string) str()->uuid()),
            'payload_disk' => 'local',
            'payload_path' => $path,
            'dscsa_affirm' => false,
            'status' => 'validated',
            'event_count' => 0,
            'epc_count' => count($gtins),
            'received_at' => now(),
            'ingest_generation' => 1,
            'reprocess_count' => 0,
        ]);
        $this->documentIds[] = (int) $document->id;

        $rows = [];
        foreach ($gtins as $index => $gtin) {
            $epc = Epc::query()->create([
                'epc_uri' => 'urn:epc:id:sgtin:030116.'.substr($gtin, -8).'.s'.$index,
                'epc_type' => 'sgtin',
                'company_prefix' => '030116',
                'gtin14' => $gtin,
                'serial_number' => 'serial-'.$index,
                'product_id' => null,
                'first_seen_at' => now(),
            ]);
            $this->epcIds[] = (int) $epc->id;

            if (Schema::hasTable('document_epcs')) {
                $rows[] = [
                    'document_id' => $document->id,
                    'epc_id' => $epc->id,
                    'ingest_generation' => 1,
                ];
            }
        }

        if ($rows !== []) {
            DB::table('document_epcs')->insert($rows);
        }

        return $document;
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
        TenantSettings::forTenant($tenant)->setJobRolesEnabled(false);
        $tenant->save();
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
        $this->seed(ExceptionCaseSeeder::class);

        return $tenant;
    }

    private function cleanup(): void
    {
        if (tenancy()->initialized) {
            foreach ($this->caseIds as $id) {
                $case = ExceptionCase::query()->find($id);
                if ($case === null) {
                    continue;
                }
                $case->activities()->delete();
                $case->epcs()->detach();
                $case->delete();
            }

            if ($this->sessionIds !== []) {
                ReceivingSession::query()->whereIn('id', $this->sessionIds)->delete();
                $this->sessionIds = [];
            }

            if ($this->documentIds !== [] && Schema::hasTable('document_epcs')) {
                DB::table('document_epcs')->whereIn('document_id', $this->documentIds)->delete();
            }

            if ($this->epcIds !== []) {
                Epc::query()->whereIn('id', $this->epcIds)->delete();
                $this->epcIds = [];
            }

            if ($this->documentIds !== []) {
                EpcisDocument::query()->whereIn('id', $this->documentIds)->delete();
                $this->documentIds = [];
            }

            if ($this->productIds !== []) {
                foreach ($this->productIds as $id) {
                    $product = Product::query()->find($id);
                    if ($product === null) {
                        continue;
                    }
                    $product->tradingPartners()->detach();
                    $product->delete();
                }
                $this->productIds = [];
            }

            if ($this->tenantPartnerIds !== []) {
                TradingPartner::query()->whereIn('id', $this->tenantPartnerIds)->delete();
                $this->tenantPartnerIds = [];
            }

            tenancy()->end();
        }

        if ($this->packagingIds !== []) {
            FdaProductPackaging::query()->whereIn('id', $this->packagingIds)->delete();
            $this->packagingIds = [];
        }

        if ($this->fdaProductIds !== []) {
            FdaProduct::query()->whereIn('id', $this->fdaProductIds)->delete();
            $this->fdaProductIds = [];
        }

        if ($this->orgIds !== []) {
            FdaOrganization::query()->whereIn('id', $this->orgIds)->delete();
            $this->orgIds = [];
        }
    }
}
