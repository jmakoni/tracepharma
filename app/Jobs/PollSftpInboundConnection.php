<?php

namespace App\Jobs;

use App\Enums\InboundTransport;
use App\Models\InboundConnection;
use App\Models\Tenant;
use App\Services\Integrations\SftpInboundReceiver;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class PollSftpInboundConnection implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $tries = 2;

    public function __construct(
        public readonly int $connectionId,
        public readonly string $tenantId,
    ) {}

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('poll-sftp-inbound:'.$this->tenantId.':'.$this->connectionId))
                ->releaseAfter(30)
                ->expireAfter(300),
        ];
    }

    public function handle(SftpInboundReceiver $receiver): void
    {
        $tenant = Tenant::query()->findOrFail($this->tenantId);

        $tenant->run(function () use ($receiver): void {
            $connection = InboundConnection::query()
                ->whereKey($this->connectionId)
                ->where('is_active', true)
                ->where('transport', InboundTransport::Sftp)
                ->firstOrFail();

            $receiver->poll($connection);
        });
    }

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        return ['tenant:'.$this->tenantId, 'inbound:sftp', 'connection:'.$this->connectionId];
    }
}
