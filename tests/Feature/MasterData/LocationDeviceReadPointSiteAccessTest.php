<?php

declare(strict_types=1);

namespace Tests\Feature\MasterData;

use App\Enums\SsccNumberRangeScope;
use App\Enums\SsccNumberRangeStatus;
use App\Enums\TenantProfile;
use App\Filament\App\Resources\LocationDevices\LocationDeviceResource;
use App\Filament\App\Resources\ReadPoints\ReadPointResource;
use App\Filament\App\Resources\SsccNumberRanges\SsccNumberRangeResource;
use App\Models\LocationDevice;
use App\Models\ReadPoint;
use App\Models\Site;
use App\Models\SsccNumberRange;
use App\Models\Tenant;
use App\Models\User;
use App\Policies\LocationDevicePolicy;
use App\Policies\ReadPointPolicy;
use App\Policies\SsccNumberRangePolicy;
use App\Support\Auth\Permissions;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\Gs1\Gtin;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class LocationDeviceReadPointSiteAccessTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $siteIds = [];

    /** @var list<int> */
    private array $userIds = [];

    /** @var list<int> */
    private array $locationDeviceIds = [];

    /** @var list<int> */
    private array $readPointIds = [];

    /** @var list<int> */
    private array $ssccRangeIds = [];

    #[Test]
    public function site_restricted_user_only_sees_location_devices_and_read_points_at_their_sites(): void
    {
        $this->initializeDemo2Tenant();

        try {
            [$siteA, $siteB] = $this->createOwnedSites();

            $deviceA = LocationDevice::query()->create([
                'site_id' => $siteA->getKey(),
                'name' => 'LD A '.Str::random(4),
                'gln' => $this->uniqueGln(),
            ]);
            $deviceB = LocationDevice::query()->create([
                'site_id' => $siteB->getKey(),
                'name' => 'LD B '.Str::random(4),
                'gln' => $this->uniqueGln(),
            ]);
            $this->locationDeviceIds = [(int) $deviceA->getKey(), (int) $deviceB->getKey()];

            $rpA = ReadPoint::query()->create([
                'site_id' => $siteA->getKey(),
                'name' => 'RP A '.Str::random(4),
                'sgln' => 'urn:epc:id:sgln:030116.00000'.random_int(10, 99).'.0',
                'is_active' => true,
            ]);
            $rpB = ReadPoint::query()->create([
                'site_id' => $siteB->getKey(),
                'name' => 'RP B '.Str::random(4),
                'sgln' => 'urn:epc:id:sgln:030116.00000'.random_int(10, 99).'.1',
                'is_active' => true,
            ]);
            $this->readPointIds = [(int) $rpA->getKey(), (int) $rpB->getKey()];

            $restricted = $this->createUserWithSites([(int) $siteA->getKey()]);
            $this->actingAs($restricted);
            $this->assertFalse($restricted->can(Permissions::SitesAccessAll));

            $visibleDevices = LocationDeviceResource::getEloquentQuery()
                ->whereIn('id', $this->locationDeviceIds)
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();
            $this->assertSame([(int) $deviceA->getKey()], $visibleDevices);

            $visiblePoints = ReadPointResource::getEloquentQuery()
                ->whereIn('id', $this->readPointIds)
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();
            $this->assertSame([(int) $rpA->getKey()], $visiblePoints);

            $this->assertTrue((new LocationDevicePolicy)->view($restricted, $deviceA));
            $this->assertFalse((new LocationDevicePolicy)->view($restricted, $deviceB));
            $this->assertTrue((new ReadPointPolicy)->view($restricted, $rpA));
            $this->assertFalse((new ReadPointPolicy)->view($restricted, $rpB));
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function sscc_number_range_query_hides_foreign_site_ranges(): void
    {
        $this->initializeDemo2Tenant();

        try {
            [$siteA, $siteB] = $this->createOwnedSites();

            $rangeA = SsccNumberRange::query()->create([
                'name' => 'range-a-'.Str::random(4),
                'scope' => SsccNumberRangeScope::Site,
                'site_id' => $siteA->getKey(),
                'company_prefix' => '030116',
                'extension_digit' => 0,
                'index' => 1,
                'increment_by' => 1,
                'range_size' => 100,
                'start_number' => 1,
                'current_number' => 1,
                'threshold_percentage' => 80,
                'status' => SsccNumberRangeStatus::Active,
                'remaining' => 100,
            ]);
            $rangeB = SsccNumberRange::query()->create([
                'name' => 'range-b-'.Str::random(4),
                'scope' => SsccNumberRangeScope::Site,
                'site_id' => $siteB->getKey(),
                'company_prefix' => '030116',
                'extension_digit' => 0,
                'index' => 2,
                'increment_by' => 1,
                'range_size' => 100,
                'start_number' => 1,
                'current_number' => 1,
                'threshold_percentage' => 80,
                'status' => SsccNumberRangeStatus::Active,
                'remaining' => 100,
            ]);
            $this->ssccRangeIds = [(int) $rangeA->getKey(), (int) $rangeB->getKey()];

            $restricted = $this->createUserWithSites([(int) $siteA->getKey()]);
            $this->actingAs($restricted);

            $visible = SsccNumberRangeResource::getEloquentQuery()
                ->whereIn('id', $this->ssccRangeIds)
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();
            $this->assertSame([(int) $rangeA->getKey()], $visible);

            $policy = new SsccNumberRangePolicy;
            $this->assertTrue($policy->view($restricted, $rangeA));
            $this->assertFalse($policy->view($restricted, $rangeB));
        } finally {
            $this->cleanup();
        }
    }

    /**
     * @return array{0: Site, 1: Site}
     */
    private function createOwnedSites(): array
    {
        $siteA = Site::factory()->owned()->create([
            'name' => 'MD Site A '.Str::random(4),
            'gln' => $this->uniqueGln(),
            'is_active' => true,
        ]);
        $siteB = Site::factory()->owned()->create([
            'name' => 'MD Site B '.Str::random(4),
            'gln' => $this->uniqueGln(),
            'is_active' => true,
        ]);
        $this->siteIds = [(int) $siteA->getKey(), (int) $siteB->getKey()];

        return [$siteA, $siteB];
    }

    /**
     * @param  list<int>  $siteIds
     */
    private function createUserWithSites(array $siteIds): User
    {
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::factory()->create([
            'name' => 'Site Restricted '.Str::random(4),
            'email' => 'site-'.Str::random(8).'@example.test',
        ]);
        $user->syncSites($siteIds);
        $user->syncRoles([]);
        $this->userIds[] = (int) $user->getKey();

        return $user->fresh() ?? $user;
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
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);

        return $tenant;
    }

    private function cleanup(): void
    {
        if ($this->locationDeviceIds !== []) {
            LocationDevice::query()->whereIn('id', $this->locationDeviceIds)->delete();
        }
        if ($this->readPointIds !== []) {
            ReadPoint::query()->whereIn('id', $this->readPointIds)->delete();
        }
        if ($this->ssccRangeIds !== []) {
            SsccNumberRange::query()->whereIn('id', $this->ssccRangeIds)->delete();
        }
        if ($this->userIds !== []) {
            User::query()->whereIn('id', $this->userIds)->each(function (User $user): void {
                $user->sites()->detach();
                $user->delete();
            });
        }
        if ($this->siteIds !== []) {
            Site::query()->whereIn('id', $this->siteIds)->delete();
        }
        tenancy()->end();
    }
}
