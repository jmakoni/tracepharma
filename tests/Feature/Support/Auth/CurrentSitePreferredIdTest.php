<?php

namespace Tests\Feature\Support\Auth;

use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\CurrentSite;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\Gs1\Gtin;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CurrentSitePreferredIdTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $siteIds = [];

    /** @var list<int> */
    private array $userIds = [];

    #[Test]
    public function preferred_id_returns_current_when_in_options(): void
    {
        $this->initializeDemo2Tenant();

        try {
            [$siteA, $siteB] = $this->createOwnedSites();
            $user = $this->createOwnerUser();
            $this->actingAs($user);
            CurrentSite::set((int) $siteA->getKey());

            $options = [
                (int) $siteA->getKey() => $siteA->name,
                (int) $siteB->getKey() => $siteB->name,
            ];

            $this->assertSame(
                (int) $siteA->getKey(),
                CurrentSite::preferredId(fallback: (int) $siteB->getKey(), options: $options),
            );
        } finally {
            session()->forget(CurrentSite::SESSION_KEY);
            $this->cleanup();
        }
    }

    #[Test]
    public function preferred_id_returns_fallback_when_current_not_in_options(): void
    {
        $this->initializeDemo2Tenant();

        try {
            [$siteA, $siteB] = $this->createOwnedSites();
            $user = $this->createOwnerUser();
            $this->actingAs($user);
            CurrentSite::set((int) $siteA->getKey());

            $options = [
                (int) $siteB->getKey() => $siteB->name,
            ];

            $this->assertSame(
                (int) $siteB->getKey(),
                CurrentSite::preferredId(fallback: (int) $siteB->getKey(), options: $options),
            );
        } finally {
            session()->forget(CurrentSite::SESSION_KEY);
            $this->cleanup();
        }
    }

    #[Test]
    public function preferred_id_returns_fallback_when_no_current(): void
    {
        $this->initializeDemo2Tenant();

        try {
            // No authenticated user → CurrentSite::id() is null.
            session()->forget(CurrentSite::SESSION_KEY);

            $this->assertSame(
                42,
                CurrentSite::preferredId(fallback: 42, options: [1 => 'A']),
            );
        } finally {
            session()->forget(CurrentSite::SESSION_KEY);
            $this->cleanup();
        }
    }

    /**
     * @return array{0: Site, 1: Site}
     */
    private function createOwnedSites(): array
    {
        $siteA = Site::factory()->owned()->create([
            'name' => 'Preferred A '.Str::random(6),
            'gln' => $this->uniqueGln(),
            'is_active' => true,
            'is_headquarters' => true,
        ]);
        $siteB = Site::factory()->owned()->create([
            'name' => 'Preferred B '.Str::random(6),
            'gln' => $this->uniqueGln(),
            'is_active' => true,
            'is_headquarters' => false,
        ]);
        $this->siteIds = [(int) $siteA->getKey(), (int) $siteB->getKey()];

        return [$siteA, $siteB];
    }

    private function createOwnerUser(): User
    {
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);

        $user = User::factory()->create([
            'email' => 'current-site-'.uniqid('', true).'@example.test',
        ]);
        $user->assignRole(TenantRole::Owner->value);
        $this->userIds[] = (int) $user->getKey();

        return $user;
    }

    private function uniqueGln(): string
    {
        do {
            $body = '03'.str_pad((string) random_int(0, 9999999999), 10, '0', STR_PAD_LEFT);
            $gln = $body.Gtin::checkDigit($body);
        } while (Site::query()->where('gln', $gln)->exists());

        return $gln;
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
        if (tenancy()->initialized) {
            if ($this->userIds !== []) {
                User::query()->whereIn('id', $this->userIds)->delete();
                $this->userIds = [];
            }

            if ($this->siteIds !== []) {
                Site::query()->whereIn('id', $this->siteIds)->delete();
                $this->siteIds = [];
            }

            tenancy()->end();
        }
    }
}
