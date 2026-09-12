<?php

namespace Tests\Feature\Labeling;

use App\Actions\Labeling\DispatchSsccBatchPrint;
use App\Enums\ClientPrintBridge;
use App\Enums\LabelPrinterProtocol;
use App\Enums\SsccAllocationMode;
use App\Enums\SsccLabelBatchStatus;
use App\Enums\SsccLabelPrintStatus;
use App\Enums\TenantProfile;
use App\Jobs\PrintSsccLabelJob;
use App\Models\LabelPrinter;
use App\Models\SsccLabel;
use App\Models\SsccLabelBatch;
use App\Models\SsccPrintJob;
use App\Models\Tenant;
use App\Support\TenantSettings;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ClientPrintDispatchSsccBatchPrintTest extends TestCase
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

    #[Test]
    public function client_print_qz_tray_returns_client_mode_with_zpl_and_skips_queue(): void
    {
        Queue::fake([PrintSsccLabelJob::class]);

        $this->initializeDemo2Tenant();

        try {
            [$batch, $label] = $this->createBatchWithLabel(LabelPrinterProtocol::QzTray);

            $result = app(DispatchSsccBatchPrint::class)->execute($batch);

            $this->assertSame('client', $result['mode']);
            $this->assertSame(ClientPrintBridge::QzTray->value, $result['bridge']);
            $this->assertCount(1, $result['jobs']);
            $this->assertSame((int) $label->id, $result['jobs'][0]['label_id']);
            $this->assertStringContainsString('^XA', $result['jobs'][0]['zpl']);
            $this->assertSame('ZDesigner', $result['jobs'][0]['printer_name']);

            Queue::assertNothingPushed();

            $label->refresh();
            $this->assertSame(SsccLabelPrintStatus::Queued, $label->print_status);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function client_print_zebra_browser_print_returns_client_mode_with_zpl_and_skips_queue(): void
    {
        Queue::fake([PrintSsccLabelJob::class]);

        $this->initializeDemo2Tenant();

        try {
            [$batch, $label] = $this->createBatchWithLabel(LabelPrinterProtocol::ZebraBrowserPrint);

            $result = app(DispatchSsccBatchPrint::class)->execute($batch);

            $this->assertSame('client', $result['mode']);
            $this->assertSame(ClientPrintBridge::ZebraBrowserPrint->value, $result['bridge']);
            $this->assertCount(1, $result['jobs']);
            $this->assertSame((int) $label->id, $result['jobs'][0]['label_id']);
            $this->assertStringContainsString('^XA', $result['jobs'][0]['zpl']);

            Queue::assertNothingPushed();
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function client_print_zpl_raw_returns_network_mode_and_dispatches_queue_job(): void
    {
        Queue::fake([PrintSsccLabelJob::class]);

        $tenant = $this->initializeDemo2Tenant();

        try {
            TenantSettings::forTenant($tenant)->setClientPrintBridge(ClientPrintBridge::NetworkTcp);
            $tenant->save();

            [$batch, $label] = $this->createBatchWithLabel(LabelPrinterProtocol::ZplRaw);

            $result = app(DispatchSsccBatchPrint::class)->execute($batch);

            $this->assertSame('network', $result['mode']);
            $this->assertSame(ClientPrintBridge::NetworkTcp->value, $result['bridge']);
            $this->assertSame([], $result['jobs']);

            Queue::assertPushed(PrintSsccLabelJob::class, function (PrintSsccLabelJob $job) use ($tenant, $label): bool {
                return $job->tenant->is($tenant)
                    && SsccPrintJob::query()
                        ->whereKey($job->printJobId)
                        ->where('sscc_label_id', $label->id)
                        ->exists();
            });
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function client_print_network_printer_follows_org_qz_tray_preference(): void
    {
        Queue::fake([PrintSsccLabelJob::class]);

        $tenant = $this->initializeDemo2Tenant();
        $prior = TenantSettings::forTenant($tenant)->clientPrintBridge();

        try {
            TenantSettings::forTenant($tenant)->setClientPrintBridge(ClientPrintBridge::QzTray);
            $tenant->save();
            $tenant->refresh();

            [$batch] = $this->createBatchWithLabel(LabelPrinterProtocol::ZplRaw, [
                'client_printer_name' => 'ZDesigner',
            ]);

            $result = app(DispatchSsccBatchPrint::class)->execute($batch);

            $this->assertSame('client', $result['mode']);
            $this->assertSame(ClientPrintBridge::QzTray->value, $result['bridge']);
            $this->assertCount(1, $result['jobs']);
            Queue::assertNothingPushed();
        } finally {
            TenantSettings::forTenant($tenant)->setClientPrintBridge($prior);
            $tenant->save();
            $this->cleanup();
        }
    }

    #[Test]
    public function execute_fails_closed_when_send_to_printer_without_printer(): void
    {
        Queue::fake([PrintSsccLabelJob::class]);

        $this->initializeDemo2Tenant();

        try {
            $batch = SsccLabelBatch::query()->create([
                'company_prefix' => '030116',
                'extension_digit' => '0',
                'allocation_mode' => SsccAllocationMode::Sequential,
                'label_count' => 1,
                'copies_per_label' => 1,
                'label_printer_id' => null,
                'send_to_printer' => true,
                'status' => SsccLabelBatchStatus::Completed,
                'commissioned_at' => now(),
            ]);
            $this->batchIds[] = (int) $batch->id;

            $label = SsccLabel::query()->create([
                'batch_id' => $batch->id,
                'label_printer_id' => null,
                'sscc_18' => '003011600002109999',
                'sscc_urn' => 'urn:epc:id:sscc:030116.00000210999',
                'extension_digit' => '0',
                'company_prefix' => '030116',
                'serial_reference' => '0000210999',
                'serial_reference_int' => 210999,
                'element_string' => '0003011600002109999',
                'hrt' => '0003011600002109999',
                'label_disk' => 'local',
                'label_path' => 'sscc/fail-closed.pdf',
                'commissioned_at' => now(),
            ]);
            $this->labelIds[] = (int) $label->id;
            $batch->load('labels');

            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessageMatches('/printer/i');

            app(DispatchSsccBatchPrint::class)->execute($batch);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function resolve_bridge_fails_closed_when_printer_missing_and_no_override(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessageMatches('/printer|bridge/i');

            app(DispatchSsccBatchPrint::class)->resolveBridgeForPrinter(null);
        } finally {
            $this->cleanup();
        }
    }

    /**
     * @return array{0: SsccLabelBatch, 1: SsccLabel}
     */
    private function createBatchWithLabel(LabelPrinterProtocol $protocol, array $extraSettings = []): array
    {
        $settings = match ($protocol) {
            LabelPrinterProtocol::QzTray => ['client_printer_name' => 'ZDesigner'],
            LabelPrinterProtocol::ZebraBrowserPrint => ['client_printer_name' => 'ZebraWorkstation'],
            LabelPrinterProtocol::ZplRaw => [],
        };

        $settings = array_merge($settings, $extraSettings);

        $printer = LabelPrinter::query()->create([
            'name' => 'Client Print Test Printer',
            'ip_address' => $protocol === LabelPrinterProtocol::ZplRaw ? '10.0.0.55' : null,
            'port' => $protocol === LabelPrinterProtocol::ZplRaw ? 9100 : null,
            'protocol' => $protocol,
            'settings' => $settings,
            'enabled' => true,
        ]);
        $this->printerIds[] = (int) $printer->id;

        $batch = SsccLabelBatch::query()->create([
            'company_prefix' => '030116',
            'extension_digit' => '0',
            'allocation_mode' => SsccAllocationMode::Sequential,
            'label_count' => 1,
            'copies_per_label' => 2,
            'label_printer_id' => $printer->id,
            'send_to_printer' => true,
            'status' => SsccLabelBatchStatus::Completed,
            'commissioned_at' => now(),
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
            'ship_to_name' => 'Test DC',
            'label_disk' => 'local',
            'label_path' => 'sscc/client-print-test.pdf',
            'commissioned_at' => now(),
        ]);
        $this->labelIds[] = (int) $label->id;

        $batch->load('labels');

        return [$batch, $label];
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
        } else {
            SsccPrintJob::query()
                ->whereIn('sscc_label_id', $this->labelIds)
                ->delete();
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

        $this->printJobIds = [];
        $this->labelIds = [];
        $this->batchIds = [];
        $this->printerIds = [];

        tenancy()->end();
    }
}
