<?php

namespace Tests\Feature\Labeling;

use App\Enums\ClientPrintBridge;
use App\Enums\LabelPrinterProtocol;
use App\Enums\SsccAllocationMode;
use App\Enums\SsccLabelBatchStatus;
use App\Enums\SsccLabelPrintStatus;
use App\Enums\SsccPrintDeliveryMode;
use App\Enums\SsccPrintJobStatus;
use App\Enums\TenantProfile;
use App\Models\LabelPrinter;
use App\Models\SsccLabel;
use App\Models\SsccLabelBatch;
use App\Models\SsccPrintJob;
use App\Models\SsccSerialPool;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Labeling\ResolveClientPrintBridge;
use App\Support\TenantSettings;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ClientLabelPrintControllerTest extends TestCase
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

    /** @var list<int> */
    private array $userIds = [];

    private ?ClientPrintBridge $priorTenantBridge = null;

    private function makeUser(): User
    {
        $user = User::factory()->create([
            'email' => 'client-print-'.uniqid('', true).'@example.test',
        ]);
        $this->userIds[] = (int) $user->id;

        return $user;
    }

    #[Test]
    public function client_print_config_returns_active_bridge(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            TenantSettings::forTenant($tenant)
                ->setClientPrintBridge(ClientPrintBridge::QzTray);
            $tenant->save();

            $user = $this->makeUser();

            $this->tenantJson('GET', '/label-print/config', $user)
                ->assertOk()
                ->assertJson([
                    'bridge' => ClientPrintBridge::QzTray->value,
                    'bridge_label' => ClientPrintBridge::QzTray->shortLabel(),
                    'is_client_side' => true,
                ])
                ->assertJsonStructure([
                    'routes' => ['set_bridge', 'clear_bridge', 'start_job', 'assert_job', 'complete_job'],
                ]);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function client_print_bridge_post_sets_session_override(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            TenantSettings::forTenant($tenant)
                ->setClientPrintBridge(ClientPrintBridge::NetworkTcp);
            $tenant->save();

            $user = $this->makeUser();

            $this->tenantJson('POST', '/label-print/bridge', $user, [
                'bridge' => ClientPrintBridge::ZebraBrowserPrint->value,
            ])
                ->assertOk()
                ->assertJson([
                    'bridge' => ClientPrintBridge::ZebraBrowserPrint->value,
                    'label' => ClientPrintBridge::ZebraBrowserPrint->shortLabel(),
                ]);

            $this->tenantJson('GET', '/label-print/config', $user)
                ->assertOk()
                ->assertJson([
                    'bridge' => ClientPrintBridge::ZebraBrowserPrint->value,
                    'is_client_side' => true,
                ]);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function client_print_label_zpl_returns_rendered_payload(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            [$label] = $this->createLabelArtifacts();

            $user = $this->makeUser();

            $this->tenantJson('GET', '/label-print/labels/'.$label->id.'/zpl?copies=2', $user)
                ->assertOk()
                ->assertJson([
                    'label_id' => (int) $label->id,
                    'sscc_18' => (string) $label->sscc_18,
                    'copies' => 2,
                ])
                ->assertJsonPath('zpl', fn (?string $zpl): bool => is_string($zpl) && str_contains($zpl, '^XA'));
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function client_print_job_complete_marks_printed_and_updates_label(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            [$label, $printJob, $pool] = $this->createPrintJobArtifacts();

            $user = $this->makeUser();

            $token = $this->tenantJson('POST', '/label-print/jobs/'.$printJob->id.'/start', $user)
                ->assertOk()
                ->json('token');

            $this->tenantJson('POST', '/label-print/jobs/'.$printJob->id.'/assert', $user, [
                'token' => $token,
            ])->assertOk();

            $this->tenantJson('POST', '/label-print/jobs/'.$printJob->id.'/complete', $user, [
                'status' => 'printed',
                'token' => $token,
            ])
                ->assertOk()
                ->assertJson([
                    'ok' => true,
                    'status' => 'printed',
                ]);

            $printJob->refresh();
            $label->refresh();
            $pool->refresh();

            $this->assertSame(SsccPrintJobStatus::Printed, $printJob->status);
            $this->assertNotNull($printJob->printed_at);
            $this->assertNull($printJob->client_print_token);
            $this->assertSame(SsccLabelPrintStatus::Printed, $label->print_status);
            $this->assertSame(2, $label->printed_copies);
            $this->assertNotNull($label->printed_at);
            $this->assertSame(210167, $pool->last_printed_serial_reference_int);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function client_print_job_complete_is_idempotent_for_already_printed(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            [$label, $printJob] = $this->createPrintJobArtifacts();

            $user = $this->makeUser();

            $token = $this->tenantJson('POST', '/label-print/jobs/'.$printJob->id.'/start', $user)
                ->assertOk()
                ->json('token');

            $this->tenantJson('POST', '/label-print/jobs/'.$printJob->id.'/complete', $user, [
                'status' => 'printed',
                'token' => $token,
            ])->assertOk();

            $attempts = (int) $printJob->fresh()->attempts;

            $this->tenantJson('POST', '/label-print/jobs/'.$printJob->id.'/complete', $user, [
                'status' => 'printed',
                'token' => $token,
            ])
                ->assertOk()
                ->assertJson([
                    'ok' => true,
                    'status' => 'printed',
                    'idempotent' => true,
                ]);

            $this->assertSame($attempts, (int) $printJob->fresh()->attempts);
            $this->assertSame(SsccLabelPrintStatus::Printed, $label->fresh()->print_status);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function client_print_job_complete_rejects_invalid_state_transition(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            [, $printJob] = $this->createPrintJobArtifacts();
            $printJob->update(['status' => SsccPrintJobStatus::Printed, 'printed_at' => now()]);

            $user = $this->makeUser();

            $this->tenantJson('POST', '/label-print/jobs/'.$printJob->id.'/complete', $user, [
                'status' => 'failed',
            ])
                ->assertStatus(422)
                ->assertJsonPath('current_status', SsccPrintJobStatus::Printed->value);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function client_print_job_start_marks_printing_with_token(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            [, $printJob] = $this->createPrintJobArtifacts();
            $user = $this->makeUser();

            $token = $this->tenantJson('POST', '/label-print/jobs/'.$printJob->id.'/start', $user)
                ->assertOk()
                ->assertJson([
                    'ok' => true,
                    'status' => 'printing',
                ])
                ->json('token');

            $this->assertNotEmpty($token);
            $this->assertSame(SsccPrintJobStatus::Printing, $printJob->fresh()->status);
            $this->assertSame($token, $printJob->fresh()->client_print_token);

            $this->tenantJson('POST', '/label-print/jobs/'.$printJob->id.'/start', $user, [
                'token' => $token,
            ])
                ->assertOk()
                ->assertJson([
                    'ok' => true,
                    'status' => 'printing',
                    'idempotent' => true,
                    'token' => $token,
                ]);

            $this->tenantJson('POST', '/label-print/jobs/'.$printJob->id.'/start', $user)
                ->assertStatus(409);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function client_print_job_assert_rejects_superseded_jobs(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            [, $printJob] = $this->createPrintJobArtifacts();
            $user = $this->makeUser();

            $token = $this->tenantJson('POST', '/label-print/jobs/'.$printJob->id.'/start', $user)
                ->assertOk()
                ->json('token');

            $printJob->update([
                'status' => SsccPrintJobStatus::Failed,
                'last_error' => 'Superseded by a newer print request.',
                'client_print_token' => null,
            ]);

            $this->tenantJson('POST', '/label-print/jobs/'.$printJob->id.'/assert', $user, [
                'token' => $token,
            ])->assertStatus(422);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function client_print_job_complete_is_atomic_against_superseded_status(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            [, $printJob] = $this->createPrintJobArtifacts();
            $user = $this->makeUser();

            $token = $this->tenantJson('POST', '/label-print/jobs/'.$printJob->id.'/start', $user)
                ->assertOk()
                ->json('token');

            $printJob->update([
                'status' => SsccPrintJobStatus::Failed,
                'last_error' => 'Superseded by a newer print request.',
                'client_print_token' => null,
            ]);

            $this->tenantJson('POST', '/label-print/jobs/'.$printJob->id.'/complete', $user, [
                'status' => 'printed',
                'token' => $token,
            ])
                ->assertStatus(422);

            $this->assertSame(SsccPrintJobStatus::Failed, $printJob->fresh()->status);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function client_print_job_complete_failed_without_token_cannot_kill_printing_job(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            [, $printJob] = $this->createPrintJobArtifacts();
            $user = $this->makeUser();

            $token = $this->tenantJson('POST', '/label-print/jobs/'.$printJob->id.'/start', $user)
                ->assertOk()
                ->json('token');

            $this->assertSame(SsccPrintJobStatus::Printing, $printJob->fresh()->status);

            $this->tenantJson('POST', '/label-print/jobs/'.$printJob->id.'/complete', $user, [
                'status' => 'failed',
                'error' => 'other session 409',
            ])
                ->assertStatus(422)
                ->assertJsonPath(
                    'message',
                    'Print ownership token is required to fail an in-progress job.',
                );

            $printJob->refresh();
            $this->assertSame(SsccPrintJobStatus::Printing, $printJob->status);
            $this->assertSame($token, $printJob->client_print_token);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function client_print_job_complete_rejects_queue_delivery_mode(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            [, $printJob] = $this->createPrintJobArtifacts();
            $printJob->update(['delivery_mode' => SsccPrintDeliveryMode::Queue]);

            $user = $this->makeUser();

            $this->tenantJson('POST', '/label-print/jobs/'.$printJob->id.'/complete', $user, [
                'status' => 'printed',
            ])
                ->assertStatus(422)
                ->assertJsonPath(
                    'message',
                    'Only client-delivered print jobs can be completed from the browser.',
                );

            $this->assertSame(SsccPrintJobStatus::Queued, $printJob->fresh()->status);
        } finally {
            $this->cleanup($tenant);
        }
    }

    /**
     * @return array{0: SsccLabel}
     */
    private function createLabelArtifacts(): array
    {
        $batch = SsccLabelBatch::query()->create([
            'company_prefix' => '0399988',
            'extension_digit' => '0',
            'allocation_mode' => SsccAllocationMode::Sequential,
            'label_count' => 1,
            'copies_per_label' => 1,
            'status' => SsccLabelBatchStatus::Completed,
        ]);
        $this->batchIds[] = (int) $batch->id;

        $label = SsccLabel::query()->create([
            'batch_id' => $batch->id,
            'sscc_18' => '0039998800002101675',
            'sscc_urn' => 'urn:epc:id:sscc:0399988.00000210167',
            'extension_digit' => '0',
            'company_prefix' => '0399988',
            'serial_reference' => '0000210167',
            'serial_reference_int' => 210167,
            'element_string' => '00039998800002101675',
            'hrt' => '00039998800002101675',
            'ship_to_name' => 'Route Test DC',
            'label_disk' => 'local',
            'label_path' => 'sscc/route-test.pdf',
        ]);
        $this->labelIds[] = (int) $label->id;

        return [$label];
    }

    /**
     * @return array{0: SsccLabel, 1: SsccPrintJob, 2: SsccSerialPool}
     */
    private function createPrintJobArtifacts(): array
    {
        $printer = LabelPrinter::query()->create([
            'name' => 'Complete Job Printer',
            'protocol' => LabelPrinterProtocol::QzTray,
            'settings' => ['client_printer_name' => 'ZDesigner'],
            'enabled' => true,
        ]);
        $this->printerIds[] = (int) $printer->id;

        $batch = SsccLabelBatch::query()->create([
            'company_prefix' => '0399988',
            'extension_digit' => '0',
            'allocation_mode' => SsccAllocationMode::Sequential,
            'label_count' => 1,
            'copies_per_label' => 2,
            'label_printer_id' => $printer->id,
            'send_to_printer' => true,
            'status' => SsccLabelBatchStatus::Completed,
        ]);
        $this->batchIds[] = (int) $batch->id;

        $label = SsccLabel::query()->create([
            'batch_id' => $batch->id,
            'label_printer_id' => $printer->id,
            'sscc_18' => '0039998800002101675',
            'sscc_urn' => 'urn:epc:id:sscc:0399988.00000210167',
            'extension_digit' => '0',
            'company_prefix' => '0399988',
            'serial_reference' => '0000210167',
            'serial_reference_int' => 210167,
            'element_string' => '00039998800002101675',
            'hrt' => '00039998800002101675',
            'label_disk' => 'local',
            'label_path' => 'sscc/complete-test.pdf',
            'print_status' => SsccLabelPrintStatus::Queued,
        ]);
        $this->labelIds[] = (int) $label->id;

        $pool = SsccSerialPool::query()->create([
            'company_prefix' => '0399988',
            'extension_digit' => '0',
            'default_allocation_mode' => SsccAllocationMode::Sequential,
            'last_serial_reference_int' => 210166,
        ]);
        $this->poolIds[] = (int) $pool->id;

        $printJob = SsccPrintJob::query()->create([
            'sscc_label_batch_id' => $batch->id,
            'sscc_label_id' => $label->id,
            'label_printer_id' => $printer->id,
            'copies' => 2,
            'status' => SsccPrintJobStatus::Queued,
            'delivery_mode' => SsccPrintDeliveryMode::Client,
            'queued_at' => now(),
        ]);
        $this->printJobIds[] = (int) $printJob->id;

        return [$label, $printJob, $pool];
    }

    private function tenantJson(string $method, string $uri, User $user, array $data = []): TestResponse
    {
        $this->actingAs($user);

        $path = str_starts_with($uri, '/') ? $uri : '/'.$uri;
        $absolute = 'http://'.self::DEMO2_DOMAIN.$path;

        $server = [
            'HTTP_HOST' => self::DEMO2_DOMAIN,
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
        ];

        $content = json_encode($data, JSON_THROW_ON_ERROR);

        return $this->call(
            strtoupper($method),
            $absolute,
            [],
            [],
            [],
            $server,
            $content,
        );
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

        $this->priorTenantBridge = TenantSettings::forTenant($tenant)->clientPrintBridge();

        return $tenant;
    }

    private function cleanup(Tenant $tenant): void
    {
        if (tenancy()->initialized) {
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

            if ($this->userIds !== []) {
                User::query()->whereIn('id', $this->userIds)->delete();
            }

            if ($this->priorTenantBridge !== null) {
                TenantSettings::forTenant($tenant)
                    ->setClientPrintBridge($this->priorTenantBridge);
                $tenant->save();
            }

            session()->forget(ResolveClientPrintBridge::SESSION_KEY);

            tenancy()->end();
        }

        $this->printJobIds = [];
        $this->labelIds = [];
        $this->batchIds = [];
        $this->printerIds = [];
        $this->poolIds = [];
        $this->userIds = [];
        $this->priorTenantBridge = null;
    }
}
