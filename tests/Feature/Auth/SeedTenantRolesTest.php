<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Actions\Tenants\ProvisionTenantPair;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Jobs\SeedTenantRoles;
use App\Models\Tenant;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\TenantHostname;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Stancl\Tenancy\Database\Models\Domain;
use Tests\TestCase;

class SeedTenantRolesTest extends TestCase
{
    use DatabaseTransactions;

    /** @var list<string> */
    private array $slugs = [];

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        foreach ($this->slugs as $slug) {
            foreach (TenantHostname::PAIR_ENVIRONMENTS as $environment) {
                $domain = Domain::query()
                    ->where('domain', TenantHostname::forSlug($slug, $environment))
                    ->first();

                if ($domain === null) {
                    continue;
                }

                Tenant::withoutEvents(
                    fn () => Tenant::query()->find($domain->tenant_id)?->delete(),
                );
            }
        }

        parent::tearDown();
    }

    #[Test]
    public function job_seeds_roles_for_tenant_profile(): void
    {
        $slug = 'roles-'.Str::lower(Str::random(8));
        $this->slugs[] = $slug;

        $tenant = app(ProvisionTenantPair::class)->create($slug, [
            'name' => 'Role seed test '.$slug,
            'profile' => TenantProfile::Manufacturer,
            'status' => 'active',
        ]);

        $tenant->run(function (): void {
            Role::query()->delete();
        });

        (new SeedTenantRoles($tenant))->handle(app(TenantRoleSeeder::class));

        $tenant->run(function (): void {
            foreach (TenantRole::forProfile(TenantProfile::Manufacturer) as $role) {
                $this->assertDatabaseHas('roles', [
                    'name' => $role->value,
                    'guard_name' => 'web',
                ]);
            }
        });
    }

    #[Test]
    public function job_seeds_distinct_roles_for_buying_group_profile(): void
    {
        $slug = 'bg-'.Str::lower(Str::random(8));
        $this->slugs[] = $slug;

        $tenant = app(ProvisionTenantPair::class)->create($slug, [
            'name' => 'Buying group role test '.$slug,
            'profile' => TenantProfile::BuyingGroup,
            'status' => 'active',
        ]);

        $tenant->run(function (): void {
            Role::query()->delete();
        });

        (new SeedTenantRoles($tenant))->handle(app(TenantRoleSeeder::class));

        $tenant->run(function (): void {
            $this->assertDatabaseHas('roles', ['name' => TenantRole::SupportEngineer->value]);
            $this->assertDatabaseMissing('roles', ['name' => TenantRole::ReceivingTechnician->value]);
        });
    }
}
