<?php

declare(strict_types=1);

namespace Tests\Feature\BuyingGroup;

use App\Actions\BuyingGroup\AcceptBuyingGroupMembership;
use App\Actions\BuyingGroup\InviteBuyingGroupMembership;
use App\Actions\BuyingGroup\RevokeBuyingGroupMembership;
use App\Enums\BuyingGroupMemberStatus;
use App\Enums\BuyingGroupMembershipStatus;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Models\BuyingGroupMember;
use App\Models\BuyingGroupMembership;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\BuyingGroupMembershipInviteNotification;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\TenantSettings;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class BuyingGroupMembershipTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    /** @var list<string> */
    private array $createdTenantIds = [];

    /** @var list<int> */
    private array $membershipIds = [];

    /** @var list<int> */
    private array $memberIds = [];

    /** @var list<int> */
    private array $userIds = [];

    protected function tearDown(): void
    {
        if ($this->membershipIds !== []) {
            BuyingGroupMembership::query()->whereIn('id', $this->membershipIds)->delete();
            $this->membershipIds = [];
        }

        foreach ($this->createdTenantIds as $tenantId) {
            Tenant::withoutEvents(fn () => Tenant::query()->whereKey($tenantId)->delete());
        }
        $this->createdTenantIds = [];

        if (tenancy()->initialized) {
            if ($this->memberIds !== []) {
                BuyingGroupMember::query()->whereIn('id', $this->memberIds)->delete();
                $this->memberIds = [];
            }
            if ($this->userIds !== []) {
                User::query()->whereIn('id', $this->userIds)->delete();
                $this->userIds = [];
            }
            tenancy()->end();
        }

        $demo2 = Tenant::query()->find(self::DEMO2_TENANT_ID);
        $demo2?->forceFill(['profile' => TenantProfile::Pharmacy, 'status' => 'active'])->save();

        parent::tearDown();
    }

    #[Test]
    public function invite_accept_revoke_hard_membership_on_demo2_style_tenants(): void
    {
        Notification::fake();

        $buyingGroup = $this->initializeDemo2Tenant(TenantProfile::BuyingGroup);
        $pharmacy = $this->createPharmacyTenant();

        $bgOwner = $this->seedOwnerInCurrentTenant();
        $roster = BuyingGroupMember::query()->create([
            'name' => 'F2 Soft Roster Pharmacy',
            'status' => BuyingGroupMemberStatus::Active,
            'contact_email' => 'f2-roster@example.test',
        ]);
        $this->memberIds[] = (int) $roster->getKey();

        $membership = app(InviteBuyingGroupMembership::class)->invite(
            $buyingGroup,
            $pharmacy,
            $bgOwner,
            (int) $roster->getKey(),
        );
        $this->membershipIds[] = (int) $membership->getKey();

        $this->assertTrue($membership->isPending());
        $this->assertSame((int) $roster->getKey(), $membership->bg_member_local_id);
        $this->assertNull($roster->fresh()->member_tenant_id);

        Notification::assertSentTo(
            User::role(TenantRole::Owner->value)->get(),
            BuyingGroupMembershipInviteNotification::class,
        );

        // Pharmacy owners live in the shared demo2 DB after member tenancy notify.
        $pharmacyOwner = User::role(TenantRole::Owner->value)->first() ?? $bgOwner;

        $accepted = app(AcceptBuyingGroupMembership::class)->accept($membership, $pharmacyOwner);
        $this->assertTrue($accepted->isActive());
        $this->assertSame(BuyingGroupMembership::CONSENT_VERSION, $accepted->consent_version);

        $buyingGroup->run(function () use ($roster, $pharmacy): void {
            $roster->refresh();
            $this->assertSame((string) $pharmacy->getKey(), $roster->member_tenant_id);
            $this->assertSame(BuyingGroupMemberStatus::Active, $roster->status);
        });

        $pharmacy->refresh();
        $this->assertTrue(TenantSettings::forTenant($pharmacy)->buyingGroupNetworkConsent());

        $revoked = app(RevokeBuyingGroupMembership::class)->revoke(
            $accepted,
            $bgOwner,
            'Wave F2 test revoke',
        );
        $this->assertSame(BuyingGroupMembershipStatus::Revoked, $revoked->status);
        $this->assertSame('Wave F2 test revoke', $revoked->revoke_reason);

        $buyingGroup->run(function () use ($roster): void {
            $roster->refresh();
            $this->assertNull($roster->member_tenant_id);
            $this->assertSame(BuyingGroupMemberStatus::Suspended, $roster->status);
        });

        $pharmacy->refresh();
        $this->assertFalse(TenantSettings::forTenant($pharmacy)->buyingGroupNetworkConsent());
    }

    #[Test]
    public function invite_rejects_wrong_member_profile(): void
    {
        $buyingGroup = $this->initializeDemo2Tenant(TenantProfile::BuyingGroup);
        $wholesaler = $this->createCentralTenant(TenantProfile::DrugWholesaler, 'F2 Wholesaler Reject');
        $owner = $this->seedOwnerInCurrentTenant();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Hard membership is limited to pharmacy tenants.');

        try {
            app(InviteBuyingGroupMembership::class)->invite($buyingGroup, $wholesaler, $owner);
        } finally {
            // no membership created
        }
    }

    #[Test]
    public function invite_rejects_suspended_buying_group(): void
    {
        $buyingGroup = $this->initializeDemo2Tenant(TenantProfile::BuyingGroup);
        $buyingGroup->forceFill(['status' => 'suspended'])->save();
        $pharmacy = $this->createPharmacyTenant();
        $owner = $this->seedOwnerInCurrentTenant();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The buying-group tenant must be active to invite members.');

        app(InviteBuyingGroupMembership::class)->invite($buyingGroup, $pharmacy, $owner);
    }

    #[Test]
    public function invite_rejects_non_buying_group_inviter(): void
    {
        $pharmacyA = $this->initializeDemo2Tenant(TenantProfile::Pharmacy);
        $pharmacyB = $this->createPharmacyTenant();
        $owner = $this->seedOwnerInCurrentTenant();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Only a buying-group tenant can invite members.');

        app(InviteBuyingGroupMembership::class)->invite($pharmacyA, $pharmacyB, $owner);
    }

    private function seedOwnerInCurrentTenant(): User
    {
        $profile = tenant()?->profile ?? TenantProfile::Pharmacy;
        app(TenantRoleSeeder::class)->seedForProfile($profile);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::factory()->create();
        $user->assignRole(TenantRole::Owner->value);
        $this->userIds[] = (int) $user->getKey();

        return $user;
    }

    private function initializeDemo2Tenant(TenantProfile $profile): Tenant
    {
        $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);

        if ($tenant === null) {
            $tenant = Tenant::withoutEvents(fn () => Tenant::query()->create([
                'id' => self::DEMO2_TENANT_ID,
                'name' => $profile === TenantProfile::BuyingGroup ? 'Demo Buying Group' : 'Demo Pharmacy',
                'profile' => $profile,
                'status' => 'active',
                'tenancy_db_name' => self::DEMO2_DATABASE,
            ]));
            $tenant->domains()->create(['domain' => self::DEMO2_DOMAIN]);
        } else {
            $tenant->forceFill([
                'profile' => $profile,
                'status' => 'active',
            ])->save();
            $tenant->domains()->firstOrCreate(['domain' => self::DEMO2_DOMAIN]);
        }

        $this->artisan('tenants:migrate', [
            '--tenants' => [self::DEMO2_TENANT_ID],
            '--force' => true,
        ])->assertSuccessful();

        tenancy()->initialize($tenant);

        return $tenant;
    }

    private function createPharmacyTenant(): Tenant
    {
        return $this->createCentralTenant(TenantProfile::Pharmacy, 'F2 Pharmacy Member');
    }

    private function createCentralTenant(TenantProfile $profile, string $name): Tenant
    {
        $id = (string) Str::uuid();
        $tenant = Tenant::withoutEvents(fn () => Tenant::query()->create([
            'id' => $id,
            'name' => $name,
            'profile' => $profile,
            'status' => 'active',
            // Share demo2 DB so Owner notify / role tables resolve in tests.
            'tenancy_db_name' => self::DEMO2_DATABASE,
        ]));
        $tenant->domains()->create(['domain' => 'f2-'.Str::lower(Str::random(8)).'.internal.vatengi.com']);
        $this->createdTenantIds[] = $id;

        return $tenant;
    }
}
