<?php

namespace Tests\Feature\Labeling;

use App\Actions\Labeling\EnsureSsccLabelPdf;
use App\Enums\SsccAllocationMode;
use App\Enums\SsccLabelBatchStatus;
use App\Enums\SsccLabelPrintStatus;
use App\Enums\TenantProfile;
use App\Models\SsccLabel;
use App\Models\SsccLabelBatch;
use App\Models\Tenant;
use App\Services\Labeling\SsccBuilder;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EnsureSsccLabelPdfTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    private ?int $labelId = null;

    private ?int $batchId = null;

    #[Test]
    public function it_regenerates_missing_pdf_on_disk(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Storage::fake('local');

            $built = app(SsccBuilder::class)->build('0366159', 91001, 0);

            $batch = SsccLabelBatch::query()->create([
                'company_prefix' => $built['company_prefix'],
                'extension_digit' => $built['extension_digit'],
                'allocation_mode' => SsccAllocationMode::Sequential,
                'label_count' => 1,
                'copies_per_label' => 1,
                'status' => SsccLabelBatchStatus::Completed,
                'completed_at' => now(),
            ]);
            $this->batchId = (int) $batch->getKey();

            $path = 'labels/sscc/'.$built['sscc_18'].'-missing.pdf';
            $label = SsccLabel::query()->create([
                'batch_id' => $batch->getKey(),
                'sscc_18' => $built['sscc_18'],
                'sscc_urn' => $built['sscc_urn'],
                'extension_digit' => $built['extension_digit'],
                'company_prefix' => $built['company_prefix'],
                'serial_reference' => $built['serial_reference'],
                'serial_reference_int' => $built['serial_reference_int'],
                'allocation_mode' => SsccAllocationMode::Sequential,
                'element_string' => $built['element_string'],
                'hrt' => $built['hrt'],
                'label_disk' => 'local',
                'label_path' => $path,
                'print_status' => SsccLabelPrintStatus::Pending,
            ]);
            $this->labelId = (int) $label->getKey();

            $this->assertFalse(Storage::disk('local')->exists($path));

            $ensured = app(EnsureSsccLabelPdf::class)->handle($label->fresh());

            $this->assertTrue(Storage::disk('local')->exists($ensured->label_path));
            $this->assertGreaterThan(100, Storage::disk('local')->size($ensured->label_path));
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
        if (tenancy()->initialized) {
            if ($this->labelId !== null) {
                SsccLabel::query()->whereKey($this->labelId)->delete();
                $this->labelId = null;
            }

            if ($this->batchId !== null) {
                SsccLabelBatch::query()->whereKey($this->batchId)->delete();
                $this->batchId = null;
            }

            tenancy()->end();
        }
    }
}
