<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ExceptionStatus;
use App\Enums\TenantRole;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\StrictRejectionDigest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Exceptions\RoleDoesNotExist;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

class SendStrictRejectionDigestCommand extends Command
{
    protected $signature = 'tracepharma:exception-digest
                            {--tenant= : Limit to a single tenant id}
                            {--dry-run : Report what would be sent without notifying owners}';

    protected $description = 'Notify tenant owners of open EPCIS validation cases still awaiting review';

    /** @var list<string> */
    private const DIGEST_TYPE_CODES = [
        'INGESTION_PARSE_ERROR',
        'INTERNAL_VALIDATION_FAILED',
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $tenantId = $this->option('tenant');

        $processed = 0;
        $notified = 0;
        $failed = 0;

        $query = Tenant::query()->where('status', 'active')->orderBy('name');

        if (is_string($tenantId) && $tenantId !== '') {
            $query->where('id', $tenantId);
        }

        $query->cursor()->each(function (Tenant $tenant) use (
            $dryRun,
            &$processed,
            &$notified,
            &$failed,
        ): void {
            $processed++;

            try {
                $tenant->run(function () use ($tenant, $dryRun, &$notified): void {
                    $exceptions = ExceptionCase::query()
                        ->whereHas('type', fn ($query) => $query->whereIn('code', self::DIGEST_TYPE_CODES))
                        ->whereIn('status', [
                            ExceptionStatus::New->value,
                            ExceptionStatus::Triaged->value,
                            ExceptionStatus::Investigating->value,
                        ])
                        ->orderByDesc('created_at')
                        ->get(['id', 'title', 'created_at']);

                    $openCount = $exceptions->count();

                    if ($openCount === 0) {
                        return;
                    }

                    try {
                        $owners = User::role(TenantRole::Owner->value)->get();
                    } catch (RoleDoesNotExist) {
                        $owners = collect();
                    }

                    $this->line(sprintf(
                        '%s%s: open=%d owners=%d',
                        $dryRun ? '[dry-run] ' : '',
                        $tenant->name,
                        $openCount,
                        $owners->count(),
                    ));

                    if ($dryRun || $owners->isEmpty()) {
                        return;
                    }

                    $payload = $exceptions->map(fn (ExceptionCase $exception): array => [
                        'id' => $exception->id,
                        'title' => (string) $exception->title,
                        'created_at' => $exception->created_at?->toIso8601String(),
                    ])->all();

                    Notification::send(
                        $owners,
                        new StrictRejectionDigest($openCount, $payload, $tenant->id),
                    );

                    $notified++;
                });
            } catch (\Throwable $exception) {
                $failed++;
                $this->error("{$tenant->name}: {$exception->getMessage()}");
            } finally {
                if (tenancy()->initialized) {
                    tenancy()->end();
                }
            }
        });

        $this->info(sprintf(
            'Validation digest complete. tenants=%d notified=%d failed=%d',
            $processed,
            $notified,
            $failed,
        ));

        return $failed > 0 ? SymfonyCommand::FAILURE : SymfonyCommand::SUCCESS;
    }
}
