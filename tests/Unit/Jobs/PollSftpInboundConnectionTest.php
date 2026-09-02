<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Jobs\PollSftpInboundConnection;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class PollSftpInboundConnectionTest extends TestCase
{
    #[Test]
    public function failed_handler_logs_tenant_and_connection_context(): void
    {
        Log::shouldReceive('error')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'PollSftpInboundConnection failed.'
                    && $context['tenant_id'] === 'tenant-abc'
                    && $context['connection_id'] === 42
                    && $context['message'] === 'connection refused';
            });

        $job = new PollSftpInboundConnection(42, 'tenant-abc');
        $job->failed(new RuntimeException('connection refused'));
    }
}
