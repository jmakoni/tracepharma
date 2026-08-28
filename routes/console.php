<?php

use App\Jobs\ImportFdaDatasetJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('tracepharma:recalc-fda-establishment-registration')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->name('fda-establishment-registration-recalc');

Schedule::call(function (): void {
    if (! ImportFdaDatasetJob::dispatchIfIdle('tracepharma:import-fda-decrs', [
        '--fresh-download' => true,
    ])) {
        Log::warning('FDA DECRS scheduled import skipped: another import is already running.');
    }
})
    ->name('fda-decrs-monthly-refresh')
    ->monthlyOn(1, '03:30')
    ->withoutOverlapping();

/**
 * WDD/3PL licenses expire on state timetables and the FDA republishes the report
 * between the monthly DECRS drops, so the ATP chain runs weekly: a month-old
 * snapshot can authorize a shipment against a license that already lapsed.
 * Triage resolved in the unmatched queue is picked up by the next run.
 *
 * Successful promote dispatches per-tenant ATP sync from ImportFdaDatasetJob so
 * tenants always read promoted catalog data (no fixed follow-up slot).
 *
 * Manual `tracepharma:import-fda-wdd-3pl` shares the same overlap lock as the job.
 */
Schedule::call(function (): void {
    if (! ImportFdaDatasetJob::dispatchIfIdle(ImportFdaDatasetJob::WDD_COMMAND, [
        '--fresh-download' => true,
        '--promote' => true,
    ])) {
        Log::warning('FDA WDD/3PL scheduled import skipped: another import is already running.');
    }
})
    ->name('fda-wdd-3pl-weekly-refresh')
    ->weeklyOn(0, '04:00')
    ->withoutOverlapping();

/**
 * FDA packaging hygiene: de-duplicate alternate NDC spellings first so the
 * NDC-11 backfill can claim the surviving row. Tenant/FDA search is SQL, so
 * the retired catalog Scout reindex stays off the schedule.
 */
Schedule::call(function (): void {
    foreach (['fda:dedupe-package-ndc', 'fda:backfill-ndc11'] as $command) {
        Artisan::call($command);
    }
})
    ->name('catalog-maintenance-weekly')
    ->weeklyOn(0, '03:00')
    ->withoutOverlapping();

Schedule::command('epcis:poll-sftp')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->name('epcis-poll-sftp-inbound');

Schedule::command('epcis:fail-stale-jobs')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->name('epcis-fail-stale-jobs');

Schedule::command('sscc:check-pool-levels')
    ->daily()
    ->withoutOverlapping()
    ->name('sscc-check-pool-levels');

Schedule::command('sscc:fail-stale-client-print-jobs')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->name('sscc-fail-stale-client-print-jobs');

Schedule::command('exceptions:check-sla')
    ->hourly()
    ->withoutOverlapping()
    ->name('exceptions-check-sla');

Schedule::command('tracing:check-sla')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->name('tracing-check-sla');

Schedule::command('compliance:alert-license-expiry')
    ->dailyAt('07:00')
    ->withoutOverlapping()
    ->name('atp-license-expiry-alert');

Schedule::command('compliance:alert-center-digest')
    ->dailyAt('07:30')
    ->withoutOverlapping()
    ->name('compliance-alert-center-digest');

Schedule::command('tracepharma:exception-digest')
    ->dailyAt('08:00')
    ->withoutOverlapping()
    ->name('epcis-validation-digest');

Schedule::command('exceptions:notify-aging-suppliers')
    ->dailyAt('08:30')
    ->withoutOverlapping()
    ->name('aging-supplier-exception-notify');

Schedule::command('epcis:emit-pending-mdn-signals')
    ->hourly()
    ->withoutOverlapping()
    ->name('epcis-emit-pending-mdn-signals');

Schedule::command('tracepharma:tenant-health-alert')
    ->hourly()
    ->withoutOverlapping()
    ->name('tenant-health-alert');

Schedule::command('tracepharma:scout-health --alert')
    ->hourly()
    ->withoutOverlapping()
    ->when(fn (): bool => config('scout.driver') === 'meilisearch')
    ->name('scout-health-alert'); // probes first active tenant index when --tenant is omitted

/**
 * Tenant schema drift: aggregation_links.established_by_event_id must not CASCADE
 * when epcis_events are pruned. Detect daily; alert ops/admins; manual --fix only.
 */
Schedule::command('tracepharma:doctor-aggregation-link-fk --alert')
    ->dailyAt('05:00')
    ->withoutOverlapping()
    ->name('aggregation-link-fk-doctor');
