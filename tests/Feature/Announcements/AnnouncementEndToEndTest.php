<?php

declare(strict_types=1);

namespace Tests\Feature\Announcements;

use App\Enums\AdminRole;
use App\Enums\AnnouncementSeverity;
use App\Enums\AnnouncementStatus;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\Admin\Resources\Announcements\Pages\CreateAnnouncement;
use App\Filament\Admin\Resources\Announcements\Pages\EditAnnouncement;
use App\Livewire\App\TenantAnnouncementBanner;
use App\Models\Admin;
use App\Models\Announcement;
use App\Models\Tenant;
use App\Models\TenantAnnouncement;
use App\Models\User;
use App\Support\Auth\AdminRoleSeeder;
use App\Support\Auth\Permissions;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\TenantSettings;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AnnouncementEndToEndTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    /** @var list<string> */
    private static array $migratedTenantIds = [];

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

        tenancy()->end();

        parent::tearDown();
    }

    #[Test]
    public function admin_publish_app_dismiss_and_retire_lifecycle(): void
    {
        config(['queue.default' => 'sync']);

        $demo2Tenant = $this->ensureDemo2Tenant();
        $targetTenantIds = $this->targetTenantIds($demo2Tenant);
        $this->ensureTenantsMigrated($targetTenantIds);

        $title = 'Platform maintenance tonight '.Str::uuid();

        $announcement = null;
        $appUser = null;
        $tenantAnnouncement = null;

        try {
        $demo2Tenant->run(function () use (&$appUser): void {
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            DB::table('tenant_announcement_dismissals')->delete();
            DB::table('notifications')->delete();
            DB::table('tenant_announcements')->delete();

            $appUser = User::factory()->create();
            $appUser->assignRole(TenantRole::Owner->value);
        });

        $this->actAsAdmin(AdminRole::PlatformAdmin);

        Livewire::test(CreateAnnouncement::class)
            ->fillForm([
                'title' => $title,
                'body' => '<p>Systems offline 02:00–04:00 UTC</p>',
                'severity' => AnnouncementSeverity::Warning->value,
                'tenants' => $targetTenantIds,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $announcement = Announcement::query()->where('title', $title)->sole();
        $this->assertSame(AnnouncementStatus::Draft, $announcement->fresh()->status);
        $this->assertCount(count($targetTenantIds), $announcement->tenants()->get());

        Livewire::test(EditAnnouncement::class, ['record' => $announcement->getKey()])
            ->callAction(TestAction::make('publish'))
            ->assertNotified('Announcement published');

        $announcement = $announcement->fresh();
        $this->assertSame(AnnouncementStatus::Published, $announcement->status);

        $demo2Tenant->run(function () use ($announcement, $appUser, $title, &$tenantAnnouncement): void {
            $this->assertDatabaseHas('tenant_announcements', [
                'announcement_id' => $announcement->id,
                'is_active' => true,
                'title' => $title,
            ]);

            $tenantAnnouncement = TenantAnnouncement::query()
                ->where('announcement_id', $announcement->id)
                ->firstOrFail();

            $notification = DB::table('notifications')
                ->where('notifiable_type', $appUser->getMorphClass())
                ->where('notifiable_id', $appUser->getKey())
                ->where('data->announcement_id', $announcement->id)
                ->first();

            $this->assertNotNull($notification);
            $this->assertNull($notification->read_at);

            $data = json_decode((string) $notification->data, true);
            $this->assertSame($announcement->id, $data['announcement_id'] ?? null);
            $this->assertSame('filament', $data['format'] ?? null);
        });

        tenancy()->end();

        tenancy()->initialize($demo2Tenant);
        TenantSettings::forTenant($demo2Tenant)->setOnboardingDismissedAt(now());
        $demo2Tenant->save();

        $this->actingAs($appUser, 'web');
        Filament::setCurrentPanel(Filament::getPanel('app'));
        session()->put('filament.app.onboarding_wizard_redirected', true);

        Livewire::test(TenantAnnouncementBanner::class)
            ->assertSee($title)
            ->call('dismiss', $tenantAnnouncement->id)
            ->assertDontSee($title);

        $demo2Tenant->run(function () use ($appUser, $announcement): void {
            $this->assertDatabaseHas('tenant_announcement_dismissals', [
                'tenant_announcement_id' => TenantAnnouncement::query()
                    ->where('announcement_id', $announcement->id)
                    ->value('id'),
                'user_id' => $appUser->getKey(),
            ]);

            $notification = DB::table('notifications')
                ->where('notifiable_type', $appUser->getMorphClass())
                ->where('notifiable_id', $appUser->getKey())
                ->where('data->announcement_id', $announcement->id)
                ->first();

            $this->assertNotNull($notification);
            $this->assertNull($notification->read_at);
        });

        tenancy()->end();

        $this->actAsAdmin(AdminRole::PlatformAdmin);

        Livewire::test(EditAnnouncement::class, ['record' => $announcement->getKey()])
            ->callAction(TestAction::make('retire'))
            ->assertNotified('Announcement retired');

        $this->assertSame(AnnouncementStatus::Retired, $announcement->fresh()->status);

        $demo2Tenant->run(function () use ($announcement, $appUser): void {
            $this->assertDatabaseHas('tenant_announcements', [
                'announcement_id' => $announcement->id,
                'is_active' => false,
            ]);

            $this->assertSame(
                1,
                DB::table('notifications')
                    ->where('notifiable_type', $appUser->getMorphClass())
                    ->where('notifiable_id', $appUser->getKey())
                    ->where('data->announcement_id', $announcement->id)
                    ->count(),
            );

            $notification = DB::table('notifications')
                ->where('notifiable_type', $appUser->getMorphClass())
                ->where('notifiable_id', $appUser->getKey())
                ->where('data->announcement_id', $announcement->id)
                ->first();

            $this->assertNotNull($notification);
            $this->assertNull($notification->read_at);
        });
        } finally {
            tenancy()->end();
            $this->cleanupAnnouncementLifecycle($announcement, $targetTenantIds);
        }
    }

    /**
     * @param  list<string>  $targetTenantIds
     */
    private function cleanupAnnouncementLifecycle(?Announcement $announcement, array $targetTenantIds): void
    {
        if ($announcement === null) {
            return;
        }

        foreach ($targetTenantIds as $tenantId) {
            $tenant = Tenant::query()->find($tenantId);

            if ($tenant === null) {
                continue;
            }

            $tenant->run(function () use ($announcement): void {
                $tenantAnnouncementIds = TenantAnnouncement::query()
                    ->where('announcement_id', $announcement->id)
                    ->pluck('id');

                if ($tenantAnnouncementIds->isNotEmpty()) {
                    DB::table('tenant_announcement_dismissals')
                        ->whereIn('tenant_announcement_id', $tenantAnnouncementIds)
                        ->delete();
                }

                DB::table('tenant_announcements')
                    ->where('announcement_id', $announcement->id)
                    ->delete();

                DB::table('notifications')
                    ->where('data->announcement_id', $announcement->id)
                    ->delete();
            });
        }

        DB::table('announcement_tenant')
            ->where('announcement_id', $announcement->id)
            ->delete();

        Announcement::query()->whereKey($announcement->id)->delete();
    }

    /**
     * @return list<string>
     */
    private function targetTenantIds(Tenant $demo2Tenant): array
    {
        $ids = Tenant::query()->limit(2)->pluck('id')->all();

        if (count($ids) < 2) {
            return [(string) $demo2Tenant->getTenantKey()];
        }

        if (! in_array($demo2Tenant->getTenantKey(), $ids, true)) {
            $ids[1] = $demo2Tenant->getTenantKey();
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  list<string>  $tenantIds
     */
    private function ensureTenantsMigrated(array $tenantIds): void
    {
        $pending = array_values(array_diff($tenantIds, self::$migratedTenantIds));

        if ($pending === []) {
            return;
        }

        $this->artisan('tenants:migrate', [
            '--tenants' => $pending,
            '--force' => true,
        ])->assertSuccessful();

        self::$migratedTenantIds = array_values(array_unique([
            ...self::$migratedTenantIds,
            ...$pending,
        ]));
    }

    private function ensureDemo2Tenant(): Tenant
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

        $this->ensureTenantsMigrated([(string) $tenant->getTenantKey()]);

        return $tenant->fresh();
    }

    private function actAsAdmin(AdminRole $role): Admin
    {
        tenancy()->end();

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
