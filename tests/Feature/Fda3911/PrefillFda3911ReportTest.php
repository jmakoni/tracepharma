<?php

namespace Tests\Feature\Fda3911;

use App\Actions\Fda3911\MarkFda3911Submitted;
use App\Actions\Fda3911\PrefillFda3911Report;
use App\Actions\Fda3911\RecordFda3911IncidentNumber;
use App\Enums\ExceptionDisposition;
use App\Enums\Fda3911Classification;
use App\Enums\Fda3911ReportStatus;
use App\Enums\TenantProfile;
use App\Models\Epcis\Epc;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Fda3911Report;
use App\Models\Product;
use App\Models\Quarantine\QuarantineHold;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Quarantine\QuarantineService;
use Database\Seeders\ExceptionCaseSeeder;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PrefillFda3911ReportTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $caseIds = [];

    /** @var list<int> */
    private array $epcIds = [];

    /** @var list<int> */
    private array $reportIds = [];

    /** @var list<int> */
    private array $productIds = [];

    #[Test]
    public function prefill_from_exception_creates_draft_with_pdf_then_mark_submitted(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Storage::fake(config('filesystems.default', 'local'));

            $user = User::query()->first() ?? User::factory()->create([
                'name' => 'Jane Pharmacist',
                'email' => 'jane.fda3911@demo.test',
            ]);

            $product = Product::query()->create([
                'gtin' => '00301162001162',
                'name' => 'Test Drug 100mg',
                'ndc11' => '03011620116',
                'strength' => '100mg',
                'dosage_form' => 'Tablet',
                'is_active' => true,
            ]);
            $this->productIds[] = (int) $product->id;

            $epc = Epc::query()->create([
                'epc_type' => 'sgtin',
                'epc_uri' => 'urn:epc:id:sgtin:030116.0200116.fda3911ser',
                'gtin14' => '00301162001162',
                'serial_number' => 'fda3911ser',
                'company_prefix' => '030116',
                'product_id' => $product->id,
                'first_seen_at' => now(),
            ]);
            $this->epcIds[] = (int) $epc->id;

            $case = app(QuarantineService::class)->quarantineFromFindRecall(
                epcIds: [$epc->id],
                reason: 'Suspect product for FDA 3911',
                actor: $user,
            );
            $this->caseIds[] = (int) $case->getKey();

            app(QuarantineService::class)->markIllegitimate(
                $case,
                $user,
                'Counterfeit confirmed during investigation.',
            );
            $case->refresh();

            $report = app(PrefillFda3911Report::class)->execute($user, $case);
            $this->reportIds[] = (int) $report->id;

            $this->assertInstanceOf(Fda3911Report::class, $report);
            $this->assertSame(Fda3911ReportStatus::Draft, $report->status);
            $this->assertSame(Fda3911Classification::Illegitimate, $report->classification);
            $this->assertSame((int) $case->getKey(), (int) $report->exception_id);
            $this->assertSame('00301162001162', $report->product_gtin);
            $this->assertSame('fda3911ser', $report->serial);
            $this->assertSame('Test Drug 100mg', $report->product_name);
            $this->assertSame('03011620116', $report->product_ndc);
            $this->assertNotNull($report->generated_pdf_path);
            $this->assertNotNull($report->due_at);
            $this->assertTrue(Storage::disk(config('filesystems.default', 'local'))->exists($report->generated_pdf_path));
            $this->assertStringContainsString('Suspect product', $report->circumstances);

            app(MarkFda3911Submitted::class)->execute($report, $user);
            $report->refresh();
            $this->assertSame(Fda3911ReportStatus::Submitted, $report->status);
            $this->assertNotNull($report->submitted_at);
            $this->assertSame((int) $user->id, (int) $report->submitted_by);

            app(RecordFda3911IncidentNumber::class)->execute($report, 'INC-3911-DEMO');
            $report->refresh();
            $this->assertSame(Fda3911ReportStatus::Acknowledged, $report->status);
            $this->assertSame('INC-3911-DEMO', $report->incident_number);
            $this->assertSame(ExceptionDisposition::Illegitimate, $case->fresh()->disposition);
        } finally {
            $this->cleanup();
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
                'gln' => '1234567890128',
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

        foreach ($this->reportIds as $id) {
            Fda3911Report::query()->whereKey($id)->delete();
        }
        $this->reportIds = [];

        foreach ($this->caseIds as $id) {
            $case = ExceptionCase::query()->find($id);
            if ($case === null) {
                continue;
            }
            $case->activities()->delete();
            QuarantineHold::query()->where('exception_id', $id)->delete();
            Fda3911Report::query()->where('exception_id', $id)->delete();
            $case->epcs()->detach();
            $case->delete();
        }
        $this->caseIds = [];

        foreach ($this->epcIds as $id) {
            QuarantineHold::query()->where('epc_id', $id)->delete();
            Epc::query()->whereKey($id)->delete();
        }
        $this->epcIds = [];

        foreach ($this->productIds as $id) {
            Product::query()->whereKey($id)->delete();
        }
        $this->productIds = [];

        tenancy()->end();
    }
}
