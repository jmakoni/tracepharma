<?php

namespace App\Console\Commands;

use App\Enums\InboundTransport;
use App\Jobs\PollSftpInboundConnection;
use App\Models\InboundConnection;
use App\Models\Tenant;
use App\Support\Tenancy\TenantAccess;
use App\Support\Tenancy\TenantKillSwitches;
use Illuminate\Console\Command;

class PollSftpInboundConnections extends Command
{
    protected $signature = 'epcis:poll-sftp';

    protected $description = 'Dispatch SFTP polling jobs for active inbound connections';

    public function handle(): int
    {
        $count = 0;

        Tenant::query()
            ->whereHas('domains')
            ->cursor()
            ->each(function (Tenant $tenant) use (&$count): void {
                if (! TenantAccess::isActive($tenant)) {
                    return;
                }

                if (TenantKillSwitches::forTenant($tenant)->inboundEpcisKilled()) {
                    return;
                }

                $tenant->run(function () use (&$count, $tenant): void {
                    InboundConnection::query()
                        ->where('is_active', true)
                        ->where('transport', InboundTransport::Sftp)
                        ->cursor()
                        ->each(function (InboundConnection $connection) use (&$count, $tenant): void {
                            PollSftpInboundConnection::dispatch($connection->id, $tenant->id);
                            $count++;
                        });
                });
            });

        $this->info("Dispatched {$count} SFTP inbound poll job(s).");

        return self::SUCCESS;
    }
}
