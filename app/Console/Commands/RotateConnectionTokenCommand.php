<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesTenantConnections;
use App\Models\InboundConnection;
use App\Models\OutboundConnection;
use App\Models\Tenant;
use App\Support\Tenancy\TenantRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use RuntimeException;

class RotateConnectionTokenCommand extends Command
{
    use ResolvesTenantConnections;

    protected $signature = 'connections:rotate-token
        {tenant : Tenant id}
        {connection : Connection id}
        {--direction=inbound : inbound or outbound}
        {--set-token= : Outbound only: store a partner-issued token instead of generating one}
        {--show : Print the generated inbound token to the console}';

    protected $description = 'Rotate a connection credential. Inbound tokens are regenerated (instant cutover); outbound tokens are partner-issued and stored via --set-token.';

    public function handle(): int
    {
        try {
            $tenant = $this->resolveTenantOrFail((string) $this->argument('tenant'));
            [$connection, $direction] = $this->resolveConnectionOrFail(
                $tenant,
                $this->argument('connection'),
                (string) $this->option('direction'),
            );
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($connection instanceof InboundConnection) {
            return $this->rotateInbound($tenant, $connection);
        }

        return $this->rotateOutbound($tenant, $connection);
    }

    private function rotateInbound(Tenant $tenant, InboundConnection $connection): int
    {
        $newToken = (string) Str::uuid();

        TenantRunner::run($tenant, static function () use ($connection, $newToken): void {
            $connection->forceFill(['inbound_token' => $newToken])->save();

            activity()
                ->performedOn($connection)
                ->log('inbound token rotated via CLI');
        });

        // Inbound tokens authenticate the partner against our endpoint: the old
        // token stops working immediately (no overlap is possible on a single
        // token field), so the partner must be given the new value now.
        if ((bool) $this->option('show')) {
            $this->info("New inbound token: {$newToken}");
        } else {
            $this->info('Inbound token rotated (pass --show to print it).');
        }

        $this->warn('Instant cutover: the previous token is no longer accepted. Share the new token with the sender.');

        return self::SUCCESS;
    }

    private function rotateOutbound(Tenant $tenant, OutboundConnection $connection): int
    {
        $token = $this->option('set-token');

        if (! is_string($token) || trim($token) === '') {
            $this->error('Outbound tokens authenticate TracePharma to the partner — the partner issues them. Re-run with --set-token=<partner-issued token>.');

            return self::FAILURE;
        }

        $token = trim($token);

        TenantRunner::run($tenant, static function () use ($connection, $token): void {
            $credentials = $connection->credentials ?? [];
            $credentials['webhook_token'] = $token;
            $connection->forceFill(['credentials' => $credentials])->save();

            activity()
                ->performedOn($connection)
                ->log('outbound webhook token updated via CLI');
        });

        $this->info("Outbound token stored for [{$connection->name}]. Sends pick it up immediately.");

        return self::SUCCESS;
    }
}
