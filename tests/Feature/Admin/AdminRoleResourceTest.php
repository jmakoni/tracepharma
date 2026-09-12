<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\AdminRole;
use App\Filament\Admin\Resources\Roles\Pages\EditRole;
use App\Filament\Admin\Resources\Roles\Pages\ListRoles;
use App\Filament\Admin\Resources\Roles\RoleResource;
use App\Models\Admin;
use App\Support\Auth\AdminRoleSeeder;
use App\Support\Auth\Permissions;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AdminRoleResourceTest extends TestCase
{
    use DatabaseTransactions;

    /** @var list<int> */
    private array $adminIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        app(AdminRoleSeeder::class)->seed();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function tearDown(): void
    {
        if ($this->adminIds !== []) {
            DB::table('model_has_roles')
                ->where('model_type', Admin::class)
                ->whereIn('model_id', $this->adminIds)
                ->delete();
            DB::table('admins')->whereIn('id', $this->adminIds)->delete();
            $this->adminIds = [];
        }

        parent::tearDown();
    }

    #[Test]
    public function support_cannot_access_roles_resource(): void
    {
        $this->actAsAdmin(AdminRole::Support);

        $this->assertFalse(RoleResource::canAccess());
        Livewire::test(ListRoles::class)->assertForbidden();
    }

    #[Test]
    public function platform_admin_can_sync_reset_and_cannot_delete_roles(): void
    {
        $this->actAsAdmin(AdminRole::PlatformAdmin);

        $this->assertTrue(RoleResource::canAccess());
        $this->assertFalse(RoleResource::canCreate());

        $platform = Role::query()
            ->where('guard_name', 'admin')
            ->where('name', AdminRole::PlatformAdmin->value)
            ->firstOrFail();
        $support = Role::query()
            ->where('guard_name', 'admin')
            ->where('name', AdminRole::Support->value)
            ->firstOrFail();

        $this->assertFalse(RoleResource::canDelete($platform));

        Livewire::test(ListRoles::class)->assertSuccessful();

        Livewire::test(EditRole::class, ['record' => $support->getKey()])
            ->fillForm([
                'permission_names' => [Permissions::CatalogManage],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $support->refresh();
        $this->assertEqualsCanonicalizing(
            [Permissions::CatalogManage],
            $support->permissions()->pluck('name')->all(),
        );

        Livewire::test(EditRole::class, ['record' => $support->getKey()])
            ->mountAction('resetToDefaults')
            ->callMountedAction()
            ->assertHasNoErrors();

        $support->refresh();
        $this->assertSame([], $support->permissions()->pluck('name')->all());

        Livewire::test(EditRole::class, ['record' => $platform->getKey()])
            ->fillForm([
                'permission_names' => [],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $platform->refresh();
        $this->assertEqualsCanonicalizing(
            Permissions::adminPanelPermissions(),
            $platform->permissions()->pluck('name')->all(),
        );
    }

    private function actAsAdmin(AdminRole $role): Admin
    {
        $admin = Admin::factory()->create();
        $admin->assignRole($role->value);
        $this->adminIds[] = (int) $admin->getKey();

        $this->actingAs($admin, 'admin');
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return $admin;
    }
}
