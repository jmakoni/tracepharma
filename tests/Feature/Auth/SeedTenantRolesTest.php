<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Actions\Tenants\ProvisionTenantPair;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Resources\ReceivingSessions\ReceivingSessionResource;
use App\Jobs\SeedTenantRoles;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\Permissions;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\TenantHostname;
use App\Support\TenantSettings;
use Filament\Facades\Filament;
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
            $this->assertDatabaseHas('roles', ['name' => TenantRole::BuyingGroupNetworkAdmin->value]);
            $this->assertDatabaseHas('roles', ['name' => TenantRole::BuyingGroupAnalyst->value]);
            $this->assertDatabaseHas('roles', ['name' => TenantRole::BuyingGroupMember->value]);
            $this->assertDatabaseMissing('roles', ['name' => TenantRole::ReceivingTechnician->value]);
        });
    }

    #[Test]
    public function seeded_prepackager_receiving_technician_can_access_receive(): void
    {
        $tenant = Tenant::query()->find('13fe9068-cb05-4bab-9e0e-a89f2a458832');
        if ($tenant === null) {
            $this->markTestSkipped('Demo2 tenant not provisioned.');
        }

        $priorJobRoles = null;
        $userId = null;

        try {
            $tenant->forceFill(['profile' => TenantProfile::Prepackager])->save();

            (new SeedTenantRoles($tenant))->handle(app(TenantRoleSeeder::class));

            $tenant->run(function () use ($tenant, &$priorJobRoles, &$userId): void {
                $this->assertDatabaseHas('roles', [
                    'name' => TenantRole::ReceivingTechnician->value,
                    'guard_name' => 'web',
                ]);
                $this->assertDatabaseHas('roles', [
                    'name' => TenantRole::PackagingLineOperator->value,
                    'guard_name' => 'web',
                ]);
                $this->assertDatabaseHas('roles', [
                    'name' => TenantRole::OutboundPickAndPackLead->value,
                    'guard_name' => 'web',
                ]);

                $settings = TenantSettings::forTenant($tenant);
                $priorJobRoles = $settings->jobRolesEnabled();
                $settings->setJobRolesEnabled(true);
                $tenant->save();
                $tenant->refresh();

                Filament::setCurrentPanel(Filament::getPanel('app'));

                $user = User::factory()->create([
                    'email' => 'prepack-recv-'.Str::uuid().'@example.test',
                ]);
                $userId = (int) $user->getKey();
                $user->assignRole(TenantRole::ReceivingTechnician->value);
                $this->actingAs($user);

                $this->assertTrue($user->can(Permissions::NavReceive));
                $this->assertTrue(ReceivingSessionResource::canAccess());
            });
        } finally {
            if (tenancy()->initialized) {
                $tenant->run(function () use ($tenant, $priorJobRoles, $userId): void {
                    if ($userId !== null) {
                        User::query()->whereKey($userId)->delete();
                    }

                    if ($priorJobRoles !== null) {
                        TenantSettings::forTenant($tenant)->setJobRolesEnabled($priorJobRoles);
                    }

                    $tenant->forceFill(['profile' => TenantProfile::Pharmacy])->save();
                });
                tenancy()->end();
            } else {
                $tenant->forceFill(['profile' => TenantProfile::Pharmacy])->save();
            }
        }
    }
}
