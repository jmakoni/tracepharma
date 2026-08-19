<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\AdminRole;
use App\Filament\Admin\Resources\ActivityLogs\ActivityLogResource;
use App\Filament\Admin\Resources\ActivityLogs\Pages\ListActivityLogs;
use App\Filament\Admin\Resources\ActivityLogs\Pages\ViewActivityLog;
use App\Models\Admin;
use App\Support\Auth\AdminRoleSeeder;
use App\Support\Auth\Permissions;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ActivityLogResourceAccessTest extends TestCase
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
    public function support_cannot_view_activity_log_resource(): void
    {
        $support = $this->actAsAdmin(AdminRole::Support);
        $activity = $this->createActivityRecord();

        $this->assertFalse($support->can(Permissions::AdminsManage));
        $this->assertFalse(ActivityLogResource::canAccess());
        $this->assertFalse(ActivityLogResource::canViewAny());
        $this->assertFalse(ActivityLogResource::canView($activity));

        Livewire::test(ListActivityLogs::class)->assertForbidden();
        Livewire::test(ViewActivityLog::class, ['record' => $activity->getKey()])->assertForbidden();
    }

    #[Test]
    public function platform_admin_can_view_activity_log_resource(): void
    {
        $admin = $this->actAsAdmin(AdminRole::PlatformAdmin);
        $activity = $this->createActivityRecord();

        $this->assertTrue($admin->can(Permissions::AdminsManage));
        $this->assertTrue(ActivityLogResource::canAccess());
        $this->assertTrue(ActivityLogResource::canViewAny());
        $this->assertTrue(ActivityLogResource::canView($activity));

        Livewire::test(ListActivityLogs::class)->assertSuccessful();
        Livewire::test(ViewActivityLog::class, ['record' => $activity->getKey()])->assertSuccessful();
    }

    private function createActivityRecord(): Activity
    {
        return Activity::query()->create([
            'log_name' => 'default',
            'description' => 'activity_log_resource_access_test',
            'properties' => [],
        ]);
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
