<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Jobs\ImportFdaDatasetJob;
use Illuminate\Bus\UniqueLock;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CatalogMaintenanceScheduleTest extends TestCase
{
    #[Test]
    public function catalog_maintenance_is_scheduled_weekly_alongside_the_weekly_wdd_chain(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('catalog-maintenance-weekly')
            ->assertSuccessful();

        $this->assertSame('0 3 * * 0', $this->maintenanceEvent()->expression);

        $expressions = collect(app(Schedule::class)->events())
            ->mapWithKeys(fn (Event $event): array => [(string) $event->description => $event->expression]);

        // Licenses expire on state timetables, so the WDD import must run weekly on Sunday.
        $this->assertSame(
            '0 4 * * 0',
            $expressions->get('fda-wdd-3pl-weekly-refresh'),
            'The WDD/3PL import must run weekly on Sunday.',
        );
        $this->assertFalse(
            $expressions->has('tenant-atp-sync-after-wdd'),
            'Tenant ATP sync chains from the WDD import job after promote, not a fixed slot.',
        );
        $this->assertSame(
            '30 3 1 * *',
            $expressions->get('fda-decrs-monthly-refresh'),
            'DECRS stays on its monthly slot.',
        );
        $this->assertFalse(
            $expressions->has('fda-wdd-3pl-monthly-refresh'),
            'The monthly WDD slot must be gone, not duplicated.',
        );
        $this->assertFalse(
            $expressions->has('map-fda-registry-to-catalog'),
            'The retired catalog map must not stay on the schedule.',
        );

        $decrsEvent = collect(app(Schedule::class)->events())
            ->first(fn (Event $event): bool => $event->description === 'fda-decrs-monthly-refresh');
        $this->assertNotNull($decrsEvent);
        $this->assertStringNotContainsString(
            'tracepharma:import-fda-decrs',
            (string) ($decrsEvent->command ?? ''),
        );

        $wddEvent = collect(app(Schedule::class)->events())
            ->first(fn (Event $event): bool => $event->description === 'fda-wdd-3pl-weekly-refresh');
        $this->assertNotNull($wddEvent);
        $this->assertStringNotContainsString(
            'tracepharma:import-fda-wdd-3pl',
            (string) ($wddEvent->command ?? ''),
        );
    }

    #[Test]
    public function monthly_decrs_schedule_queues_the_same_job_as_admin_import(): void
    {
        Bus::fake();

        $event = collect(app(Schedule::class)->events())
            ->first(fn (Event $event): bool => $event->description === 'fda-decrs-monthly-refresh');
        $this->assertNotNull($event);
        $event->run($this->app);

        Bus::assertDispatched(ImportFdaDatasetJob::class, fn (ImportFdaDatasetJob $job): bool => $job->command === 'tracepharma:import-fda-decrs'
            && ($job->parameters['--fresh-download'] ?? false) === true
            && $job->queue === 'fda');
    }

    #[Test]
    public function monthly_decrs_schedule_respects_the_import_job_unique_lock(): void
    {
        Bus::fake();
        $job = new ImportFdaDatasetJob('tracepharma:import-fda-decrs', ['--fresh-download' => true]);
        $this->assertTrue(app(UniqueLock::class)->acquire($job));

        try {
            $event = collect(app(Schedule::class)->events())
                ->first(fn (Event $event): bool => $event->description === 'fda-decrs-monthly-refresh');
            $this->assertNotNull($event);
            $event->run($this->app);

            Bus::assertNotDispatched(ImportFdaDatasetJob::class);
        } finally {
            app(UniqueLock::class)->release($job);
        }
    }

    #[Test]
    public function weekly_wdd_schedule_queues_the_same_job_as_admin_import(): void
    {
        Bus::fake();

        $event = collect(app(Schedule::class)->events())
            ->first(fn (Event $event): bool => $event->description === 'fda-wdd-3pl-weekly-refresh');
        $this->assertNotNull($event);
        $event->run($this->app);

        Bus::assertDispatched(ImportFdaDatasetJob::class, fn (ImportFdaDatasetJob $job): bool => $job->command === 'tracepharma:import-fda-wdd-3pl'
            && ($job->parameters['--fresh-download'] ?? false) === true
            && ($job->parameters['--promote'] ?? false) === true
            && $job->queue === 'fda');
    }

    #[Test]
    public function weekly_wdd_schedule_respects_the_import_job_unique_lock(): void
    {
        Bus::fake();
        $job = new ImportFdaDatasetJob('tracepharma:import-fda-wdd-3pl', [
            '--fresh-download' => true,
            '--promote' => true,
        ]);
        $this->assertTrue(app(UniqueLock::class)->acquire($job));

        try {
            $event = collect(app(Schedule::class)->events())
                ->first(fn (Event $event): bool => $event->description === 'fda-wdd-3pl-weekly-refresh');
            $this->assertNotNull($event);
            $event->run($this->app);

            Bus::assertNotDispatched(ImportFdaDatasetJob::class);
        } finally {
            app(UniqueLock::class)->release($job);
        }
    }

    /**
     * De-duplicating FDA packages has to happen before NDC-11 values are recomputed.
     * Catalog Scout reindex stays callable but is not the weekly SoR.
     */
    #[Test]
    public function catalog_maintenance_runs_fda_dedupe_then_ndc11_backfill(): void
    {
        $event = $this->maintenanceEvent();

        /** @var list<string> $ran */
        $ran = [];

        Artisan::shouldReceive('call')
            ->times(2)
            ->andReturnUsing(function (string $command) use (&$ran): int {
                $ran[] = $command;

                return 0;
            });

        $event->run($this->app);

        $this->assertSame([
            'fda:dedupe-package-ndc',
            'fda:backfill-ndc11',
        ], $ran);
    }

    private function maintenanceEvent(): Event
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn (Event $event): bool => $event->description === 'catalog-maintenance-weekly');

        $this->assertNotNull($event, 'catalog-maintenance-weekly is not scheduled.');

        return $event;
    }
}
