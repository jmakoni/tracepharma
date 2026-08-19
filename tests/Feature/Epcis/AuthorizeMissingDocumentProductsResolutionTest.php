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
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Models\User;
use App\Support\Gs1\Gtin;
use App\Support\Gs1\Ndc;
use Database\Seeders\ExceptionCaseSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Bulk authorization may only clear an UNKNOWN_GTIN case for a GTIN that actually
 * reached product master — a case GTIN that resolved to the unit behind it, or a
 * packaging miss, leaves the exception true.
 */
class AuthorizeMissingDocumentProductsResolutionTest extends TestCase
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
    public function a_scanned_case_gtin_is_authorized_as_its_own_product(): void
    {
        $pair = $this->uniquePackagingGtinPair();
        $this->createRxPackaging($pair['package_ndc'], $pair['unit_gtin'], $pair['ndc11']);

        $this->initializeDemo2Tenant();

        $wholesaler = $this->makeWholesaler();
        $document = $this->makeDocumentWithGtins([$pair['case_gtin']]);

        $result = app(AuthorizeMissingDocumentProducts::class)->handle(
            $document,
            $wholesaler,
            User::factory()->create(),
            alsoResolve: false,
            alsoReprocess: false,
        );

        $this->assertSame(1, $result['catalog_hits']);
        $this->assertSame([$pair['case_gtin']], $result['authorized_gtins']);
        $this->assertSame([], $result['gtin_not_applied']);

        $caseProduct = Product::query()->where('gtin', $pair['case_gtin'])->first();
        $this->assertNotNull($caseProduct);
        $this->productIds[] = (int) $caseProduct->getKey();

        $this->assertSame([], AuthorizeMissingDocumentProducts::unknownGtinsForDocument($document));
    }

    #[Test]
    public function an_unknown_gtin_case_for_a_gtin_still_missing_stays_open(): void
    {
        $pair = $this->uniquePackagingGtinPair();
        $this->createRxPackaging($pair['package_ndc'], $pair['unit_gtin'], $pair['ndc11']);
        $missGtin = $this->uniqueUnmatchedGtin();

        $this->initializeDemo2Tenant();

        $wholesaler = $this->makeWholesaler();
        $document = $this->makeDocumentWithGtins([$pair['unit_gtin'], $missGtin]);
        $type = ExceptionType::query()->where('code', 'UNKNOWN_GTIN')->firstOrFail();

        $authorizedCase = $this->makeUnknownGtinCase($type, $document, $pair['unit_gtin']);
        $missedCase = $this->makeUnknownGtinCase($type, $document, $missGtin);

        $result = app(AuthorizeMissingDocumentProducts::class)->handle(
            $document,
            $wholesaler,
            User::factory()->create(),
            alsoResolve: true,
            alsoReprocess: false,
            resolutionNotes: 'Bulk authorized from resolution scope test.',
        );

        $this->assertSame([$pair['unit_gtin']], $result['authorized_gtins']);
        $this->assertSame([$missGtin], $result['catalog_misses']);
        $this->assertSame(1, $result['resolved_cases']);

        $authorizedProduct = Product::query()->where('gtin', $pair['unit_gtin'])->first();
        $this->assertNotNull($authorizedProduct);
        $this->productIds[] = (int) $authorizedProduct->getKey();

        $this->assertSame(ExceptionStatus::Resolved, $authorizedCase->fresh()->status);
        $this->assertNotSame(ExceptionStatus::Resolved, $missedCase->fresh()->status);
    }

    private function makeUnknownGtinCase(ExceptionType $type, EpcisDocument $document, string $gtin): ExceptionCase
    {
        $case = ExceptionCase::query()->create([
            'exception_type_id' => $type->getKey(),
            'document_id' => $document->getKey(),
            'title' => 'Unknown GTIN encountered',
            'description' => "GTIN not found in product master: {$gtin}",
            'severity' => ExceptionSeverity::High,
            'status' => ExceptionStatus::New,
        ]);

        $this->caseIds[] = (int) $case->getKey();

        return $case;
    }

    /**
     * Unit (indicator 0) and case (indicator 3) GTINs over one unused package NDC.
     *
     * @return array{package_ndc: string, unit_gtin: string, case_gtin: string, ndc11: string}
     */
    private function uniquePackagingGtinPair(): array
    {
        do {
            $packageNdc = sprintf(
                '%04d-%04d-%02d',
                random_int(1000, 9999),
                random_int(0, 9999),
                random_int(0, 99),
            );
            $ndc10 = preg_replace('/\D+/', '', $packageNdc) ?? '';
            $ndc11 = Ndc::toNdc11($packageNdc);
            $unitBody = '003'.$ndc10;
            $caseBody = '303'.$ndc10;
            $unitGtin = $unitBody.Gtin::checkDigit($unitBody);
            $caseGtin = $caseBody.Gtin::checkDigit($caseBody);
        } while (
            $ndc11 === null
            || strlen($ndc10) !== 10
            || FdaProductPackaging::query()
                ->where(fn ($query) => $query->where('package_ndc', $packageNdc)
                    ->orWhere('ndc11', $ndc11)
                    ->orWhereIn('gtin', [$unitGtin, $caseGtin]))
                ->exists()
        );

        return [
            'package_ndc' => $packageNdc,
            'unit_gtin' => $unitGtin,
            'case_gtin' => $caseGtin,
            'ndc11' => $ndc11,
        ];
    }

    /**
     * A GTIN-14 with no FDA packaging row, by GTIN or by reversed NDC.
     */
    private function uniqueUnmatchedGtin(): string
    {
        do {
            $body = '0'.str_pad((string) random_int(0, 999999999999), 12, '0', STR_PAD_LEFT);
            $gtin = $body.Gtin::checkDigit($body);
            $ndc10 = Gtin::ndc10FromNdcEncodedGtin($gtin);
            $ndc11 = $ndc10 === null ? null : Ndc::ndc11CandidatesFromTenDigits($ndc10);
        } while (
            FdaProductPackaging::query()
                ->where('gtin', $gtin)
                ->orWhereIn('ndc11', $ndc11 ?? ['none'])
                ->exists()
        );

        return $gtin;
    }

    private function createRxPackaging(string $packageNdc, string $gtin, string $ndc11): FdaProductPackaging
    {
        $suffix = uniqid();
        $org = FdaOrganization::query()->create([
            'original_name' => 'SSOR CUT2 Resolution Labeler '.$suffix,
            'canonical_name' => 'SSOR CUT2 RESOLUTION LABELER '.$suffix,
            'name' => 'SSOR CUT2 Resolution Labeler '.$suffix,
            'partner_type' => PartnerType::Manufacturer,
            'is_active' => true,
        ]);
        $this->orgIds[] = (int) $org->getKey();

        $fda = FdaProduct::query()->create([
            'product_id' => 'SSOR-CUT2-RESOLUTION-'.uniqid(),
            'product_ndc' => substr($packageNdc, 0, 9),
            'brand_name' => 'SSOR CUT2 Resolution Rx',
            'product_type' => FdaProduct::PRODUCT_TYPE_HUMAN_PRESCRIPTION,
            'fda_organization_id' => $org->id,
            'finished' => true,
            'is_active' => true,
        ]);
        $this->fdaProductIds[] = (int) $fda->getKey();

        $packaging = FdaProductPackaging::query()->create([
            'fda_product_id' => $fda->id,
            'gtin' => $gtin,
            'ndc' => $packageNdc,
            'package_ndc' => $packageNdc,
            'ndc11' => $ndc11,
            'is_active' => true,
        ]);
        $this->packagingIds[] = (int) $packaging->getKey();

        return $packaging;
    }

    private function makeWholesaler(): TradingPartner
    {
        $partner = TradingPartner::query()->create([
            'name' => 'SSOR CUT2 Resolution Wholesaler '.uniqid(),
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
        $path = 'epcis/inbound/resolution-'.(string) str()->uuid().'.xml';
        Storage::disk('local')->put($path, '<epcis/>');

        $document = EpcisDocument::query()->create([
            'document_uuid' => (string) str()->uuid(),
            'schema_version' => '1.2',
            'creation_date' => now(),
            'direction' => 'inbound',
            'format' => 'xml',
            'original_filename' => 'resolution.xml',
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
        $this->documentIds[] = (int) $document->getKey();

        $rows = [];
        foreach ($gtins as $index => $gtin) {
            $epc = Epc::query()->create([
                'epc_uri' => 'urn:epc:id:sgtin:'.substr($gtin, 1, 6).'.'.substr($gtin, 0, 1).substr($gtin, 7, 6).'.r'.$index.uniqid(),
                'epc_type' => 'sgtin',
                'company_prefix' => substr($gtin, 1, 6),
                'gtin14' => $gtin,
                'serial_number' => 'serial-'.$index.'-'.uniqid(),
                'product_id' => null,
                'first_seen_at' => now(),
            ]);
            $this->epcIds[] = (int) $epc->getKey();

            if (Schema::hasTable('document_epcs')) {
                $rows[] = [
                    'document_id' => $document->getKey(),
                    'epc_id' => $epc->getKey(),
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

            if ($this->documentIds !== [] && Schema::hasTable('document_epcs')) {
                DB::table('document_epcs')->whereIn('document_id', $this->documentIds)->delete();
            }

            if ($this->epcIds !== []) {
                Epc::query()->whereIn('id', $this->epcIds)->delete();
            }

            if ($this->documentIds !== []) {
                EpcisDocument::query()->whereIn('id', $this->documentIds)->delete();
            }

            foreach ($this->tenantPartnerIds as $partnerId) {
                TradingPartner::query()->find($partnerId)?->products()->detach();
            }

            if ($this->productIds !== []) {
                Product::query()->whereIn('id', $this->productIds)->delete();
            }

            if ($this->tenantPartnerIds !== []) {
                TradingPartner::query()->whereIn('id', $this->tenantPartnerIds)->delete();
            }

            tenancy()->end();
        }

        if ($this->packagingIds !== []) {
            FdaProductPackaging::query()->whereIn('id', $this->packagingIds)->delete();
        }

        if ($this->fdaProductIds !== []) {
            FdaProduct::query()->whereIn('id', $this->fdaProductIds)->delete();
        }

        if ($this->orgIds !== []) {
            FdaOrganization::query()->whereIn('id', $this->orgIds)->delete();
        }

        $this->caseIds = [];
        $this->epcIds = [];
        $this->documentIds = [];
        $this->productIds = [];
        $this->tenantPartnerIds = [];
        $this->packagingIds = [];
        $this->fdaProductIds = [];
        $this->orgIds = [];
    }
}
