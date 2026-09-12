<?php

declare(strict_types=1);

namespace Tests\Feature\BuyingGroup;

use App\Actions\BuyingGroup\AcceptBuyingGroupMembership;
use App\Actions\BuyingGroup\InviteBuyingGroupMembership;
use App\Enums\BuyingGroupMemberStatus;
use App\Enums\ExceptionSeverity;
use App\Enums\ExceptionStatus;
use App\Enums\InboundTransport;
use App\Enums\SerializationProvider;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Pages\AuthorizedPartnerMatrix;
use App\Filament\App\Pages\MemberNetworkHealth;
use App\Models\BuyingGroupMember;
use App\Models\BuyingGroupMemberMetric;
use App\Models\BuyingGroupMembership;
use App\Models\BuyingGroupPartnerFact;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Exceptions\ExceptionType;
use App\Models\InboundConnection;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\BuyingGroup\BuyingGroupNetworkSnapshots;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class BuyingGroupRollupTest extends TestCase
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

    /** @var list<int> */
    private array $exceptionIds = [];

    /** @var list<int> */
    private array $connectionIds = [];

    /** @var list<string> */
    private array $metricMemberTenantIds = [];

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
            tenancy()->end();
        }

        $demo2 = Tenant::query()->find(self::DEMO2_TENANT_ID);
        if ($demo2 !== null) {
            tenancy()->initialize($demo2);

            if ($this->metricMemberTenantIds !== []) {
                BuyingGroupMemberMetric::query()
                    ->whereIn('member_tenant_id', $this->metricMemberTenantIds)
                    ->delete();
                BuyingGroupPartnerFact::query()
                    ->whereIn('member_tenant_id', $this->metricMemberTenantIds)
                    ->delete();
                $this->metricMemberTenantIds = [];
            }

            if ($this->exceptionIds !== []) {
                ExceptionCase::query()->whereIn('id', $this->exceptionIds)->delete();
                $this->exceptionIds = [];
            }

            if ($this->connectionIds !== []) {
                InboundConnection::query()->whereIn('id', $this->connectionIds)->delete();
                $this->connectionIds = [];
            }

            if ($this->memberIds !== []) {
                BuyingGroupMember::query()->whereIn('id', $this->memberIds)->delete();
                $this->memberIds = [];
            }

            // Belt-and-suspenders: remove any leftover F3-named roster rows from prior failed runs.
            BuyingGroupMember::query()
                ->where('name', 'like', 'F3 %')
                ->delete();

            if ($this->userIds !== []) {
                User::query()->whereIn('id', $this->userIds)->delete();
                $this->userIds = [];
            }

            tenancy()->end();
        }

        $demo2?->forceFill(['profile' => TenantProfile::Pharmacy, 'status' => 'active'])->save();

        parent::tearDown();
    }

    #[Test]
    public function rollup_writes_metrics_for_active_hard_membership_and_pages_render(): void
    {
        Notification::fake();

        $buyingGroup = $this->initializeDemo2Tenant(TenantProfile::BuyingGroup);
        $pharmacy = $this->createPharmacyTenant();
        $this->metricMemberTenantIds[] = (string) $pharmacy->getKey();

        $bgOwner = $this->seedOwnerInCurrentTenant();

        $softOnly = BuyingGroupMember::query()->create([
            'name' => 'F3 Soft-Only Pharmacy',
            'status' => BuyingGroupMemberStatus::Active,
            'contact_email' => 'f3-soft@example.test',
        ]);
        $this->memberIds[] = (int) $softOnly->getKey();

        $linkedRoster = BuyingGroupMember::query()->create([
            'name' => 'F3 Linked Pharmacy',
            'status' => BuyingGroupMemberStatus::Active,
            'contact_email' => 'f3-linked@example.test',
        ]);
        $this->memberIds[] = (int) $linkedRoster->getKey();

        $membership = app(InviteBuyingGroupMembership::class)->invite(
            $buyingGroup,
            $pharmacy,
            $bgOwner,
            (int) $linkedRoster->getKey(),
        );
        $this->membershipIds[] = (int) $membership->getKey();

        tenancy()->end();
        tenancy()->initialize($pharmacy);
        $pharmacyOwner = $this->seedOwnerInCurrentTenant();
        app(AcceptBuyingGroupMembership::class)->accept($membership->fresh(), $pharmacyOwner);

        $type = ExceptionType::query()->orderBy('id')->first();
        $this->assertNotNull($type);

        $openCase = ExceptionCase::query()->create([
            'exception_type_id' => $type->getKey(),
            'title' => 'F3 open exception',
            'severity' => ExceptionSeverity::Medium,
            'status' => ExceptionStatus::New,
            'created_at' => now()->subDays(10),
            'updated_at' => now()->subDays(10),
        ]);
        $this->exceptionIds[] = (int) $openCase->getKey();

        $connection = InboundConnection::query()->create([
            'name' => 'F3 inbound',
            'serialization_provider' => SerializationProvider::CustomHttps,
            'transport' => InboundTransport::Https,
            'is_active' => true,
            'last_success_at' => now()->subHour(),
            'consecutive_failures' => 0,
        ]);
        $this->connectionIds[] = (int) $connection->getKey();

        tenancy()->end();

        $asOf = now()->toDateString();

        $this->artisan('tracepharma:buying-group-rollup', [
            '--buying-group' => (string) $buyingGroup->getKey(),
            '--member' => (string) $pharmacy->getKey(),
            '--as-of' => $asOf,
            '--sync' => true,
        ])->assertSuccessful();

        tenancy()->initialize($buyingGroup);

        $metric = BuyingGroupMemberMetric::query()
            ->where('member_tenant_id', $pharmacy->getKey())
            ->whereDate('as_of', $asOf)
            ->first();

        $this->assertNotNull($metric);
        $this->assertGreaterThanOrEqual(1, (int) $metric->exceptions_open);
        $this->assertGreaterThanOrEqual(1, (int) $metric->exceptions_aging_7d);
        $this->assertNotNull($metric->last_epcis_success_at);
        $this->assertNotNull($metric->health_score);

        // Idempotent re-run keeps a single row for the day.
        $this->artisan('tracepharma:buying-group-rollup', [
            '--buying-group' => (string) $buyingGroup->getKey(),
            '--member' => (string) $pharmacy->getKey(),
            '--as-of' => $asOf,
            '--sync' => true,
        ])->assertSuccessful();

        tenancy()->initialize($buyingGroup);

        $this->assertSame(
            1,
            BuyingGroupMemberMetric::query()
                ->where('member_tenant_id', $pharmacy->getKey())
                ->whereDate('as_of', $asOf)
                ->count(),
        );

        $rows = app(BuyingGroupNetworkSnapshots::class)->memberHealthRows();
        $softRow = $rows->firstWhere('member_name', 'F3 Soft-Only Pharmacy');
        $linkedRow = $rows->firstWhere('member_name', 'F3 Linked Pharmacy');

        $this->assertNotNull($softRow);
        $this->assertTrue($softRow['soft_only']);
        $this->assertSame('N/A', $softRow['atp_gap_count']);
        $this->assertSame('Link tenant for live metrics', $softRow['empty_hint']);

        $this->assertNotNull($linkedRow);
        $this->assertTrue($linkedRow['live_metrics']);
        $this->assertGreaterThanOrEqual(1, (int) $linkedRow['exceptions_open']);

        Filament::setCurrentPanel(Filament::getPanel('app'));
        $this->actingAs($bgOwner);

        $this->assertTrue(MemberNetworkHealth::canAccess());
        $this->assertTrue(AuthorizedPartnerMatrix::canAccess());

        Livewire::test(MemberNetworkHealth::class)
            ->assertSuccessful()
            ->assertSee('F3 Soft-Only Pharmacy')
            ->assertSee('Link tenant for live metrics')
            ->assertSee('F3 Linked Pharmacy');

        Livewire::test(AuthorizedPartnerMatrix::class)
            ->assertSuccessful()
            ->assertSee('F3 Soft-Only Pharmacy')
            ->assertSee('N/A');
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
        $id = (string) Str::uuid();
        $tenant = Tenant::withoutEvents(fn () => Tenant::query()->create([
            'id' => $id,
            'name' => 'F3 Pharmacy Member',
            'profile' => TenantProfile::Pharmacy,
            'status' => 'active',
            'tenancy_db_name' => self::DEMO2_DATABASE,
        ]));
        $tenant->domains()->create(['domain' => 'f3-'.Str::lower(Str::random(8)).'.internal.vatengi.com']);
        $this->createdTenantIds[] = $id;

        return $tenant;
    }
}
