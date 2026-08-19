<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

class ImportFdaDatasetJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const WDD_COMMAND = 'tracepharma:import-fda-wdd-3pl';

    public const DECRS_COMMAND = 'tracepharma:import-fda-decrs';

    public const OPENFDA_NDC_COMMAND = 'tracepharma:import-openfda-ndc';

    public const OPENFDA_DRUGSFDA_COMMAND = 'tracepharma:import-openfda-drugsfda';

    private static ?string $executingCommand = null;

    public int $tries = 1;

    public int $timeout = 3600;

    public int $uniqueFor = 7200;

    /**
     * @param  array<string, mixed>  $parameters
     */
    public function __construct(
        public readonly string $command,
        public readonly array $parameters = [],
    ) {
        $this->onQueue('fda');
    }

    public function uniqueId(): string
    {
        return $this->command;
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->uniqueId()))->expireAfter($this->timeout),
        ];
    }

    public function handle(): void
    {
        self::$executingCommand = $this->command;

        try {
            $exit = Artisan::call($this->command, $this->parameters);

            if ($exit !== SymfonyCommand::SUCCESS) {
                throw new RuntimeException(
                    "FDA import {$this->command} failed (exit code {$exit}).",
                );
            }

            if ($this->shouldChainTenantAtpSync()) {
                SyncTenantAtpLicensesFromFda::dispatchForAllTenants();
            }
        } finally {
            self::$executingCommand = null;
        }
    }

    public static function isExecuting(string $command): bool
    {
        return self::$executingCommand === $command;
    }

    private function shouldChainTenantAtpSync(): bool
    {
        return $this->command === self::WDD_COMMAND
            && ($this->parameters['--promote'] ?? false) === true;
    }

    /**
     * Queue the import when no identical job is already queued or running.
     *
     * @param  array<string, mixed>  $parameters
     */
    public static function dispatchIfIdle(string $command, array $parameters = []): bool
    {
        $dispatched = Cache::lock('fda-dispatch:'.$command, 10)->block(5, function () use ($command, $parameters): bool {
            $job = new static($command, $parameters);
            $overlap = self::overlapMiddleware($command);

            if (self::lockIsHeld($overlap->getLockKey($job), $job->timeout)) {
                return false;
            }

            $uniqueLock = app(UniqueLock::class);

            if (! $uniqueLock->acquire($job)) {
                return false;
            }

            app(Dispatcher::class)->dispatch($job);

            return true;
        });

        return $dispatched === true;
    }

    /**
     * Acquire the same overlap + unique locks used by queued imports.
     */
    public static function tryAcquireExecutionLock(string $command): ?Lock
    {
        $job = new static($command);
        $overlap = self::overlapMiddleware($command);
        $lock = Cache::lock($overlap->getLockKey($job), $job->timeout);

        if (! $lock->get()) {
            return null;
        }

        if (! app(UniqueLock::class)->acquire($job)) {
            $lock->release();

            return null;
        }

        return $lock;
    }

    public static function releaseExecutionLock(string $command, Lock $overlapLock): void
    {
        app(UniqueLock::class)->release(new static($command));
        $overlapLock->release();
    }

    private static function overlapMiddleware(string $command): WithoutOverlapping
    {
        $job = new static($command);

        return (new WithoutOverlapping($job->uniqueId()))->expireAfter($job->timeout);
    }

    private static function lockIsHeld(string $key, int $seconds): bool
    {
        $lock = Cache::lock($key, $seconds);

        if ($lock->get()) {
            $lock->release();

            return false;
        }

        return true;
    }
}
