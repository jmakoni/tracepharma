<?php

namespace Tests\Unit\Jobs;

use App\Enums\LabelPrinterProtocol;
use App\Enums\SsccAllocationMode;
use App\Enums\SsccLabelBatchStatus;
use App\Enums\SsccLabelPrintStatus;
use App\Enums\SsccPrintDeliveryMode;
use App\Enums\SsccPrintJobStatus;
use App\Enums\TenantProfile;
use App\Jobs\PrintSsccLabelJob;
use App\Models\LabelPrinter;
use App\Models\SsccLabel;
use App\Models\SsccLabelBatch;
use App\Models\SsccPrintJob;
use App\Models\SsccSerialPool;
use App\Models\Tenant;
use App\Services\Labeling\NetworkPrinterClient;
use App\Services\Labeling\SsccSerialPoolService;
use App\Services\Labeling\ZplLabelRenderer;
use App\Support\Labeling\SsccBatchPrintCompletion;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PrintSsccLabelJobTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $printJobIds = [];

    /** @var list<int> */
    private array $labelIds = [];

    /** @var list<int> */
    private array $batchIds = [];

    /** @var list<int> */
    private array $printerIds = [];

    /** @var list<int> */
    private array $poolIds = [];

    #[Test]
    public function print_job_fails_for_client_side_printer_protocol_without_tcp(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $printer = LabelPrinter::query()->create([
                'name' => 'Workstation Zebra',
                'ip_address' => null,
                'port' => null,
                'protocol' => LabelPrinterProtocol::QzTray,
                'settings' => ['client_printer_name' => 'ZDesigner'],
                'enabled' => true,
            ]);
            $this->printerIds[] = (int) $printer->id;

            $batch = SsccLabelBatch::query()->create([
                'company_prefix' => '030116',
                'extension_digit' => '0',
                'allocation_mode' => SsccAllocationMode::Sequential,
                'label_count' => 1,
                'copies_per_label' => 1,
                'status' => SsccLabelBatchStatus::Completed,
            ]);
            $this->batchIds[] = (int) $batch->id;

            $label = SsccLabel::query()->create([
                'batch_id' => $batch->id,
                'label_printer_id' => $printer->id,
                'sscc_18' => '003011600002101675',
                'sscc_urn' => 'urn:epc:id:sscc:030116.00000210167',
                'extension_digit' => '0',
                'company_prefix' => '030116',
                'serial_reference' => '0000210167',
                'serial_reference_int' => 210167,
                'element_string' => '0003011600002101675',
                'hrt' => '0003011600002101675',
                'label_disk' => 'local',
                'label_path' => 'test.pdf',
            ]);
            $this->labelIds[] = (int) $label->id;

            $job = SsccPrintJob::query()->create([
                'sscc_label_batch_id' => $batch->id,
                'sscc_label_id' => $label->id,
                'label_printer_id' => $printer->id,
                'copies' => 1,
                'status' => SsccPrintJobStatus::Queued,
                'queued_at' => now(),
            ]);
            $this->printJobIds[] = (int) $job->id;

            $mock = $this->createMock(NetworkPrinterClient::class);
            $mock->expects($this->never())->method('send');
            $this->app->instance(NetworkPrinterClient::class, $mock);

            (new PrintSsccLabelJob($tenant, $job->id))->handle(
                app(ZplLabelRenderer::class),
                app(NetworkPrinterClient::class),
                app(SsccSerialPoolService::class),
                app(SsccBatchPrintCompletion::class),
            );

            $job->refresh();
            $label->refresh();

            $this->assertSame(SsccPrintJobStatus::Failed, $job->status);
            $this->assertSame(SsccLabelPrintStatus::Failed, $label->print_status);
            $this->assertStringContainsString('browser workstation', (string) $job->last_error);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function print_job_skips_superseded_jobs_without_tcp(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $printer = LabelPrinter::query()->create([
                'name' => 'Zebra Network',
                'ip_address' => '10.0.0.50',
                'port' => 9100,
                'protocol' => LabelPrinterProtocol::ZplRaw,
                'enabled' => true,
            ]);
            $this->printerIds[] = (int) $printer->id;

            $batch = SsccLabelBatch::query()->create([
                'company_prefix' => '030116',
                'extension_digit' => '0',
                'allocation_mode' => SsccAllocationMode::Sequential,
                'label_count' => 1,
                'copies_per_label' => 1,
                'status' => SsccLabelBatchStatus::Completed,
            ]);
            $this->batchIds[] = (int) $batch->id;

            $label = SsccLabel::query()->create([
                'batch_id' => $batch->id,
                'label_printer_id' => $printer->id,
                'sscc_18' => '003011600002101675',
                'sscc_urn' => 'urn:epc:id:sscc:030116.00000210167',
                'extension_digit' => '0',
                'company_prefix' => '030116',
                'serial_reference' => '0000210167',
                'serial_reference_int' => 210167,
                'element_string' => '0003011600002101675',
                'hrt' => '0003011600002101675',
                'label_disk' => 'local',
                'label_path' => 'test.pdf',
                'print_status' => SsccLabelPrintStatus::Queued,
            ]);
            $this->labelIds[] = (int) $label->id;

            $job = SsccPrintJob::query()->create([
                'sscc_label_batch_id' => $batch->id,
                'sscc_label_id' => $label->id,
                'label_printer_id' => $printer->id,
                'copies' => 1,
                'status' => SsccPrintJobStatus::Failed,
                'last_error' => 'Superseded by a newer print request.',
                'queued_at' => now(),
                'delivery_mode' => SsccPrintDeliveryMode::Queue,
            ]);
            $this->printJobIds[] = (int) $job->id;

            $mock = $this->createMock(NetworkPrinterClient::class);
            $mock->expects($this->never())->method('send');
            $this->app->instance(NetworkPrinterClient::class, $mock);

            (new PrintSsccLabelJob($tenant, $job->id))->handle(
                app(ZplLabelRenderer::class),
                app(NetworkPrinterClient::class),
                app(SsccSerialPoolService::class),
                app(SsccBatchPrintCompletion::class),
            );

            $job->refresh();
            $this->assertSame(SsccPrintJobStatus::Failed, $job->status);
            $this->assertStringContainsString('Superseded', (string) $job->last_error);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function print_job_updates_label_and_pool_last_printed_serial(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $printer = LabelPrinter::query()->create([
                'name' => 'Zebra 1',
                'ip_address' => '10.0.0.50',
                'port' => 9100,
                'protocol' => LabelPrinterProtocol::ZplRaw,
                'enabled' => true,
            ]);
            $this->printerIds[] = (int) $printer->id;

            $batch = SsccLabelBatch::query()->create([
                'company_prefix' => '030116',
                'extension_digit' => '0',
                'allocation_mode' => SsccAllocationMode::Sequential,
                'label_count' => 1,
                'copies_per_label' => 2,
                'status' => SsccLabelBatchStatus::Completed,
            ]);
            $this->batchIds[] = (int) $batch->id;

            $label = SsccLabel::query()->create([
                'batch_id' => $batch->id,
                'label_printer_id' => $printer->id,
                'sscc_18' => '003011600002101675',
                'sscc_urn' => 'urn:epc:id:sscc:030116.00000210167',
                'extension_digit' => '0',
                'company_prefix' => '030116',
                'serial_reference' => '0000210167',
                'serial_reference_int' => 210167,
                'element_string' => '0003011600002101675',
                'hrt' => '0003011600002101675',
                'label_disk' => 'local',
                'label_path' => 'test.pdf',
            ]);
            $this->labelIds[] = (int) $label->id;

            $pool = SsccSerialPool::query()->create([
                'company_prefix' => '030116',
                'extension_digit' => '0',
                'default_allocation_mode' => SsccAllocationMode::Sequential,
                'last_serial_reference_int' => 210167,
            ]);
            $this->poolIds[] = (int) $pool->id;

            $job = SsccPrintJob::query()->create([
                'sscc_label_batch_id' => $batch->id,
                'sscc_label_id' => $label->id,
                'label_printer_id' => $printer->id,
                'copies' => 2,
                'status' => SsccPrintJobStatus::Queued,
                'queued_at' => now(),
            ]);
            $this->printJobIds[] = (int) $job->id;

            $mock = $this->createMock(NetworkPrinterClient::class);
            $mock->expects($this->once())->method('send')->with('10.0.0.50', 9100, $this->stringContains('^XA'));
            $this->app->instance(NetworkPrinterClient::class, $mock);

            (new PrintSsccLabelJob($tenant, $job->id))->handle(
                app(ZplLabelRenderer::class),
                app(NetworkPrinterClient::class),
                app(SsccSerialPoolService::class),
                app(SsccBatchPrintCompletion::class),
            );

            $job->refresh();
            $label->refresh();
            $pool->refresh();

            $this->assertSame(SsccPrintJobStatus::Printed, $job->status);
            $this->assertSame(SsccLabelPrintStatus::Printed, $label->print_status);
            $this->assertSame(2, $label->printed_copies);
            $this->assertSame(210167, $pool->last_printed_serial_reference_int);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function print_job_is_unique_and_skips_tcp_when_already_printing(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $printer = LabelPrinter::query()->create([
                'name' => 'Zebra Crash Recovery',
                'ip_address' => '10.0.0.50',
                'port' => 9100,
                'protocol' => LabelPrinterProtocol::ZplRaw,
                'enabled' => true,
            ]);
            $this->printerIds[] = (int) $printer->id;

            $batch = SsccLabelBatch::query()->create([
                'company_prefix' => '030116',
                'extension_digit' => '0',
                'allocation_mode' => SsccAllocationMode::Sequential,
                'label_count' => 1,
                'copies_per_label' => 1,
                'status' => SsccLabelBatchStatus::Completed,
            ]);
            $this->batchIds[] = (int) $batch->id;

            $label = SsccLabel::query()->create([
                'batch_id' => $batch->id,
                'label_printer_id' => $printer->id,
                'sscc_18' => '003011600002101682',
                'sscc_urn' => 'urn:epc:id:sscc:030116.00000210168',
                'extension_digit' => '0',
                'company_prefix' => '030116',
                'serial_reference' => '0000210168',
                'serial_reference_int' => 210168,
                'element_string' => '0003011600002101682',
                'hrt' => '0003011600002101682',
                'label_disk' => 'local',
                'label_path' => 'test.pdf',
            ]);
            $this->labelIds[] = (int) $label->id;

            $pool = SsccSerialPool::query()->create([
                'company_prefix' => '030116',
                'extension_digit' => '0',
                'default_allocation_mode' => SsccAllocationMode::Sequential,
                'last_serial_reference_int' => 210168,
            ]);
            $this->poolIds[] = (int) $pool->id;

            $job = SsccPrintJob::query()->create([
                'sscc_label_batch_id' => $batch->id,
                'sscc_label_id' => $label->id,
                'label_printer_id' => $printer->id,
                'copies' => 1,
                'status' => SsccPrintJobStatus::Printing,
                'attempts' => 1,
                'queued_at' => now(),
            ]);
            $this->printJobIds[] = (int) $job->id;

            $queueJob = new PrintSsccLabelJob($tenant, $job->id);
            $this->assertInstanceOf(ShouldBeUnique::class, $queueJob);
            $this->assertNotEmpty($queueJob->middleware());

            $mock = $this->createMock(NetworkPrinterClient::class);
            $mock->expects($this->never())->method('send');
            $this->app->instance(NetworkPrinterClient::class, $mock);

            $queueJob->handle(
                app(ZplLabelRenderer::class),
                app(NetworkPrinterClient::class),
                app(SsccSerialPoolService::class),
                app(SsccBatchPrintCompletion::class),
            );

            $job->refresh();
            $this->assertSame(SsccPrintJobStatus::Printed, $job->status);
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
        if (! tenancy()->initialized) {
            return;
        }

        if ($this->printJobIds !== []) {
            SsccPrintJob::query()->whereIn('id', $this->printJobIds)->delete();
        }

        if ($this->labelIds !== []) {
            SsccLabel::query()->whereIn('id', $this->labelIds)->delete();
        }

        if ($this->batchIds !== []) {
            SsccLabelBatch::query()->whereIn('id', $this->batchIds)->delete();
        }

        if ($this->printerIds !== []) {
            LabelPrinter::query()->whereIn('id', $this->printerIds)->delete();
        }

        if ($this->poolIds !== []) {
            SsccSerialPool::query()->whereIn('id', $this->poolIds)->delete();
        }

        tenancy()->end();
    }
}
