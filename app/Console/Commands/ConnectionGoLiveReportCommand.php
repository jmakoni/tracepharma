<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesTenantConnections;
use App\Support\Integrations\GoLiveEvidencePack;
use App\Support\Tenancy\TenantRunner;
use Illuminate\Console\Command;
use RuntimeException;

class ConnectionGoLiveReportCommand extends Command
{
    use ResolvesTenantConnections;

    protected $signature = 'connections:go-live-report
        {tenant : Tenant ID}
        {connection : Connection ID}
        {--direction=outbound : inbound or outbound}
        {--output= : Write the Markdown evidence pack to this path instead of stdout}';

    protected $description = 'Render a Markdown go-live evidence pack (checklist, sign-off, traffic) for a connection';

    public function handle(GoLiveEvidencePack $pack): int
    {
        try {
            $tenant = $this->resolveTenantOrFail((string) $this->argument('tenant'));
            $direction = (string) $this->option('direction');
            [$connection] = $this->resolveConnectionOrFail($tenant, (int) $this->argument('connection'), $direction);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $markdown = TenantRunner::run($tenant, static fn (): string => $pack->render($tenant, $connection));

        $output = $this->option('output');

        if (is_string($output) && $output !== '') {
            file_put_contents($output, $markdown);
            $this->info("Evidence pack written to {$output}");
        } else {
            $this->line($markdown);
        }

        return self::SUCCESS;
    }
}
