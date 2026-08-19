<?php

namespace Tests\Feature\Dashboard;

use App\Enums\ExceptionSeverity;
use App\Enums\ExceptionStatus;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Exceptions\ExceptionType;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Verification;
use App\Support\Auth\Permissions;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\Dashboard\AnalyticsMetrics;
use App\Support\Gs1\Gtin;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AnalyticsVrsRatesSiteAccessTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $verificationIds = [];

    /** @var list<int> */
    private array $siteIds = [];

    /** @var list<int> */
    private array $caseIds = [];

    /** @var list<int> */
    private array $userIds = [];

    #[Test]
    public function site_restricted_user_rates_exclude_tenant_wide_verifications(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            [$siteA, $siteB] = $this->createOwnedSites();
            $restricted = $this->createUserWithSites([(int) $siteA->getKey()]);
            $otherUser = User::factory()->create();
            $this->userIds[] = (int) $otherUser->getKey();

            $this->actingAs($restricted);
            $this->assertFalse($restricted->can(Permissions::SitesAccessAll));

            $before = AnalyticsMetrics::make($restricted, 30)->vrsRates();

            $this->createVerification([
                'serial' => 'AR-OWN-'.Str::random(4),
                'status' => 'verified',
                'verified_by' => $restricted->getKey(),
            ]);
            $this->createVerification([
                'serial' => 'AR-OTHER-'.Str::random(4),
                'status' => 'verified',
                'verified_by' => $otherUser->getKey(),
            ]);
            $this->createVerification([
                'serial' => 'AR-BLOCK-OTHER-'.Str::random(4),
                'status' => 'failed',
                'verified_by' => $otherUser->getKey(),
            ]);

            $siteAException = $this->createExceptionCase((int) $siteA->getKey());
            $siteBException = $this->createExceptionCase((int) $siteB->getKey());
            $this->createVerification([
                'serial' => 'AR-SITE-A-'.Str::random(4),
                'status' => 'verified',
                'exception_id' => $siteAException->getKey(),
                'verified_by' => $otherUser->getKey(),
            ]);
            $this->createVerification([
                'serial' => 'AR-SITE-B-'.Str::random(4),
                'status' => 'failed',
                'exception_id' => $siteBException->getKey(),
                'verified_by' => $otherUser->getKey(),
            ]);

            $rates = AnalyticsMetrics::make($restricted, 30)->vrsRates();

            $this->assertSame($before['allowed'] + 2, $rates['allowed']);
            $this->assertSame($before['blocked'], $rates['blocked']);
            $this->assertSame($before['total'] + 2, $rates['total']);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function site_restricted_user_with_site_filter_keeps_actor_owned_unlinked_verifications(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            [$siteA, $siteB] = $this->createOwnedSites();
            $restricted = $this->createUserWithSites([(int) $siteA->getKey()]);
            $otherUser = User::factory()->create();
            $this->userIds[] = (int) $otherUser->getKey();

            $this->actingAs($restricted);
            $this->assertFalse($restricted->can(Permissions::SitesAccessAll));

            $siteFilter = (int) $siteA->getKey();
            $before = AnalyticsMetrics::make($restricted, 30, $siteFilter)->vrsRates();

            $this->createVerification([
                'serial' => 'AR-FILTER-OWN-'.Str::random(4),
                'status' => 'verified',
                'verified_by' => $restricted->getKey(),
            ]);
            $this->createVerification([
                'serial' => 'AR-FILTER-OTHER-'.Str::random(4),
                'status' => 'verified',
                'verified_by' => $otherUser->getKey(),
            ]);

            $siteAException = $this->createExceptionCase($siteFilter);
            $siteBException = $this->createExceptionCase((int) $siteB->getKey());
            $this->createVerification([
                'serial' => 'AR-FILTER-SITE-A-'.Str::random(4),
                'status' => 'verified',
                'exception_id' => $siteAException->getKey(),
                'verified_by' => $otherUser->getKey(),
            ]);
            $this->createVerification([
                'serial' => 'AR-FILTER-SITE-B-'.Str::random(4),
                'status' => 'failed',
                'exception_id' => $siteBException->getKey(),
                'verified_by' => $otherUser->getKey(),
            ]);

            $rates = AnalyticsMetrics::make($restricted, 30, $siteFilter)->vrsRates();

            $this->assertSame($before['allowed'] + 2, $rates['allowed']);
            $this->assertSame($before['blocked'], $rates['blocked']);
            $this->assertSame($before['total'] + 2, $rates['total']);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function access_all_user_with_site_filter_keeps_tenant_wide_unlinked_verifications(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            [$siteA, $siteB] = $this->createOwnedSites();
            $owner = $this->actingOwner();
            $otherUser = User::factory()->create();
            $this->userIds[] = (int) $otherUser->getKey();

            $this->assertTrue($owner->can(Permissions::SitesAccessAll));

            $siteFilter = (int) $siteA->getKey();
            $before = AnalyticsMetrics::make($owner, 30, $siteFilter)->vrsRates();

            $this->createVerification([
                'serial' => 'AR-FILTER-ALL-OWN-'.Str::random(4),
                'status' => 'verified',
                'verified_by' => $owner->getKey(),
            ]);
            $this->createVerification([
                'serial' => 'AR-FILTER-ALL-OTHER-'.Str::random(4),
                'status' => 'verified',
                'verified_by' => $otherUser->getKey(),
            ]);

            $siteAException = $this->createExceptionCase($siteFilter);
            $siteBException = $this->createExceptionCase((int) $siteB->getKey());
            $this->createVerification([
                'serial' => 'AR-FILTER-ALL-A-'.Str::random(4),
                'status' => 'verified',
                'exception_id' => $siteAException->getKey(),
            ]);
            $this->createVerification([
                'serial' => 'AR-FILTER-ALL-B-'.Str::random(4),
                'status' => 'failed',
                'exception_id' => $siteBException->getKey(),
            ]);

            $rates = AnalyticsMetrics::make($owner, 30, $siteFilter)->vrsRates();

            $this->assertSame($before['allowed'] + 3, $rates['allowed']);
            $this->assertSame($before['blocked'], $rates['blocked']);
            $this->assertSame($before['total'] + 3, $rates['total']);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function access_all_user_sees_tenant_wide_vrs_rates(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            [$siteA, $siteB] = $this->createOwnedSites();
            $owner = $this->actingOwner();
            $otherUser = User::factory()->create();
            $this->userIds[] = (int) $otherUser->getKey();

            $this->assertTrue($owner->can(Permissions::SitesAccessAll));

            $before = AnalyticsMetrics::make($owner, 30)->vrsRates();

            $this->createVerification([
                'serial' => 'AR-ALL-OWN-'.Str::random(4),
                'status' => 'verified',
                'verified_by' => $owner->getKey(),
            ]);
            $this->createVerification([
                'serial' => 'AR-ALL-OTHER-'.Str::random(4),
                'status' => 'verified',
                'verified_by' => $otherUser->getKey(),
            ]);
            $this->createVerification([
                'serial' => 'AR-ALL-BLOCK-'.Str::random(4),
                'status' => 'failed',
                'verified_by' => $otherUser->getKey(),
            ]);

            $siteAException = $this->createExceptionCase((int) $siteA->getKey());
            $siteBException = $this->createExceptionCase((int) $siteB->getKey());
            $this->createVerification([
                'serial' => 'AR-ALL-A-'.Str::random(4),
                'status' => 'verified',
                'exception_id' => $siteAException->getKey(),
            ]);
            $this->createVerification([
                'serial' => 'AR-ALL-B-'.Str::random(4),
                'status' => 'failed',
                'exception_id' => $siteBException->getKey(),
            ]);

            $rates = AnalyticsMetrics::make($owner, 30)->vrsRates();

            $this->assertSame($before['allowed'] + 3, $rates['allowed']);
            $this->assertSame($before['blocked'] + 2, $rates['blocked']);
            $this->assertSame($before['total'] + 5, $rates['total']);
        } finally {
            $this->cleanup($tenant);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createVerification(array $attributes = []): Verification
    {
        $verification = Verification::query()->create([
            'gtin14' => '30301164005162',
            'serial' => 'AR-DEFAULT-'.Str::random(4),
            'status' => 'verified',
            'created_at' => now()->subHours(2),
            ...$attributes,
        ]);
        $this->verificationIds[] = (int) $verification->getKey();

        return $verification;
    }

    private function createExceptionCase(int $siteId): ExceptionCase
    {
        $case = ExceptionCase::query()->create([
            'exception_type_id' => $this->exceptionTypeId(),
            'site_id' => $siteId,
            'title' => 'Analytics VRS '.Str::random(4),
            'description' => 'Test exception',
            'severity' => ExceptionSeverity::Medium,
            'status' => ExceptionStatus::New,
        ]);
        $this->caseIds[] = (int) $case->getKey();

        return $case;
    }

    /**
     * @return array{0: Site, 1: Site}
     */
    private function createOwnedSites(): array
    {
        $siteA = Site::factory()->owned()->create([
            'name' => 'Analytics VRS Site A '.Str::random(5),
            'gln' => $this->uniqueGln(),
            'is_active' => true,
        ]);
        $siteB = Site::factory()->owned()->create([
            'name' => 'Analytics VRS Site B '.Str::random(5),
            'gln' => $this->uniqueGln(),
            'is_active' => true,
        ]);
        $this->siteIds = [(int) $siteA->getKey(), (int) $siteB->getKey()];

        return [$siteA, $siteB];
    }

    private function exceptionTypeId(): int
    {
        $typeId = ExceptionType::query()->value('id');
        if ($typeId !== null) {
            return (int) $typeId;
        }

        return (int) ExceptionType::query()->create([
            'code' => 'ar_vrs_'.Str::lower(Str::random(4)),
            'name' => 'Analytics VRS site type',
            'is_active' => true,
        ])->id;
    }

    private function uniqueGln(): string
    {
        do {
            $body = '03'.str_pad((string) random_int(0, 9999999999), 10, '0', STR_PAD_LEFT);
            $gln = $body.Gtin::checkDigit($body);
        } while (Site::query()->where('gln', $gln)->exists());

        return $gln;
    }

    /**
     * @param  list<int>  $siteIds
     */
    private function createUserWithSites(array $siteIds): User
    {
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::factory()->create();
        $user->syncSites($siteIds);
        $this->userIds[] = (int) $user->getKey();

        return $user;
    }

    private function actingOwner(): User
    {
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::factory()->create();
        $user->assignRole(TenantRole::Owner->value);
        $this->actingAs($user);
        $this->userIds[] = (int) $user->getKey();

        return $user;
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
            $tenant->forceFill(['profile' => TenantProfile::Pharmacy])->save();
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

    private function cleanup(Tenant $tenant): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        if ($this->verificationIds !== []) {
            Verification::query()->whereKey($this->verificationIds)->delete();
            $this->verificationIds = [];
        }

        if ($this->caseIds !== []) {
            ExceptionCase::query()->whereKey($this->caseIds)->delete();
            $this->caseIds = [];
        }

        foreach ($this->siteIds as $siteId) {
            Site::query()->whereKey($siteId)->delete();
        }
        $this->siteIds = [];

        if ($this->userIds !== []) {
            User::query()->whereKey($this->userIds)->delete();
            $this->userIds = [];
        }

        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        tenancy()->end();
    }
}
