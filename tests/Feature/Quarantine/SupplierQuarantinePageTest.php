<?php

namespace Tests\Feature\Quarantine;

use App\Enums\ExceptionActivityKind;
use App\Enums\ExceptionActivityVisibility;
use App\Enums\TenantProfile;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcIlmd;
use App\Models\Epcis\EpcisDocument;
use App\Models\Exceptions\ExceptionActivity;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Product;
use App\Models\Quarantine\QuarantineHold;
use App\Models\Tenant;
use App\Services\Quarantine\QuarantineService;
use App\Services\Quarantine\SupplierQuarantineTableBuilder;
use Database\Seeders\ExceptionCaseSeeder;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SupplierQuarantinePageTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $epcIds = [];

    /** @var list<int> */
    private array $caseIds = [];

    /** @var list<int> */
    private array $productIds = [];

    /** @var list<int> */
    private array $documentIds = [];

    #[Test]
    public function supplier_page_loads_with_valid_signed_url_and_shows_case_title(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $reason = 'Supplier page visibility test';
            $epc = $this->createEpc(substr((string) str()->uuid(), 0, 8));

            $case = app(QuarantineService::class)->quarantineFromFindRecall(
                epcIds: [$epc->id],
                reason: $reason,
            );
            $this->caseIds[] = (int) $case->getKey();

            URL::forceRootUrl('http://'.self::DEMO2_DOMAIN);
            $url = app(QuarantineService::class)->signedSupplierUrl($case->fresh());

            tenancy()->end();

            $response = $this->get($url);

            $response->assertOk();
            $response->assertSee($case->title, false);
            $response->assertSee('Supplier Quarantine', false);
        } finally {
            if (! tenancy()->initialized) {
                tenancy()->initialize($tenant);
            }
            $this->cleanup();
        }
    }

    #[Test]
    public function supplier_page_shows_po_summary_and_identifier_columns(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $suffix = substr((string) str()->uuid(), 0, 8);
            $po = 'PO-SQ-'.$suffix;
            $ndc = '12345-678-90';
            $productName = 'Chlorhexidine Test '.$suffix;
            $lot = 'LOT'.$suffix;
            $serial = 's'.$suffix;

            $product = Product::query()->create([
                'gtin' => '00301162001162',
                'name' => $productName,
                'package_ndc' => $ndc,
                'ndc11' => '12345678901',
                'is_active' => true,
            ]);
            $this->productIds[] = (int) $product->getKey();

            $document = EpcisDocument::query()->create([
                'document_uuid' => (string) str()->uuid(),
                'schema_version' => '1.2',
                'creation_date' => now(),
                'direction' => 'inbound',
                'format' => 'xml',
                'original_filename' => 'supplier-quarantine-test.xml',
                'file_sha256' => hash('sha256', (string) str()->uuid()),
                'payload_disk' => 'local',
                'payload_path' => 'epcis/inbound/supplier-quarantine-'.$suffix.'.xml',
                'dscsa_affirm' => false,
                'status' => 'received',
                'event_count' => 0,
                'epc_count' => 1,
                'received_at' => now(),
                'ingest_generation' => 1,
                'customer_po' => $po,
            ]);
            $this->documentIds[] = (int) $document->getKey();

            $epc = Epc::query()->create([
                'epc_type' => 'sgtin',
                'epc_uri' => "urn:epc:id:sgtin:030116.0200116.{$serial}",
                'gtin14' => '00301162001162',
                'serial_number' => $serial,
                'company_prefix' => '030116',
                'product_id' => $product->getKey(),
                'first_seen_at' => now(),
            ]);
            $this->epcIds[] = (int) $epc->id;

            EpcIlmd::query()->create([
                'epc_id' => $epc->id,
                'gtin14' => '00301162001162',
                'lot_number' => $lot,
                'expiry_date' => '2027-06-30',
            ]);

            $case = app(QuarantineService::class)->quarantineFromFindRecall(
                epcIds: [$epc->id],
                reason: 'PO table visibility test',
                document: $document,
            );
            $this->caseIds[] = (int) $case->getKey();

            QuarantineHold::query()
                ->where('exception_id', $case->getKey())
                ->where('epc_id', $epc->id)
                ->update(['opened_at' => now()->subDays(3)]);

            $rows = app(SupplierQuarantineTableBuilder::class)->identifierRows($case->fresh());
            $this->assertCount(1, $rows);
            $this->assertSame($po, $rows->first()['po']);
            $this->assertSame($ndc, $rows->first()['ndc']);
            $this->assertSame($productName, $rows->first()['product_name']);
            $this->assertSame($lot, $rows->first()['lot']);
            $this->assertSame($case->status->label(), $rows->first()['status']);
            $this->assertSame(3, $rows->first()['days_held']);

            $summary = app(SupplierQuarantineTableBuilder::class)->summaryRows($rows);
            $this->assertCount(1, $summary);
            $this->assertSame(1, $summary->first()['quantity']);
            $this->assertSame(3, $summary->first()['days_held']);

            URL::forceRootUrl('http://'.self::DEMO2_DOMAIN);
            $url = app(QuarantineService::class)->signedSupplierUrl($case->fresh());
            $statusLabel = $case->status->label();

            tenancy()->end();

            $response = $this->get($url);

            $response->assertOk();
            $response->assertSee('Affected products', false);
            $response->assertSee('Days held', false);
            $response->assertSee('Date quarantined', false);
            $response->assertSee('Date resolved', false);
            $response->assertSee($po, false);
            $response->assertSee($ndc, false);
            $response->assertSee($productName, false);
            $response->assertSee($serial, false);
            $response->assertSee($lot, false);
            $response->assertSee('2027-06-30', false);
            $response->assertSee($statusLabel, false);
        } finally {
            if (! tenancy()->initialized) {
                tenancy()->initialize($tenant);
            }
            $this->cleanup();
        }
    }

    #[Test]
    public function supplier_comment_post_creates_partner_visible_activity(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $epc = $this->createEpc(substr((string) str()->uuid(), 0, 8));

            $case = app(QuarantineService::class)->quarantineFromFindRecall(
                epcIds: [$epc->id],
                reason: 'Awaiting supplier acknowledgment',
            );
            $this->caseIds[] = (int) $case->getKey();

            URL::forceRootUrl('http://'.self::DEMO2_DOMAIN);
            app(QuarantineService::class)->ensureShareLink($case->fresh());
            $case->refresh();

            $commentUrl = URL::signedRoute(
                'tenant.supplier-quarantine.comment',
                ['shareUuid' => $case->share_uuid],
            );

            tenancy()->end();

            $response = $this->post($commentUrl, [
                'supplier_name' => 'Acme Wholesale',
                'body' => 'We are investigating this lot and will respond within 24 hours.',
            ]);

            $response->assertRedirect();
            $response->assertSessionHas('status');

            tenancy()->initialize($tenant);

            $activity = ExceptionActivity::query()
                ->where('exception_id', $case->getKey())
                ->where('visibility', ExceptionActivityVisibility::Partner->value)
                ->where('kind', ExceptionActivityKind::Comment->value)
                ->latest('id')
                ->first();

            $this->assertNotNull($activity);
            $this->assertStringContainsString('[Acme Wholesale]', $activity->body);
            $this->assertStringContainsString('investigating this lot', $activity->body);
            $this->assertSame('supplier_quarantine_page', $activity->meta['source'] ?? null);
        } finally {
            if (! tenancy()->initialized) {
                tenancy()->initialize($tenant);
            }
            $this->cleanup();
        }
    }

    private function createEpc(string $suffix): Epc
    {
        $epc = Epc::query()->create([
            'epc_type' => 'sgtin',
            'epc_uri' => "urn:epc:id:sgtin:030116.0200116.q{$suffix}",
            'gtin14' => '00301162001162',
            'serial_number' => "q{$suffix}",
            'company_prefix' => '030116',
            'first_seen_at' => now(),
        ]);
        $this->epcIds[] = (int) $epc->id;

        return $epc;
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
        if (! tenancy()->initialized) {
            return;
        }

        foreach ($this->caseIds as $caseId) {
            $case = ExceptionCase::query()->find($caseId);
            if ($case === null) {
                continue;
            }

            $case->activities()->delete();
            QuarantineHold::query()->where('exception_id', $caseId)->delete();
            $case->epcs()->detach();
            $case->delete();
        }
        $this->caseIds = [];

        foreach ($this->epcIds as $id) {
            QuarantineHold::query()->where('epc_id', $id)->delete();
            EpcIlmd::query()->where('epc_id', $id)->delete();
            Epc::query()->whereKey($id)->delete();
        }
        $this->epcIds = [];

        foreach ($this->documentIds as $id) {
            EpcisDocument::query()->whereKey($id)->delete();
        }
        $this->documentIds = [];

        foreach ($this->productIds as $id) {
            Product::query()->whereKey($id)->delete();
        }
        $this->productIds = [];

        tenancy()->end();
    }
}
