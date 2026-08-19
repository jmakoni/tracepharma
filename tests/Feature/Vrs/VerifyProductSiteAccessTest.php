<?php

namespace Tests\Feature\Vrs;

use App\Enums\ExceptionSeverity;
use App\Enums\ExceptionStatus;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Pages\VerifyProduct;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Exceptions\ExceptionType;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Verification;
use App\Support\Auth\Permissions;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\Gs1\Gtin;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class VerifyProductSiteAccessTest extends TestCase
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
    private array $userIds = [];

    /** @var list<int> */
    private array $caseIds = [];

    #[Test]
    public function site_restricted_user_does_not_see_other_users_or_sites_in_todays_verifications(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            [$siteA, $siteB] = $this->createOwnedSites();
            $restricted = $this->createUserWithSites([(int) $siteA->getKey()]);
            $otherUser = User::factory()->create();
            $this->userIds[] = (int) $otherUser->getKey();

            $ownUnlinked = $this->createTodaysVerification([
                'serial' => 'VP-TODAY-OWN-'.Str::random(4),
                'verified_by' => $restricted->getKey(),
            ]);
            $otherUnlinked = $this->createTodaysVerification([
                'serial' => 'VP-TODAY-OTHER-'.Str::random(4),
                'verified_by' => $otherUser->getKey(),
            ]);
            $siteAException = $this->createExceptionCase((int) $siteA->getKey());
            $siteBException = $this->createExceptionCase((int) $siteB->getKey());
            $linkedSiteA = $this->createTodaysVerification([
                'serial' => 'VP-TODAY-A-'.Str::random(4),
                'exception_id' => $siteAException->getKey(),
                'verified_by' => $otherUser->getKey(),
            ]);
            $linkedSiteB = $this->createTodaysVerification([
                'serial' => 'VP-TODAY-B-'.Str::random(4),
                'exception_id' => $siteBException->getKey(),
                'verified_by' => $otherUser->getKey(),
            ]);

            $this->actingAs($restricted);
            $this->assertFalse($restricted->can(Permissions::SitesAccessAll));

            Filament::setCurrentPanel(Filament::getPanel('app'));

            $visible = Livewire::test(VerifyProduct::class)
                ->instance()
                ->todaysVerifications()
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            $this->assertContains((int) $ownUnlinked->getKey(), $visible);
            $this->assertContains((int) $linkedSiteA->getKey(), $visible);
            $this->assertNotContains((int) $otherUnlinked->getKey(), $visible);
            $this->assertNotContains((int) $linkedSiteB->getKey(), $visible);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function access_all_user_sees_all_todays_verifications(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            [$siteA, $siteB] = $this->createOwnedSites();
            $owner = $this->actingOwner();
            $otherUser = User::factory()->create();
            $this->userIds[] = (int) $otherUser->getKey();

            $ownUnlinked = $this->createTodaysVerification([
                'serial' => 'VP-TODAY-ALL-OWN-'.Str::random(4),
                'verified_by' => $owner->getKey(),
            ]);
            $otherUnlinked = $this->createTodaysVerification([
                'serial' => 'VP-TODAY-ALL-OTHER-'.Str::random(4),
                'verified_by' => $otherUser->getKey(),
            ]);
            $siteAException = $this->createExceptionCase((int) $siteA->getKey());
            $siteBException = $this->createExceptionCase((int) $siteB->getKey());
            $linkedSiteA = $this->createTodaysVerification([
                'serial' => 'VP-TODAY-ALL-A-'.Str::random(4),
                'exception_id' => $siteAException->getKey(),
            ]);
            $linkedSiteB = $this->createTodaysVerification([
                'serial' => 'VP-TODAY-ALL-B-'.Str::random(4),
                'exception_id' => $siteBException->getKey(),
            ]);

            $this->assertTrue($owner->can(Permissions::SitesAccessAll));

            Filament::setCurrentPanel(Filament::getPanel('app'));

            $visible = Livewire::test(VerifyProduct::class)
                ->instance()
                ->todaysVerifications()
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            $this->assertContains((int) $ownUnlinked->getKey(), $visible);
            $this->assertContains((int) $otherUnlinked->getKey(), $visible);
            $this->assertContains((int) $linkedSiteA->getKey(), $visible);
            $this->assertContains((int) $linkedSiteB->getKey(), $visible);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function site_restricted_user_does_not_see_tenant_wide_vrs_scorecard(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->seedVerifications();

            $site = Site::factory()->owned()->create(['name' => 'Restricted VRS Site']);
            $this->siteIds[] = (int) $site->getKey();

            $user = $this->createUserWithSites([(int) $site->getKey()]);
            $this->actingAs($user);

            $this->assertFalse($user->can(Permissions::SitesAccessAll));

            Filament::setCurrentPanel(Filament::getPanel('app'));

            Livewire::test(VerifyProduct::class)
                ->assertOk()
                ->assertSet('lastScanMessage', null)
                ->assertDontSee('Allowed (24h)')
                ->assertDontSee('Blocked (24h)')
                ->assertDontSee('Deferred (24h)')
                ->assertDontSee('Unavailable (24h)');

            $component = Livewire::test(VerifyProduct::class);
            $this->assertFalse($component->instance()->showScorecard());
            $this->assertNull($component->instance()->scorecardMetrics());
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function access_all_user_sees_vrs_scorecard(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->seedVerifications();

            $user = $this->actingOwner();
            $this->assertTrue($user->can(Permissions::SitesAccessAll));

            Filament::setCurrentPanel(Filament::getPanel('app'));

            Livewire::test(VerifyProduct::class)
                ->assertOk()
                ->assertSee('Allowed (24h)')
                ->assertSee('Blocked (24h)')
                ->assertSee('Deferred (24h)')
                ->assertSee('Unavailable (24h)');

            $component = Livewire::test(VerifyProduct::class);
            $this->assertTrue($component->instance()->showScorecard());
            $metrics = $component->instance()->scorecardMetrics();
            $this->assertNotNull($metrics);
            $this->assertGreaterThanOrEqual(1, $metrics['allowed']);
            $this->assertGreaterThanOrEqual(1, $metrics['blocked']);
        } finally {
            $this->cleanup($tenant);
        }
    }

    private function seedVerifications(): void
    {
        $since = now()->subHours(6);

        $this->verificationIds[] = (int) Verification::query()->create([
            'gtin14' => '30301164005162',
            'serial' => 'VP-ALLOW-'.random_int(1000, 9999),
            'status' => 'verified',
            'created_at' => $since,
        ])->getKey();

        $this->verificationIds[] = (int) Verification::query()->create([
            'gtin14' => '30301164005162',
            'serial' => 'VP-BLOCK-'.random_int(1000, 9999),
            'status' => 'failed',
            'created_at' => $since,
        ])->getKey();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createTodaysVerification(array $attributes = []): Verification
    {
        $verification = Verification::query()->create([
            'gtin14' => '30301164005162',
            'serial' => 'VP-TODAY-'.Str::random(4),
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
            'document_id' => null,
            'title' => 'Verify product today '.Str::random(4),
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
            'name' => 'Verify Product Site A '.Str::random(5),
            'gln' => $this->uniqueGln(),
            'is_active' => true,
        ]);
        $siteB = Site::factory()->owned()->create([
            'name' => 'Verify Product Site B '.Str::random(5),
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
            'code' => 'vp_today_'.Str::lower(Str::random(4)),
            'name' => 'Verify product today site type',
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
