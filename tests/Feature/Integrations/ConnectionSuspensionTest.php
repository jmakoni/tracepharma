<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Actions\Integrations\RegisterConnectionApprovalRequest;
use App\Actions\Integrations\ReviewConnectionApprovalRequest;
use App\Enums\AdminRole;
use App\Enums\ConnectionApprovalStatus;
use App\Enums\InboundTransport;
use App\Enums\OutboundTransport;
use App\Enums\SerializationProvider;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Models\Admin;
use App\Models\ConnectionApprovalRequest;
use App\Models\InboundConnection;
use App\Models\OutboundConnection;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\ConnectionReviewedNotification;
use App\Notifications\ConnectionReviewRequestedNotification;
use App\Support\Auth\AdminRoleSeeder;
use App\Support\Auth\TenantRoleSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ConnectionSuspensionTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $inboundConnectionIds = [];

    /** @var list<int> */
    private array $outboundConnectionIds = [];

    /** @var list<int> */
    private array $requestIds = [];

    /** @var list<int> */
    private array $adminIds = [];

    /** @var list<int> */
    private array $userIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        app(AdminRoleSeeder::class)->seed();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    #[Test]
    public function suspend_requires_approved_status_and_a_reason(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $admin = $this->createAdmin();
            $connection = $this->makeInboundConnection();
            $request = $this->registerPending($connection);

            tenancy()->end();

            $review = app(ReviewConnectionApprovalRequest::class);

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Only approved connections can be suspended.');

            try {
                $review->suspend($request->fresh(), $admin, 'reason');
            } finally {
                $this->assertSame(ConnectionApprovalStatus::Pending, $request->fresh()->status);
            }
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function suspend_blocks_the_connection_and_resume_restores_it(): void
    {
        Notification::fake();
        $this->initializeDemo2Tenant();

        try {
            $admin = $this->createAdmin();
            $owner = $this->createOwner();
            $connection = $this->makeInboundConnection();
            $request = $this->registerPending($connection);

            tenancy()->end();

            $review = app(ReviewConnectionApprovalRequest::class);
            $review->approve($request->fresh(), $admin);
            $review->suspend($request->fresh(), $admin, 'Suspicious document volume.');

            $request = $request->fresh();
            $this->assertSame(ConnectionApprovalStatus::Suspended, $request->status);
            $this->assertSame('Suspicious document volume.', $request->review_note);

            tenancy()->initialize(Tenant::query()->findOrFail(self::DEMO2_TENANT_ID));

            $connection = $connection->fresh();
            $this->assertTrue($connection->isSuspended());
            $this->assertFalse($connection->isApproved());
            $this->assertSame('Suspicious document volume.', $connection->approval_note);

            Notification::assertSentTo(
                $owner,
                ConnectionReviewedNotification::class,
                fn (ConnectionReviewedNotification $n): bool => $n->decision === ConnectionApprovalStatus::Suspended
                    && $n->reviewNote === 'Suspicious document volume.',
            );

            tenancy()->end();

            $review->resume($request->fresh(), $admin);

            $this->assertSame(ConnectionApprovalStatus::Approved, $request->fresh()->status);

            tenancy()->initialize(Tenant::query()->findOrFail(self::DEMO2_TENANT_ID));

            $this->assertTrue($connection->fresh()->isApproved());
            $this->assertNull($connection->fresh()->approval_note);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function suspended_inbound_connection_is_rejected_by_the_webhook(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $connection = $this->makeInboundConnection();
            $connection->forceFill(['approval_status' => ConnectionApprovalStatus::Suspended])->save();

            $response = $this->postJson(
                '/api/webhooks/epcis/'.self::DEMO2_TENANT_ID.'/'.$connection->getKey(),
                [],
                ['X-Inbound-Token' => 'whatever'],
            );

            $response->assertForbidden();
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function suspended_outbound_connection_is_skipped_by_the_resolver(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $connection = OutboundConnection::query()->create([
                'name' => 'Suspended outbound '.Str::random(4),
                'serialization_provider' => SerializationProvider::CustomHttps,
                'transport' => OutboundTransport::Https,
                'is_active' => true,
                'approval_status' => ConnectionApprovalStatus::Suspended,
                'settings' => ['endpoint_url' => 'https://partner.example/epcis'],
            ]);
            $this->outboundConnectionIds[] = (int) $connection->getKey();

            $approved = OutboundConnection::query()
                ->where('approval_status', ConnectionApprovalStatus::Approved->value)
                ->whereKey($connection->getKey())
                ->exists();

            $this->assertFalse($approved);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function tenant_owners_are_notified_on_approve_and_reject(): void
    {
        Notification::fake();
        $this->initializeDemo2Tenant();

        try {
            $admin = $this->createAdmin();
            $owner = $this->createOwner();

            $approveConnection = $this->makeInboundConnection();
            $approveRequest = $this->registerPending($approveConnection);

            $rejectConnection = $this->makeInboundConnection();
            $rejectRequest = $this->registerPending($rejectConnection);

            tenancy()->end();

            $review = app(ReviewConnectionApprovalRequest::class);
            $review->approve($approveRequest->fresh(), $admin);
            $review->reject($rejectRequest->fresh(), $admin, 'Incomplete endpoint details.');

            Notification::assertSentTo(
                $owner,
                ConnectionReviewedNotification::class,
                fn (ConnectionReviewedNotification $n): bool => $n->decision === ConnectionApprovalStatus::Approved,
            );

            Notification::assertSentTo(
                $owner,
                ConnectionReviewedNotification::class,
                fn (ConnectionReviewedNotification $n): bool => $n->decision === ConnectionApprovalStatus::Rejected
                    && $n->reviewNote === 'Incomplete endpoint details.',
            );
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function reviewers_are_notified_when_a_request_is_registered(): void
    {
        Notification::fake();
        $this->initializeDemo2Tenant();

        try {
            $admin = $this->createAdmin();
            $connection = $this->makeInboundConnection();

            $request = app(RegisterConnectionApprovalRequest::class)->register($connection);
            $this->requestIds[] = (int) $request->getKey();

            Notification::assertSentTo(
                $admin,
                ConnectionReviewRequestedNotification::class,
                fn (ConnectionReviewRequestedNotification $n): bool => $n->requestId === (int) $request->getKey(),
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

    private function makeInboundConnection(): InboundConnection
    {
        $connection = InboundConnection::query()->create([
            'name' => 'Suspension test '.Str::random(6),
            'serialization_provider' => SerializationProvider::TracePharma,
            'transport' => InboundTransport::Https,
            'is_active' => true,
        ]);

        $this->inboundConnectionIds[] = (int) $connection->getKey();

        return $connection;
    }

    private function registerPending(InboundConnection $connection): ConnectionApprovalRequest
    {
        $request = app(RegisterConnectionApprovalRequest::class)->register($connection);
        $this->requestIds[] = (int) $request->getKey();

        return $request;
    }

    private function createAdmin(): Admin
    {
        $resume = tenancy()->initialized ? tenant() : null;

        if ($resume !== null) {
            tenancy()->end();
        }

        $admin = Admin::factory()->create();
        $admin->assignRole(AdminRole::PlatformAdmin->value);
        $this->adminIds[] = (int) $admin->getKey();

        if ($resume !== null) {
            tenancy()->initialize($resume);
        }

        return $admin;
    }

    private function createOwner(): User
    {
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
        $user = User::factory()->create([
            'email' => 'owner-suspension-'.Str::uuid().'@example.com',
        ]);
        $user->assignRole(TenantRole::Owner->value);
        $this->userIds[] = (int) $user->getKey();

        return $user;
    }

    private function cleanup(): void
    {
        $hasTenantArtifacts = $this->inboundConnectionIds !== []
            || $this->outboundConnectionIds !== []
            || $this->userIds !== [];

        if ($hasTenantArtifacts && ! tenancy()->initialized) {
            $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);

            if ($tenant !== null) {
                tenancy()->initialize($tenant);
            }
        }

        if (tenancy()->initialized) {
            if ($this->inboundConnectionIds !== []) {
                InboundConnection::query()->whereIn('id', $this->inboundConnectionIds)->delete();
                $this->inboundConnectionIds = [];
            }

            if ($this->outboundConnectionIds !== []) {
                OutboundConnection::query()->whereIn('id', $this->outboundConnectionIds)->delete();
                $this->outboundConnectionIds = [];
            }

            if ($this->userIds !== []) {
                User::query()->whereIn('id', $this->userIds)->delete();
                $this->userIds = [];
            }

            tenancy()->end();
        }

        if ($this->requestIds !== []) {
            ConnectionApprovalRequest::query()->whereIn('id', $this->requestIds)->delete();
            $this->requestIds = [];
        }

        if ($this->adminIds !== []) {
            Admin::query()->whereIn('id', $this->adminIds)->delete();
            $this->adminIds = [];
        }
    }
}
