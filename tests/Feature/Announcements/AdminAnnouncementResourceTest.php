<?php

declare(strict_types=1);

namespace Tests\Feature\Announcements;

use App\Enums\AdminRole;
use App\Enums\AnnouncementSeverity;
use App\Enums\AnnouncementStatus;
use App\Filament\Admin\Resources\Announcements\AnnouncementResource;
use App\Filament\Admin\Resources\Announcements\Pages\EditAnnouncement;
use App\Filament\Admin\Resources\Announcements\Pages\ListAnnouncements;
use App\Jobs\Announcements\FanOutAnnouncementToTenant;
use App\Models\Admin;
use App\Models\Announcement;
use App\Models\Tenant;
use App\Support\Auth\AdminRoleSeeder;
use App\Support\Auth\Permissions;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AdminAnnouncementResourceTest extends TestCase
{
    /** @var list<int> */
    private array $adminIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
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
    public function platform_admin_can_open_announcements_index(): void
    {
        $this->actAsAdmin(AdminRole::PlatformAdmin);

        $this->get('https://'.config('tracepharma.admin_domain').'/announcements')
            ->assertOk()
            ->assertSee('Announcements');
    }

    #[Test]
    public function support_cannot_access_announcements_resource(): void
    {
        $this->actAsAdmin(AdminRole::Support);

        $this->assertFalse(AnnouncementResource::canAccess());
        Livewire::test(ListAnnouncements::class)->assertForbidden();
    }

    #[Test]
    public function publish_action_requires_at_least_one_tenant(): void
    {
        $this->actAsAdmin(AdminRole::PlatformAdmin);

        $announcement = Announcement::query()->create([
            'title' => 'Draft notice',
            'body' => 'Body copy',
            'severity' => AnnouncementSeverity::Info,
            'status' => AnnouncementStatus::Draft,
            'created_by_admin_id' => auth('admin')->id(),
        ]);

        Livewire::test(EditAnnouncement::class, ['record' => $announcement->getKey()])
            ->callAction(TestAction::make('publish'))
            ->assertNotified('Select at least one tenant before publishing.');

        $this->assertSame(AnnouncementStatus::Draft, $announcement->fresh()->status);
    }

    #[Test]
    public function publish_action_changes_status_to_published(): void
    {
        Bus::fake([FanOutAnnouncementToTenant::class]);

        $this->actAsAdmin(AdminRole::PlatformAdmin);

        $tenant = Tenant::query()->firstOrFail();

        $announcement = Announcement::query()->create([
            'title' => 'Go-live notice',
            'body' => 'Body copy',
            'severity' => AnnouncementSeverity::Warning,
            'status' => AnnouncementStatus::Draft,
            'created_by_admin_id' => auth('admin')->id(),
        ]);
        $announcement->tenants()->sync([$tenant->getTenantKey() => ['fan_out_status' => 'pending']]);

        Livewire::test(EditAnnouncement::class, ['record' => $announcement->getKey()])
            ->callAction(TestAction::make('publish'))
            ->assertNotified('Announcement published');

        $this->assertSame(AnnouncementStatus::Published, $announcement->fresh()->status);
        Bus::assertDispatched(FanOutAnnouncementToTenant::class);
    }

    private function actAsAdmin(AdminRole $role): Admin
    {
        app(AdminRoleSeeder::class)->seed();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $admin = Admin::factory()->create();
        $admin->assignRole($role->value);
        $this->adminIds[] = (int) $admin->getKey();

        $this->actingAs($admin, 'admin');
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return $admin;
    }
}
