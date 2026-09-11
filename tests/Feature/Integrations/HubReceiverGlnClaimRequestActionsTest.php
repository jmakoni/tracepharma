<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Actions\Integrations\RequestHubReceiverGlnClaim;
use App\Actions\Integrations\ReviewHubReceiverGlnClaimRequest;
use App\Enums\HubReceiverGlnClaimRequestStatus;
use App\Enums\TenantProfile;
use App\Models\Admin;
use App\Models\EpcisHubRoute;
use App\Models\HubReceiverGlnClaimRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Support\EpcisHub\EpcisHubPlatformConfig;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class HubReceiverGlnClaimRequestActionsTest extends TestCase
{
    private const GLN = '0366159000010';

    private ?Tenant $tenant = null;

    private ?Admin $admin = null;

    protected function setUp(): void
    {
        parent::setUp();

        app(EpcisHubPlatformConfig::class)->setProviders('stage', ['systech']);

        $this->tenant = Tenant::withoutEvents(fn (): Tenant => Tenant::query()->create([
            'id' => (string) str()->uuid(),
            'name' => 'Hub claim request test',
            'profile' => TenantProfile::Pharmacy,
            'status' => 'active',
            'gln' => self::GLN,
            'inbound_environment' => 'stage',
            'hub_providers' => ['systech'],
            'tenancy_db_name' => 'tenant_hub_claim_request_test',
        ]));
    }

    protected function tearDown(): void
    {
        if ($this->tenant !== null) {
            HubReceiverGlnClaimRequest::query()->where('tenant_id', $this->tenant->getKey())->delete();
            EpcisHubRoute::query()->where('tenant_id', $this->tenant->getKey())->delete();
            Tenant::withoutEvents(fn () => $this->tenant?->delete());
        }

        $this->admin?->delete();

        parent::tearDown();
    }

    #[Test]
    public function tenant_user_can_upsert_a_pending_claim_request_without_creating_a_route(): void
    {
        $user = new User(['name' => 'Tenant Owner', 'email' => 'owner@example.com']);
        $action = app(RequestHubReceiverGlnClaim::class);

        $request = $action->request(
            $this->tenant(),
            ' SYSTECH ',
            self::GLN,
            ' Enable hub receiving. ',
            $user,
        );

        $this->assertSame('systech', $request->provider);
        $this->assertSame(self::GLN, $request->gln);
        $this->assertSame('Enable hub receiving.', $request->reason);
        $this->assertSame('Tenant Owner (owner@example.com)', $request->requested_by);
        $this->assertSame(HubReceiverGlnClaimRequestStatus::Pending, $request->status);
        $this->assertFalse(EpcisHubRoute::query()->where('tenant_id', $this->tenant()->getKey())->exists());

        $updated = $action->request(
            $this->tenant(),
            'systech',
            self::GLN,
            'Updated business reason.',
            $user,
        );

        $this->assertTrue($request->is($updated));
        $this->assertSame('Updated business reason.', $updated->reason);
        $this->assertSame(1, HubReceiverGlnClaimRequest::query()
            ->where('tenant_id', $this->tenant()->getKey())
            ->pending()
            ->count());
    }

    #[Test]
    public function request_rejects_an_existing_admin_claim(): void
    {
        EpcisHubRoute::query()->create([
            'tenant_id' => $this->tenant()->getKey(),
            'provider' => 'systech',
            'gln' => self::GLN,
            'is_active' => true,
            'claimed_via' => EpcisHubRoute::CLAIMED_VIA_ADMIN,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already admin-claimed');

        app(RequestHubReceiverGlnClaim::class)->request(
            $this->tenant(),
            'systech',
            self::GLN,
            'Enable hub receiving.',
            new User(['email' => 'owner@example.com']),
        );
    }

    #[Test]
    public function request_applies_the_same_provider_entitlement_guard_as_direct_claims(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not enabled for this tenant');

        app(RequestHubReceiverGlnClaim::class)->request(
            $this->tenant(),
            'unitrace',
            self::GLN,
            'Enable hub receiving.',
            new User(['email' => 'owner@example.com']),
        );
    }

    #[Test]
    public function admin_can_approve_a_pending_request_and_create_the_claim(): void
    {
        $request = $this->pendingRequest();
        $this->admin = Admin::factory()->create();

        app(ReviewHubReceiverGlnClaimRequest::class)->approve($request, $this->admin, 'Verified ownership.');

        $request->refresh();
        $this->assertSame(HubReceiverGlnClaimRequestStatus::Approved, $request->status);
        $this->assertSame($this->admin->getKey(), $request->reviewed_by_admin_id);
        $this->assertNotNull($request->reviewed_at);
        $this->assertSame('Verified ownership.', $request->review_note);
        $this->assertDatabaseHas('epcis_hub_routes', [
            'tenant_id' => $this->tenant()->getKey(),
            'provider' => 'systech',
            'gln' => self::GLN,
            'claimed_via' => EpcisHubRoute::CLAIMED_VIA_ADMIN,
        ]);
    }

    #[Test]
    public function admin_can_reject_a_pending_request_without_creating_a_claim(): void
    {
        $request = $this->pendingRequest();
        $this->admin = Admin::factory()->create();

        app(ReviewHubReceiverGlnClaimRequest::class)->reject($request, $this->admin, 'GLN evidence did not match.');

        $request->refresh();
        $this->assertSame(HubReceiverGlnClaimRequestStatus::Rejected, $request->status);
        $this->assertSame($this->admin->getKey(), $request->reviewed_by_admin_id);
        $this->assertSame('GLN evidence did not match.', $request->review_note);
        $this->assertFalse(EpcisHubRoute::query()->where('tenant_id', $this->tenant()->getKey())->exists());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Only pending hub receiver GLN claim requests can be reviewed.');

        app(ReviewHubReceiverGlnClaimRequest::class)->approve($request, $this->admin);
    }

    #[Test]
    public function tenant_can_reset_a_rejected_request_to_pending_without_creating_a_duplicate_or_route(): void
    {
        $request = $this->pendingRequest();
        $this->admin = Admin::factory()->create();

        app(ReviewHubReceiverGlnClaimRequest::class)->reject($request, $this->admin, 'Evidence did not match.');

        $resubmitted = app(RequestHubReceiverGlnClaim::class)->request(
            $this->tenant(),
            'systech',
            self::GLN,
            'New ownership evidence is available.',
            new User(['name' => 'New Owner', 'email' => 'new-owner@example.com']),
        );

        $this->assertTrue($request->is($resubmitted));
        $this->assertSame(HubReceiverGlnClaimRequestStatus::Pending, $resubmitted->status);
        $this->assertSame('New ownership evidence is available.', $resubmitted->reason);
        $this->assertSame('New Owner (new-owner@example.com)', $resubmitted->requested_by);
        $this->assertNull($resubmitted->reviewed_by_admin_id);
        $this->assertNull($resubmitted->reviewed_at);
        $this->assertNull($resubmitted->review_note);
        $this->assertSame(1, HubReceiverGlnClaimRequest::query()
            ->where('tenant_id', $this->tenant()->getKey())
            ->where('provider', 'systech')
            ->where('gln', self::GLN)
            ->count());
        $this->assertSame(1, HubReceiverGlnClaimRequest::query()
            ->where('tenant_id', $this->tenant()->getKey())
            ->pending()
            ->count());
        $this->assertFalse(EpcisHubRoute::query()->where('tenant_id', $this->tenant()->getKey())->exists());
    }

    private function pendingRequest(): HubReceiverGlnClaimRequest
    {
        return app(RequestHubReceiverGlnClaim::class)->request(
            $this->tenant(),
            'systech',
            self::GLN,
            'Enable hub receiving.',
            new User(['name' => 'Tenant Owner', 'email' => 'owner@example.com']),
        );
    }

    private function tenant(): Tenant
    {
        return $this->tenant ?? throw new RuntimeException('Test tenant was not initialized.');
    }
}
