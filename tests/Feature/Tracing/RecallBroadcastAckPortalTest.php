<?php

namespace Tests\Feature\Tracing;

use App\Actions\Tracing\SendRecallBroadcast;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Enums\TracingRequestNotificationStatus;
use App\Enums\TracingRequestScope;
use App\Enums\TracingRequestStatus;
use App\Enums\TracingRequestorType;
use App\Filament\App\Resources\TracingRequests\Actions\RecallBroadcastAckLinkActions;
use App\Models\Tenant;
use App\Models\TracingRequest;
use App\Models\TracingRequestNotification;
use App\Models\TradingPartner;
use App\Models\User;
use App\Services\Tracing\RecallBroadcastAckService;
use App\Support\Auth\TenantRoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RecallBroadcastAckPortalTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $requestIds = [];

    /** @var list<int> */
    private array $partnerIds = [];

    /** @var list<int> */
    private array $userIds = [];

    #[Test]
    public function ack_link_actions_are_hidden_from_personas_without_the_ability(): void
    {
        Notification::fake();

        $tenant = $this->initializeDemo2Tenant();

        try {
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);

            $technician = User::factory()->create();
            $technician->assignRole(TenantRole::ReceivingTechnician->value);
            $this->userIds[] = (int) $technician->getKey();
            $this->actingAs($technician);

            $user = User::query()->first() ?? User::factory()->create();
            $partner = TradingPartner::factory()->create([
                'email' => 'recall-ack-auth@example.test',
            ]);
            $this->partnerIds[] = (int) $partner->getKey();

            $request = TracingRequest::query()->create([
                'title' => 'Recall LOT-AUTH-1',
                'status' => TracingRequestStatus::Open,
                'requestor_type' => TracingRequestorType::Internal,
                'scope' => TracingRequestScope::Lot,
                'lot' => 'LOT-AUTH-1',
                'is_recall' => true,
                'requested_by' => $user->getKey(),
                'requested_at' => now(),
            ]);
            $this->requestIds[] = (int) $request->getKey();

            app(SendRecallBroadcast::class)->execute(
                $request,
                [(int) $partner->getKey()],
                $user,
            );

            $notification = TracingRequestNotification::query()
                ->where('tracing_request_id', $request->getKey())
                ->where('trading_partner_id', $partner->getKey())
                ->firstOrFail();

            $this->assertNotNull($notification->ack_share_uuid);

            $rotate = RecallBroadcastAckLinkActions::rotateAckLinkAction();
            $rotate->record($notification);
            $this->assertFalse($rotate->isVisible());

            $revoke = RecallBroadcastAckLinkActions::revokeAckLinkAction();
            $revoke->record($notification);
            $this->assertFalse($revoke->isVisible());
        } finally {
            if (! tenancy()->initialized) {
                tenancy()->initialize($tenant);
            }
            $this->cleanup();
        }
    }

    #[Test]
    public function partner_can_acknowledge_recall_broadcast_via_signed_link(): void
    {
        Notification::fake();

        $tenant = $this->initializeDemo2Tenant();

        try {
            $user = User::query()->first() ?? User::factory()->create();
            $partner = TradingPartner::factory()->create([
                'email' => 'recall-ack-partner@example.test',
            ]);
            $this->partnerIds[] = (int) $partner->getKey();

            $request = TracingRequest::query()->create([
                'title' => 'Recall LOT-ACK-1',
                'status' => TracingRequestStatus::Open,
                'requestor_type' => TracingRequestorType::Internal,
                'scope' => TracingRequestScope::Lot,
                'gtin' => '00300012345678',
                'lot' => 'LOT-ACK-1',
                'is_recall' => true,
                'requested_by' => $user->getKey(),
                'requested_at' => now(),
            ]);
            $this->requestIds[] = (int) $request->getKey();

            app(SendRecallBroadcast::class)->execute(
                $request,
                [(int) $partner->getKey()],
                $user,
            );

            $notification = TracingRequestNotification::query()
                ->where('tracing_request_id', $request->getKey())
                ->where('trading_partner_id', $partner->getKey())
                ->firstOrFail();

            $this->assertNotNull($notification->ack_share_uuid);

            URL::forceRootUrl('http://'.self::DEMO2_DOMAIN);
            $showUrl = app(RecallBroadcastAckService::class)->signedAckUrl($notification);
            $submitUrl = app(RecallBroadcastAckService::class)->signedAckSubmitUrl($notification);

            tenancy()->end();

            $this->get($showUrl)
                ->assertOk()
                ->assertSee('Recall LOT-ACK-1', false)
                ->assertSee('Acknowledge receipt', false);

            $this->post($submitUrl)
                ->assertRedirect();

            tenancy()->initialize($tenant);

            $notification->refresh();
            $this->assertSame(TracingRequestNotificationStatus::Acknowledged, $notification->status);
            $this->assertNotNull($notification->acknowledged_at);

            tenancy()->end();

            $this->get($showUrl)
                ->assertOk()
                ->assertSee('Acknowledged', false)
                ->assertDontSee('Acknowledge receipt', false);

            $this->post($submitUrl)->assertRedirect();

            tenancy()->initialize($tenant);
            $this->assertSame(
                $notification->acknowledged_at?->toDateTimeString(),
                $notification->refresh()->acknowledged_at?->toDateTimeString(),
            );
        } finally {
            if (! tenancy()->initialized) {
                tenancy()->initialize($tenant);
            }
            $this->cleanup();
        }
    }

    #[Test]
    public function recall_broadcast_ack_rejects_inactive_partner(): void
    {
        Notification::fake();

        $tenant = $this->initializeDemo2Tenant();

        try {
            $user = User::query()->first() ?? User::factory()->create();
            $partner = TradingPartner::factory()->create([
                'email' => 'recall-ack-inactive@example.test',
                'is_active' => true,
            ]);
            $this->partnerIds[] = (int) $partner->getKey();

            $request = TracingRequest::query()->create([
                'title' => 'Recall LOT-INACTIVE-1',
                'status' => TracingRequestStatus::Open,
                'requestor_type' => TracingRequestorType::Internal,
                'scope' => TracingRequestScope::Lot,
                'lot' => 'LOT-INACTIVE-1',
                'is_recall' => true,
                'requested_by' => $user->getKey(),
                'requested_at' => now(),
            ]);
            $this->requestIds[] = (int) $request->getKey();

            app(SendRecallBroadcast::class)->execute(
                $request,
                [(int) $partner->getKey()],
                $user,
            );

            $notification = TracingRequestNotification::query()
                ->where('tracing_request_id', $request->getKey())
                ->where('trading_partner_id', $partner->getKey())
                ->firstOrFail();

            URL::forceRootUrl('http://'.self::DEMO2_DOMAIN);
            $ackService = app(RecallBroadcastAckService::class);
            $showUrl = $ackService->signedAckUrl($notification);
            $submitUrl = $ackService->signedAckSubmitUrl($notification);

            $partner->update(['is_active' => false]);

            tenancy()->end();

            $this->get($showUrl)
                ->assertOk()
                ->assertSee('no longer valid', false);

            $this->post($submitUrl)
                ->assertOk()
                ->assertSee('no longer valid', false);

            tenancy()->initialize($tenant);
            $notification->refresh();
            $this->assertSame(TracingRequestNotificationStatus::Sent, $notification->status);
            $this->assertNull($notification->acknowledged_at);
        } finally {
            if (! tenancy()->initialized) {
                tenancy()->initialize($tenant);
            }
            $this->cleanup();
        }
    }

    #[Test]
    public function recall_broadcast_ack_rejects_invalid_signature(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $user = User::query()->first() ?? User::factory()->create();
            $partner = TradingPartner::factory()->create([
                'email' => 'recall-ack-invalid@example.test',
            ]);
            $this->partnerIds[] = (int) $partner->getKey();

            $request = TracingRequest::query()->create([
                'title' => 'Recall LOT-INVALID-1',
                'status' => TracingRequestStatus::Open,
                'requestor_type' => TracingRequestorType::Internal,
                'scope' => TracingRequestScope::Lot,
                'lot' => 'LOT-INVALID-1',
                'is_recall' => true,
                'requested_by' => $user->getKey(),
                'requested_at' => now(),
            ]);
            $this->requestIds[] = (int) $request->getKey();

            $notification = TracingRequestNotification::query()->create([
                'tracing_request_id' => $request->getKey(),
                'trading_partner_id' => $partner->getKey(),
                'channel' => 'email',
                'status' => TracingRequestNotificationStatus::Sent,
                'sent_at' => now(),
            ]);
            app(RecallBroadcastAckService::class)->ensureAckLink($notification);

            URL::forceRootUrl('http://'.self::DEMO2_DOMAIN);
            $url = 'http://'.self::DEMO2_DOMAIN.'/recall-broadcast-ack/'.$notification->ack_share_uuid;

            tenancy()->end();

            $this->get($url)->assertForbidden();

            $this->post($url)->assertForbidden();
        } finally {
            if (! tenancy()->initialized) {
                tenancy()->initialize($tenant);
            }
            $this->cleanup();
        }
    }

    #[Test]
    public function rotating_the_ack_link_issues_a_new_uuid_and_closes_the_old_url(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $user = User::query()->first() ?? User::factory()->create();
            $partner = TradingPartner::factory()->create([
                'email' => 'recall-ack-rotate@example.test',
            ]);
            $this->partnerIds[] = (int) $partner->getKey();

            $request = TracingRequest::query()->create([
                'title' => 'Recall LOT-ROTATE-1',
                'status' => TracingRequestStatus::Open,
                'requestor_type' => TracingRequestorType::Internal,
                'scope' => TracingRequestScope::Lot,
                'lot' => 'LOT-ROTATE-1',
                'is_recall' => true,
                'requested_by' => $user->getKey(),
                'requested_at' => now(),
            ]);
            $this->requestIds[] = (int) $request->getKey();

            app(SendRecallBroadcast::class)->execute(
                $request,
                [(int) $partner->getKey()],
                $user,
            );

            $notification = TracingRequestNotification::query()
                ->where('tracing_request_id', $request->getKey())
                ->where('trading_partner_id', $partner->getKey())
                ->firstOrFail();

            URL::forceRootUrl('http://'.self::DEMO2_DOMAIN);
            $ackService = app(RecallBroadcastAckService::class);
            $oldUrl = $ackService->signedAckUrl($notification);
            $originalUuid = $notification->ack_share_uuid;

            $ackService->rotateAckLink($notification);
            $rotated = $notification->refresh();

            $this->assertNotNull($rotated->ack_share_uuid);
            $this->assertNotSame($originalUuid, $rotated->ack_share_uuid);

            $freshUrl = $ackService->signedAckUrl($rotated);

            tenancy()->end();

            $this->get($oldUrl)
                ->assertOk()
                ->assertSee('no longer valid', false);
            $this->get($freshUrl)->assertOk();
        } finally {
            if (! tenancy()->initialized) {
                tenancy()->initialize($tenant);
            }
            $this->cleanup();
        }
    }

    #[Test]
    public function revoking_the_ack_link_closes_outstanding_urls(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $user = User::query()->first() ?? User::factory()->create();
            $partner = TradingPartner::factory()->create([
                'email' => 'recall-ack-revoke@example.test',
            ]);
            $this->partnerIds[] = (int) $partner->getKey();

            $request = TracingRequest::query()->create([
                'title' => 'Recall LOT-REVOKE-1',
                'status' => TracingRequestStatus::Open,
                'requestor_type' => TracingRequestorType::Internal,
                'scope' => TracingRequestScope::Lot,
                'lot' => 'LOT-REVOKE-1',
                'is_recall' => true,
                'requested_by' => $user->getKey(),
                'requested_at' => now(),
            ]);
            $this->requestIds[] = (int) $request->getKey();

            app(SendRecallBroadcast::class)->execute(
                $request,
                [(int) $partner->getKey()],
                $user,
            );

            $notification = TracingRequestNotification::query()
                ->where('tracing_request_id', $request->getKey())
                ->where('trading_partner_id', $partner->getKey())
                ->firstOrFail();

            URL::forceRootUrl('http://'.self::DEMO2_DOMAIN);
            $ackService = app(RecallBroadcastAckService::class);
            $url = $ackService->signedAckUrl($notification);

            tenancy()->end();
            $this->get($url)->assertOk();

            tenancy()->initialize($tenant);
            $ackService->revokeAckLink($notification->refresh());
            $this->assertNull($notification->refresh()->ack_share_uuid);

            tenancy()->end();
            $this->get($url)
                ->assertOk()
                ->assertSee('no longer valid', false);
        } finally {
            if (! tenancy()->initialized) {
                tenancy()->initialize($tenant);
            }
            $this->cleanup();
        }
    }

    #[Test]
    public function acknowledged_notifications_can_still_have_their_ack_link_revoked(): void
    {
        Notification::fake();

        $tenant = $this->initializeDemo2Tenant();

        try {
            $user = User::query()->first() ?? User::factory()->create();
            $partner = TradingPartner::factory()->create([
                'email' => 'recall-ack-revoke-ackd@example.test',
            ]);
            $this->partnerIds[] = (int) $partner->getKey();

            $request = TracingRequest::query()->create([
                'title' => 'Recall LOT-REVOKE-ACKD-1',
                'status' => TracingRequestStatus::Open,
                'requestor_type' => TracingRequestorType::Internal,
                'scope' => TracingRequestScope::Lot,
                'lot' => 'LOT-REVOKE-ACKD-1',
                'is_recall' => true,
                'requested_by' => $user->getKey(),
                'requested_at' => now(),
            ]);
            $this->requestIds[] = (int) $request->getKey();

            app(SendRecallBroadcast::class)->execute(
                $request,
                [(int) $partner->getKey()],
                $user,
            );

            $notification = TracingRequestNotification::query()
                ->where('tracing_request_id', $request->getKey())
                ->where('trading_partner_id', $partner->getKey())
                ->firstOrFail();

            URL::forceRootUrl('http://'.self::DEMO2_DOMAIN);
            $ackService = app(RecallBroadcastAckService::class);
            $url = $ackService->signedAckUrl($notification);
            $submitUrl = $ackService->signedAckSubmitUrl($notification);

            tenancy()->end();
            $this->post($submitUrl)->assertRedirect();

            tenancy()->initialize($tenant);
            $notification->refresh();
            $this->assertSame(TracingRequestNotificationStatus::Acknowledged, $notification->status);
            $acknowledgedAt = $notification->acknowledged_at;

            $ackService->revokeAckLink($notification);
            $notification->refresh();

            $this->assertNull($notification->ack_share_uuid);
            $this->assertSame(TracingRequestNotificationStatus::Acknowledged, $notification->status);
            $this->assertSame(
                $acknowledgedAt?->toDateTimeString(),
                $notification->acknowledged_at?->toDateTimeString(),
            );

            tenancy()->end();
            $this->get($url)
                ->assertOk()
                ->assertSee('no longer valid', false);
        } finally {
            if (! tenancy()->initialized) {
                tenancy()->initialize($tenant);
            }
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

        if ($this->userIds !== []) {
            DB::table('model_has_roles')
                ->where('model_type', User::class)
                ->whereIn('model_id', $this->userIds)
                ->delete();
            User::query()->whereIn('id', $this->userIds)->delete();
            $this->userIds = [];
        }

        $this->requestIds = [];
        $this->partnerIds = [];
        tenancy()->end();
    }
}
