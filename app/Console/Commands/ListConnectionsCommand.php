<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ConnectionApprovalRequest;
use Illuminate\Console\Command;

class ListConnectionsCommand extends Command
{
    protected $signature = 'connections:list
        {--tenant= : Limit to a single tenant id}
        {--direction= : inbound or outbound}
        {--status= : pending, approved, rejected}';

    protected $description = 'List tenant connection requests (central approval queue view) with optional filters.';

    public function handle(): int
    {
        $requests = ConnectionApprovalRequest::query()
            ->when($this->option('tenant'), fn ($q, $t) => $q->where('tenant_id', $t))
            ->when($this->option('direction'), fn ($q, $d) => $q->where('direction', $d))
            ->when($this->option('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderBy('tenant_id')
            ->orderBy('direction')
            ->orderBy('connection_id')
            ->get();

        if ($requests->isEmpty()) {
            $this->warn('No connection requests matched.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Tenant', 'Dir', 'Conn', 'Name', 'Provider', 'Transport', 'Counterparty', 'Status', 'Reviewed'],
            $requests->map(static fn (ConnectionApprovalRequest $r): array => [
                $r->getKey(),
                $r->tenant_id,
                $r->direction,
                $r->connection_id,
                $r->connection_name,
                $r->provider,
                $r->transport,
                $r->counterparty,
                $r->status?->value ?? (string) $r->status,
                $r->reviewed_at?->toDateTimeString() ?? '-',
            ])->all(),
        );

        $this->info("{$requests->count()} connection request(s).");

        return self::SUCCESS;
    }
}
