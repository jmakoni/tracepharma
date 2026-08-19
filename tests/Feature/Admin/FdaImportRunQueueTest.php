<?php

namespace Tests\Feature\Admin;

use App\Enums\AdminRole;
use App\Filament\Admin\Resources\Fda\FdaImportRuns\Pages\ListFdaImportRuns;
use App\Jobs\ImportFdaDatasetJob;
use App\Jobs\SyncTenantAtpLicensesFromFda;
use App\Models\Admin;
use App\Models\Fda\FdaImportRun;
use App\Support\Auth\AdminRoleSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class FdaImportRunQueueTest extends TestCase
{
    private const SOURCE_PATH = 'ssor-sync-import-runs';

    /** @var list<int> */
    private array $adminIds = [];

    /** @var list<int> */
    private array $runIds = [];

    protected function tearDown(): void
    {
        if ($this->runIds !== []) {
            FdaImportRun::query()->whereIn('id', $this->runIds)->delete();
        }

        FdaImportRun::query()->where('source_path', self::SOURCE_PATH)->delete();

        if ($this->adminIds !== []) {
            DB::table('model_has_roles')
                ->where('model_type', Admin::class)
                ->whereIn('model_id', $this->adminIds)
                ->delete();
            DB::table('admins')->whereIn('id', $this->adminIds)->delete();
        }

        parent::tearDown();
    }

    #[Test]
    public function import_runs_show_the_latest_run_per_source(): void
    {
        $decrsRun = $this->seedRun('decrs', rowsRead: 4242);
        $wddRun = $this->seedRun('wdd', rowsRead: 17, complete: false);
        $this->actAsAdmin(AdminRole::PlatformAdmin);

        Livewire::test(ListFdaImportRuns::class)
            ->assertSuccessful()
            ->assertSee('DECRS')
            ->assertSee(number_format(4242))
            ->assertSee('WDD')
            ->assertSee('Never')
            ->assertSee('NDC')
            ->assertSee('Drugs@FDA')
            ->assertCanSeeTableRecords([$decrsRun, $wddRun])
            ->assertTableColumnStateSet('rows_read', 4242, $decrsRun)
            ->assertTableColumnStateSet('rows_read', 17, $wddRun);
    }

    #[Test]
    public function platform_admin_queues_decrs_and_openfda_imports(): void
    {
        Bus::fake();
        $this->actAsAdmin(AdminRole::PlatformAdmin);

        Livewire::test(ListFdaImportRuns::class)
            ->callAction(TestAction::make('importDecrs'), ['fresh_download' => false])
            ->callAction(TestAction::make('importOpenFdaNdc'), ['fresh_download' => true])
            ->callAction(TestAction::make('importOpenFdaDrugsFda'), ['fresh_download' => false]);

        Bus::assertDispatched(ImportFdaDatasetJob::class, fn (ImportFdaDatasetJob $job): bool => $job->command === 'tracepharma:import-fda-decrs'
            && $job->parameters === []
            && $job->queue === 'fda');
        Bus::assertDispatched(ImportFdaDatasetJob::class, fn (ImportFdaDatasetJob $job): bool => $job->command === 'tracepharma:import-openfda-ndc'
            && $job->parameters === ['--fresh-download' => true]
            && $job->queue === 'fda');
        Bus::assertDispatched(ImportFdaDatasetJob::class, fn (ImportFdaDatasetJob $job): bool => $job->command === 'tracepharma:import-openfda-drugsfda'
            && $job->parameters === []
            && $job->queue === 'fda');

        $this->assertSame(3600, (new ImportFdaDatasetJob('tracepharma:import-fda-decrs'))->timeout);
        $this->assertSame(7200, (new ImportFdaDatasetJob('tracepharma:import-fda-decrs'))->uniqueFor);
        $this->assertSame(3660, (int) config('horizon.defaults.supervisor-fda.timeout'));
        $this->assertSame(3900, (int) config('queue.connections.redis.retry_after'));
    }

    #[Test]
    public function support_admin_cannot_queue_imports_but_can_see_last_runs(): void
    {
        Bus::fake();
        $this->seedRun('openfda_ndc', rowsRead: 88);
        $this->actAsAdmin(AdminRole::Support);

        Livewire::test(ListFdaImportRuns::class)
            ->assertSuccessful()
            ->assertSee('NDC')
            ->assertSee('88')
            ->assertActionHidden(TestAction::make('importDecrs'))
            ->assertActionHidden(TestAction::make('importOpenFdaNdc'))
            ->assertActionHidden(TestAction::make('importOpenFdaDrugsFda'))
            ->mountAction(TestAction::make('importDecrs'))
            ->assertActionNotMounted();

        Bus::assertNotDispatched(ImportFdaDatasetJob::class);
    }

    #[Test]
    public function duplicate_import_shows_warning_instead_of_success(): void
    {
        Bus::fake();
        $this->actAsAdmin(AdminRole::PlatformAdmin);

        $job = new ImportFdaDatasetJob('tracepharma:import-fda-decrs');
        $this->assertTrue(app(UniqueLock::class)->acquire($job));

        try {
            Livewire::test(ListFdaImportRuns::class)
                ->callAction(TestAction::make('importDecrs'), ['fresh_download' => false])
                ->assertNotified('Import already running');

            Bus::assertNotDispatched(ImportFdaDatasetJob::class);
        } finally {
            app(UniqueLock::class)->release($job);
        }
    }

    #[Test]
    public function dispatch_if_idle_returns_false_when_the_unique_lock_is_already_held(): void
    {
        Bus::fake();

        $job = new ImportFdaDatasetJob('tracepharma:import-fda-decrs');
        $this->assertTrue(app(UniqueLock::class)->acquire($job));

        try {
            $this->assertFalse(ImportFdaDatasetJob::dispatchIfIdle('tracepharma:import-fda-decrs'));
            Bus::assertNotDispatched(ImportFdaDatasetJob::class);
        } finally {
            app(UniqueLock::class)->release($job);
        }
    }

    #[Test]
    public function dispatch_if_idle_acquires_the_unique_lock_before_queueing(): void
    {
        Bus::fake();

        $this->assertTrue(ImportFdaDatasetJob::dispatchIfIdle('tracepharma:import-fda-decrs'));

        Bus::assertDispatched(ImportFdaDatasetJob::class, fn (ImportFdaDatasetJob $job): bool => $job->command === 'tracepharma:import-fda-decrs');

        $job = new ImportFdaDatasetJob('tracepharma:import-fda-decrs');
        $this->assertFalse(app(UniqueLock::class)->acquire($job));

        app(UniqueLock::class)->release($job);
    }

    #[Test]
    public function import_job_throws_when_the_artisan_command_fails(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('exit code');

        (new ImportFdaDatasetJob('tracepharma:import-openfda-ndc', [
            '--stage' => 'bogus',
        ]))->handle();
    }

    #[Test]
    public function import_job_chains_tenant_atp_sync_after_successful_wdd_promote(): void
    {
        Queue::fake();

        Artisan::shouldReceive('call')
            ->once()
            ->with(ImportFdaDatasetJob::WDD_COMMAND, ['--promote' => true])
            ->andReturn(0);

        (new ImportFdaDatasetJob(ImportFdaDatasetJob::WDD_COMMAND, [
            '--promote' => true,
        ]))->handle();

        Queue::assertPushed(SyncTenantAtpLicensesFromFda::class);
    }

    #[Test]
    public function wdd_cli_command_respects_the_import_job_execution_lock(): void
    {
        $job = new ImportFdaDatasetJob(ImportFdaDatasetJob::WDD_COMMAND);
        $lock = ImportFdaDatasetJob::tryAcquireExecutionLock(ImportFdaDatasetJob::WDD_COMMAND);
        $this->assertNotNull($lock);

        try {
            $this->artisan(ImportFdaDatasetJob::WDD_COMMAND, [
                '--path' => base_path('tests/fixtures/fda/wdd_3pl_sample.txt'),
            ])
                ->assertFailed()
                ->expectsOutputToContain('Another WDD/3PL import is already running or queued.');
        } finally {
            ImportFdaDatasetJob::releaseExecutionLock(ImportFdaDatasetJob::WDD_COMMAND, $lock);
        }
    }

    #[Test]
    public function decrs_cli_command_respects_the_import_job_execution_lock(): void
    {
        $lock = ImportFdaDatasetJob::tryAcquireExecutionLock(ImportFdaDatasetJob::DECRS_COMMAND);
        $this->assertNotNull($lock);

        try {
            $this->artisan(ImportFdaDatasetJob::DECRS_COMMAND, [
                '--path' => base_path('tests/fixtures/fda/decrs_sample.zip'),
            ])
                ->assertFailed()
                ->expectsOutputToContain('Another DECRS import is already running or queued.');
        } finally {
            ImportFdaDatasetJob::releaseExecutionLock(ImportFdaDatasetJob::DECRS_COMMAND, $lock);
        }
    }

    #[Test]
    public function openfda_ndc_cli_command_respects_the_import_job_execution_lock(): void
    {
        $lock = ImportFdaDatasetJob::tryAcquireExecutionLock(ImportFdaDatasetJob::OPENFDA_NDC_COMMAND);
        $this->assertNotNull($lock);

        try {
            $this->artisan(ImportFdaDatasetJob::OPENFDA_NDC_COMMAND, [
                '--path' => base_path('tests/fixtures/openfda/drug-ndc-sample.json'),
                '--stage' => 'partners',
            ])
                ->assertFailed()
                ->expectsOutputToContain('Another openFDA NDC import is already running or queued.');
        } finally {
            ImportFdaDatasetJob::releaseExecutionLock(ImportFdaDatasetJob::OPENFDA_NDC_COMMAND, $lock);
        }
    }

    #[Test]
    public function import_job_is_unique_per_command(): void
    {
        $ndcJob = new ImportFdaDatasetJob('tracepharma:import-openfda-ndc');
        $drugsFdaJob = new ImportFdaDatasetJob('tracepharma:import-openfda-drugsfda');

        $this->assertInstanceOf(ShouldBeUnique::class, $ndcJob);
        $this->assertSame('tracepharma:import-openfda-ndc', $ndcJob->uniqueId());
        $this->assertSame('tracepharma:import-openfda-drugsfda', $drugsFdaJob->uniqueId());
        $this->assertNotSame($ndcJob->uniqueId(), $drugsFdaJob->uniqueId());
    }

    private function seedRun(string $source, int $rowsRead, bool $complete = true): FdaImportRun
    {
        $startedAt = now()->addHour();

        $run = FdaImportRun::query()->create([
            'source' => $source,
            'source_path' => self::SOURCE_PATH,
            'rows_read' => $rowsRead,
            'rows_inserted' => 1,
            'rows_updated' => 0,
            'rows_skipped' => 0,
            'rows_sent_to_review' => 0,
            'started_at' => $startedAt,
            'completed_at' => $complete ? $startedAt->copy()->addMinutes(10) : null,
            'duration_ms' => $complete ? 600_000 : null,
        ]);
        $this->runIds[] = (int) $run->id;

        return $run;
    }

    private function actAsAdmin(AdminRole $role): Admin
    {
        app(AdminRoleSeeder::class)->seed();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $admin = Admin::factory()->create();
        $admin->assignRole($role->value);
        $this->adminIds[] = (int) $admin->getKey();

        $this->actingAs($admin, 'admin');
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return $admin;
    }
}
