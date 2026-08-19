<?php

namespace App\Console\Commands;

use App\Actions\Epcis\ReprocessEpcisDocument;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisException;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Exceptions\ExceptionType;
use App\Models\Tenant;
use App\Services\Exceptions\ExceptionService;
use DomainException;
use Illuminate\Console\Command;
use Throwable;

class EvaluateEpcisExceptionsCommand extends Command
{
    protected $signature = 'tracepharma:evaluate-epcis-exceptions
        {--tenant= : Tenant id (required)}
        {--reprocess : Re-run ingest/compliance on parsed|validated|error|received documents}
        {--promote : Promote open epcis_exceptions without a case}
        {--sync : Reprocess inline instead of queueing}
        {--force : Bypass open receiving-session guard on reprocess}
        {--limit=0 : Max documents to reprocess (0 = all)}';

    protected $description = 'Re-evaluate ingested EPCIS documents and/or promote open exception signals to cases';

    public function handle(ReprocessEpcisDocument $reprocess, ExceptionService $exceptions): int
    {
        $tenantId = $this->option('tenant');
        if (! filled($tenantId)) {
            $this->error('The --tenant option is required.');

            return self::FAILURE;
        }

        $tenant = Tenant::query()->find($tenantId);
        if ($tenant === null) {
            $this->error("Tenant not found: {$tenantId}");

            return self::FAILURE;
        }

        $doReprocess = (bool) $this->option('reprocess');
        $doPromote = (bool) $this->option('promote');

        if (! $doReprocess && ! $doPromote) {
            $doReprocess = true;
            $doPromote = true;
        }

        tenancy()->initialize($tenant);

        try {
            $catalogCount = ExceptionType::query()->where('is_active', true)->count();
            $this->info("Active exception types: {$catalogCount}");
            $this->info('Documents: '.EpcisDocument::query()->count());
            $this->info('Open signals: '.EpcisException::query()->where('status', 'open')->count());
            $this->info('Signals without case: '.EpcisException::query()->whereNull('case_id')->count());

            $reprocessed = 0;
            $reprocessFailed = 0;

            if ($doReprocess) {
                $limit = max(0, (int) $this->option('limit'));
                $query = EpcisDocument::query()
                    ->whereIn('status', ['parsed', 'validated', 'error', 'received'])
                    ->orderBy('id');

                if ($limit > 0) {
                    $query->limit($limit);
                }

                $ids = $query->pluck('id');
                $this->info('Reprocessing '.$ids->count().' document(s)...');

                foreach ($ids as $id) {
                    $document = EpcisDocument::query()->find($id);
                    if ($document === null) {
                        continue;
                    }

                    try {
                        $reprocess->handle(
                            $document,
                            sync: (bool) $this->option('sync'),
                            force: (bool) $this->option('force'),
                            authorizeExceptionsRole: false,
                        );
                        $reprocessed++;
                        $this->line("  reprocessed document #{$id} → ".$document->fresh()->status);
                    } catch (DomainException $e) {
                        $reprocessFailed++;
                        $this->warn("  skip document #{$id}: ".$e->getMessage());
                    } catch (Throwable $e) {
                        $reprocessFailed++;
                        $this->error("  fail document #{$id}: ".$e->getMessage());
                    }
                }
            }

            $promoted = 0;
            $promoteFailed = 0;

            if ($doPromote) {
                $signals = EpcisException::query()
                    ->whereNull('case_id')
                    ->where('status', 'open')
                    ->orderBy('id')
                    ->get();

                $this->info('Promoting '.$signals->count().' open signal(s) without a case...');

                foreach ($signals as $signal) {
                    try {
                        $case = $exceptions->createFromSignal($signal);
                        $promoted++;
                        $this->line(
                            "  signal #{$signal->getKey()} ({$signal->exception_type}) → case #{$case->getKey()} [{$case->type?->code}]",
                        );
                    } catch (Throwable $e) {
                        $promoteFailed++;
                        $this->error("  fail signal #{$signal->getKey()}: ".$e->getMessage());
                    }
                }
            }

            $this->newLine();
            $this->info("Done. reprocessed={$reprocessed} reprocess_failed={$reprocessFailed} promoted={$promoted} promote_failed={$promoteFailed}");
            $this->info('Open signals now: '.EpcisException::query()->where('status', 'open')->count());
            $this->info('Cases total: '.ExceptionCase::query()->count());

            return ($reprocessFailed + $promoteFailed) > 0 ? self::FAILURE : self::SUCCESS;
        } finally {
            tenancy()->end();
        }
    }
}
