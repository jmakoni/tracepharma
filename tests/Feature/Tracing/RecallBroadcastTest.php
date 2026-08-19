<?php

namespace Tests\Feature\Tracing;

use App\Actions\Tracing\SendRecallBroadcast;
use App\Enums\TenantProfile;
use App\Enums\TracingRequestNotificationStatus;
use App\Enums\TracingRequestScope;
use App\Enums\TracingRequestStatus;
use App\Enums\TracingRequestorType;
use App\Models\Tenant;
use App\Models\TracingRequest;
use App\Models\TracingRequestNotification;
use App\Models\TradingPartner;
use App\Models\User;
use App\Notifications\RecallBroadcastMail;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RecallBroadcastTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $requestIds = [];

    /** @var list<int> */
    private array $partnerIds = [];

    #[Test]
    public function send_recall_broadcast_sends_mail_and_marks_notification_sent(): void
    {
        Notification::fake();

        $this->initializeDemo2Tenant();

        try {
            $user = User::query()->first() ?? User::factory()->create();
            $partner = TradingPartner::factory()->create([
                'email' => 'recall-partner@example.test',
            ]);
            $this->partnerIds[] = (int) $partner->getKey();

            $request = TracingRequest::query()->create([
                'title' => 'Recall LOT-RECALL-1',
                'status' => TracingRequestStatus::Open,
                'requestor_type' => TracingRequestorType::Internal,
                'scope' => TracingRequestScope::Lot,
                'gtin' => '00300012345678',
                'lot' => 'LOT-RECALL-1',
                'is_recall' => true,
                'requested_by' => $user->getKey(),
                'requested_at' => now(),
            ]);
            $this->requestIds[] = (int) $request->getKey();

            $result = app(SendRecallBroadcast::class)->execute(
                $request,
                [(int) $partner->getKey()],
                $user,
            );

            $this->assertSame(1, $result['sent']);
            $this->assertSame(0, $result['failed']);
            $this->assertSame(0, $result['skipped']);

            $this->assertDatabaseHas('tracing_request_notifications', [
                'tracing_request_id' => $request->getKey(),
                'trading_partner_id' => $partner->getKey(),
                'channel' => 'email',
                'status' => TracingRequestNotificationStatus::Sent->value,
            ]);

            Notification::assertSentOnDemand(
                RecallBroadcastMail::class,
                fn (RecallBroadcastMail $notification, array $channels, object $notifiable): bool => $notifiable->routes['mail'] === 'recall-partner@example.test'
                    && $notification->request->is($request)
                    && $notification->partner->is($partner),
            );
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function send_recall_broadcast_rejects_non_recall_requests(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $user = User::query()->first() ?? User::factory()->create();
            $partner = TradingPartner::factory()->create([
                'email' => 'recall-partner@example.test',
            ]);
            $this->partnerIds[] = (int) $partner->getKey();

            $request = TracingRequest::query()->create([
                'title' => 'Standard trace',
                'status' => TracingRequestStatus::Open,
                'requestor_type' => TracingRequestorType::Internal,
                'scope' => TracingRequestScope::Lot,
                'gtin' => '00300012345678',
                'lot' => 'LOT-TRACE-1',
                'is_recall' => false,
                'requested_by' => $user->getKey(),
                'requested_at' => now(),
            ]);
            $this->requestIds[] = (int) $request->getKey();

            $this->expectException(InvalidArgumentException::class);

            app(SendRecallBroadcast::class)->execute(
                $request,
                [(int) $partner->getKey()],
                $user,
            );
        } finally {
            $this->cleanup();
        }
    }

    private function initializeDemo2Tenant(): Tenant
    {
        $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);

        if ($tenant === null) {
            $tenant = Tenant::withoutEvents(fn () => Tenant::query()->create([
                'id' => self::DEMO2_TENANT_ID,
                'name' => 'Demo Pharmacy',
                'profile' => TenantProfile::Pharmacy,
                'status' => 'active',
                'tenancy_db_name' => self::DEMO2_DATABASE,
            ]));
            $tenant->domains()->create(['domain' => self::DEMO2_DOMAIN]);
        } else {
            $tenant->domains()->firstOrCreate(['domain' => self::DEMO2_DOMAIN]);
        }

        if (! self::$demo2TenantReady) {
            $this->artisan('tenants:migrate', [
                '--tenants' => [self::DEMO2_TENANT_ID],
                '--force' => true,
            ])->assertSuccessful();

            self::$demo2TenantReady = true;
        }

        tenancy()->initialize($tenant);

        return $tenant;
    }

    private function cleanup(): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        foreach ($this->requestIds as $id) {
            TracingRequestNotification::query()
                ->where('tracing_request_id', $id)
                ->delete();
            TracingRequest::query()->whereKey($id)->delete();
        }

        foreach ($this->partnerIds as $id) {
            TradingPartner::query()->whereKey($id)->delete();
        }

        $this->requestIds = [];
        $this->partnerIds = [];
        tenancy()->end();
    }
}
