<?php

namespace Tests\Feature\MasterData;

use App\Enums\PartnerType;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Resources\ReadPoints\Pages\CreateReadPoint;
use App\Models\ReadPoint;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use DomainException;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A read point carries no GLN column: its SGLN is the only thing that says where an event
 * was read, and only the GS1 Pure Identity form can be parsed back to a GLN. A URN saved
 * in any other shape leaves every event read there unresolvable.
 *
 * GLNs are prefixed 094227 so rows stay traceable in the shared demo2 tenant.
 */
class ReadPointSglnFormTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private const GLN_PREFIX = '094227';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $userIds = [];

    /** @var list<int> */
    private array $readPointIds = [];

    #[Test]
    public function an_sgln_that_is_not_a_pure_identity_urn_is_refused(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $site = $this->site();
            $this->actAsMasterDataOwner();

            Livewire::test(CreateReadPoint::class)
                ->fillForm([
                    'site_id' => $site->getKey(),
                    'name' => 'Read Point Bad Sgln 094227',
                    // The legacy two-segment form: no company-prefix split to read.
                    'sgln' => 'urn:epc:id:sgln:094227100000.0',
                ])
                ->call('create')
                ->assertHasFormErrors(['sgln']);

            $this->assertSame(
                0,
                ReadPoint::query()->where('name', 'Read Point Bad Sgln 094227')->count(),
                'A read point whose SGLN cannot be parsed must not persist.',
            );
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function a_pure_identity_sgln_is_accepted(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $site = $this->site();
            $this->actAsMasterDataOwner();

            Livewire::test(CreateReadPoint::class)
                ->fillForm([
                    'site_id' => $site->getKey(),
                    'name' => 'Read Point Good Sgln 094227',
                    'sgln' => 'urn:epc:id:sgln:0942271.00000.1',
                ])
                ->call('create')
                ->assertHasNoFormErrors();

            $readPoint = ReadPoint::query()->where('name', 'Read Point Good Sgln 094227')->first();

            $this->assertNotNull($readPoint);
            $this->readPointIds[] = (int) $readPoint->getKey();
            $this->assertSame('urn:epc:id:sgln:0942271.00000.1', $readPoint->sgln);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function a_read_point_can_belong_to_a_company_owned_site(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $site = $this->organizationSite();
            $this->actAsMasterDataOwner();

            Livewire::test(CreateReadPoint::class)
                ->fillForm([
                    'site_id' => $site->getKey(),
                    'name' => 'Read Point Org Site 094227',
                    'sgln' => 'urn:epc:id:sgln:0942271.00000.2',
                ])
                ->call('create')
                ->assertHasNoFormErrors();

            $readPoint = ReadPoint::query()->where('name', 'Read Point Org Site 094227')->first();

            $this->assertNotNull($readPoint);
            $this->readPointIds[] = (int) $readPoint->getKey();
            $this->assertSame($site->getKey(), $readPoint->site_id);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function a_read_point_without_a_site_is_refused(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->actAsMasterDataOwner();

            Livewire::test(CreateReadPoint::class)
                ->fillForm([
                    'site_id' => null,
                    'name' => 'Read Point No Site 094227',
                    'sgln' => 'urn:epc:id:sgln:0942271.00000.3',
                ])
                ->call('create')
                ->assertHasFormErrors(['site_id']);

            $this->assertSame(
                0,
                ReadPoint::query()->where('name', 'Read Point No Site 094227')->count(),
            );

            $this->expectException(DomainException::class);
            $this->expectExceptionMessage('A read point must belong to a tenant site.');

            ReadPoint::query()->create([
                'name' => 'Read Point No Site Model 094227',
                'sgln' => 'urn:epc:id:sgln:0942271.00000.4',
            ]);
        } finally {
            $this->cleanup();
        }
    }

    private function site(): Site
    {
        $partner = TradingPartner::query()->create([
            'name' => 'Read Point Partner 094227 '.uniqid(),
            'gln' => self::GLN_PREFIX.str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT),
            'partner_type' => PartnerType::Wholesaler,
            'country_code' => 'US',
            'is_active' => true,
        ]);

        return Site::query()->create([
            'trading_partner_id' => $partner->getKey(),
            'name' => 'Read Point Dock 094227 '.uniqid(),
            'gln' => self::GLN_PREFIX.str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT),
            'country_code' => 'US',
            'is_active' => true,
            'is_organization_facility' => false,
        ]);
    }

    private function organizationSite(): Site
    {
        return Site::query()->create([
            'trading_partner_id' => null,
            'is_organization_facility' => true,
            'name' => 'Read Point Org HQ 094227 '.uniqid(),
            'gln' => self::GLN_PREFIX.str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT),
            'country_code' => 'US',
            'is_active' => true,
        ]);
    }

    private function actAsMasterDataOwner(): void
    {
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);

        $user = User::factory()->create();
        $user->assignRole(TenantRole::Owner->value);
        $this->userIds[] = (int) $user->getKey();

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('app'));
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
            if ($this->readPointIds !== []) {
                ReadPoint::query()->whereIn('id', $this->readPointIds)->delete();
            }

            DB::table('read_points')->where('name', 'like', '%094227%')->delete();
            DB::table('sites')->where('gln', 'like', self::GLN_PREFIX.'%')->delete();
            DB::table('trading_partners')->where('gln', 'like', self::GLN_PREFIX.'%')->delete();

            if ($this->userIds !== []) {
                DB::table('model_has_roles')
                    ->where('model_type', User::class)
                    ->whereIn('model_id', $this->userIds)
                    ->delete();
                User::query()->whereIn('id', $this->userIds)->delete();
            }

            tenancy()->end();
        }

        $this->userIds = [];
        $this->readPointIds = [];
    }
}
