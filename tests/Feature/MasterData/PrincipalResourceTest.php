<?php

namespace Tests\Feature\MasterData;

use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Resources\Principals\Pages\CreatePrincipal;
use App\Filament\App\Resources\Principals\PrincipalResource;
use App\Models\Principal;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\TenantFeatures;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PrincipalResourceTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    private ?TenantProfile $priorProfile = null;

    /** @var list<int> */
    private array $principalIds = [];

    /** @var list<int> */
    private array $siteIds = [];

    /** @var list<int> */
    private array $userIds = [];

    #[Test]
    public function logistics_3pl_owner_can_create_a_principal(): void
    {
        $this->initializeDemo2Tenant(TenantProfile::Logistics3pl);

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            $this->actingAs($this->createOwner(TenantProfile::Logistics3pl));

            $this->assertTrue(TenantFeatures::forTenant(tenant())->supportsPrincipals());
            $this->assertTrue(PrincipalResource::canAccess());

            $name = 'Principal Acme '.Str::random(6);

            Livewire::test(CreatePrincipal::class)
                ->fillForm([
                    'name' => $name,
                    'gln' => null,
                    'is_active' => true,
                ])
                ->call('create')
                ->assertHasNoFormErrors();

            $principal = Principal::query()->where('name', $name)->first();
            $this->assertNotNull($principal);
            $this->principalIds[] = (int) $principal->getKey();
            $this->assertTrue((bool) $principal->is_active);
            $this->assertNull($principal->gln);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function logistics_3pl_can_attach_principal_to_site(): void
    {
        $this->initializeDemo2Tenant(TenantProfile::Logistics3pl);

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            $this->actingAs($this->createOwner(TenantProfile::Logistics3pl));

            $principal = Principal::query()->create([
                'name' => 'Principal Site Tag '.Str::random(6),
                'gln' => null,
                'is_active' => true,
            ]);
            $this->principalIds[] = (int) $principal->getKey();

            $body12 = '036615'.str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $gln = $body12.$this->gs1CheckDigit($body12);

            $site = Site::query()->create([
                'name' => '3PL dock '.Str::random(6),
                'gln' => $gln,
                'is_active' => true,
                'is_headquarters' => false,
                'is_organization_facility' => true,
                'trading_partner_id' => null,
                'principal_id' => $principal->getKey(),
            ]);
            $this->siteIds[] = (int) $site->getKey();

            $this->assertSame((int) $principal->getKey(), (int) $site->fresh()->principal_id);
            $this->assertTrue($site->principal()->is($principal));
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function drug_wholesaler_cannot_access_principal_resource(): void
    {
        $this->initializeDemo2Tenant(TenantProfile::DrugWholesaler);

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            $this->actingAs($this->createOwner(TenantProfile::DrugWholesaler));

            $this->assertFalse(TenantFeatures::forTenant(tenant())->supportsPrincipals());
            $this->assertFalse(PrincipalResource::canAccess());
            Livewire::test(CreatePrincipal::class)->assertForbidden();
        } finally {
            $this->cleanup();
        }
    }

    private function createOwner(TenantProfile $profile): User
    {
        app(TenantRoleSeeder::class)->seedForProfile($profile);
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
                'name' => 'Demo Pharmacy',
                'profile' => $profile,
                'status' => 'active',
                'tenancy_db_name' => self::DEMO2_DATABASE,
            ]));
            $tenant->domains()->create(['domain' => self::DEMO2_DOMAIN]);
        } else {
            $tenant->domains()->firstOrCreate(['domain' => self::DEMO2_DOMAIN]);
        }

        $this->priorProfile = $tenant->profile instanceof TenantProfile
            ? $tenant->profile
            : TenantProfile::tryFrom((string) $tenant->profile);

        $tenant->forceFill(['profile' => $profile])->save();

        if (! self::$demo2TenantReady) {
            $this->artisan('tenants:migrate', [
                '--tenants' => [self::DEMO2_TENANT_ID],
                '--force' => true,
            ])->assertSuccessful();
            self::$demo2TenantReady = true;
        }

        tenancy()->initialize($tenant->fresh());

        return tenant() instanceof Tenant ? tenant() : $tenant;
    }

    private function gs1CheckDigit(string $body12): string
    {
        $sum = 0;
        foreach (str_split($body12) as $i => $digit) {
            $sum += ((int) $digit) * (($i % 2 === 0) ? 1 : 3);
        }

        return (string) ((10 - ($sum % 10)) % 10);
    }

    private function cleanup(): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        $tenant = tenant();

        foreach ($this->siteIds as $id) {
            Site::query()->whereKey($id)->delete();
        }
        $this->siteIds = [];

        foreach ($this->principalIds as $id) {
            Principal::query()->whereKey($id)->delete();
        }
        $this->principalIds = [];

        foreach ($this->userIds as $id) {
            User::query()->whereKey($id)->delete();
        }
        $this->userIds = [];

        if ($this->priorProfile !== null && $tenant !== null) {
            $tenant->forceFill(['profile' => $this->priorProfile])->save();
            $this->priorProfile = null;
        }

        tenancy()->end();
    }
}
