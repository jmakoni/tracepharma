<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Enums\TenantProfile;
use App\Jobs\PollSftpInboundConnection;
use App\Models\Tenant;
use App\Services\Integrations\SftpInboundReceiver;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Mockery;
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

    #[Test]
    public function handle_skips_suspended_tenant_without_polling(): void
    {
        $tenantId = (string) Str::uuid();

        $tenant = Tenant::withoutEvents(fn () => Tenant::query()->create([
            'id' => $tenantId,
            'name' => 'Suspended SFTP Poll',
            'profile' => TenantProfile::Pharmacy,
            'status' => 'suspended',
            'tenancy_db_name' => 'tenant_sftp_poll_suspend_'.Str::lower(Str::random(8)),
        ]));

        try {
            $receiver = Mockery::mock(SftpInboundReceiver::class);
            $receiver->shouldNotReceive('poll');

            $job = new PollSftpInboundConnection(1, $tenant->id);
            $job->handle($receiver);
        } finally {
            Tenant::withoutEvents(fn () => Tenant::query()->whereKey($tenantId)->delete());
        }
    }
}
