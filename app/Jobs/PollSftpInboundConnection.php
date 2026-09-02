<?php

namespace App\Jobs;

use App\Enums\InboundTransport;
use App\Models\InboundConnection;
use App\Models\Tenant;
use App\Services\Integrations\SftpInboundReceiver;
use App\Support\Tenancy\TenantRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;

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

        TenantRunner::run($tenant, function () use ($receiver): void {
            $connection = InboundConnection::query()
                ->whereKey($this->connectionId)
                ->where('is_active', true)
                ->where('transport', InboundTransport::Sftp)
                ->firstOrFail();

            $receiver->poll($connection);
        });
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('PollSftpInboundConnection failed.', [
            'tenant_id' => $this->tenantId,
            'connection_id' => $this->connectionId,
            'message' => $exception->getMessage(),
        ]);
    }

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        return ['tenant:'.$this->tenantId, 'inbound:sftp', 'connection:'.$this->connectionId];
    }
}
