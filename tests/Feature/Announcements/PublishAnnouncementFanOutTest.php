<?php

declare(strict_types=1);

namespace Tests\Feature\Announcements;

use App\Actions\Announcements\PublishAnnouncement;
use App\Actions\Announcements\RetireAnnouncement;
use App\Enums\AnnouncementSeverity;
use App\Enums\AnnouncementStatus;
use App\Enums\TenantProfile;
use App\Jobs\Announcements\FanOutAnnouncementToTenant;
use App\Models\Admin;
use App\Models\Announcement;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PublishAnnouncementFanOutTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
    }

    #[Test]
    public function publish_fans_out_banner_row_and_bell_notification_to_active_users(): void
    {
        config(['queue.default' => 'sync']);

        $tenant = $this->ensureDemo2Tenant();
        $admin = Admin::factory()->create();

        $user = null;
        $tenant->run(function () use (&$user): void {
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            DB::table('notifications')->delete();
            DB::table('tenant_announcements')->delete();
            $user = User::factory()->create();
        });

        $announcement = Announcement::query()->create([
            'title' => 'Maintenance window',
            'body' => '<p>Saturday 02:00 UTC</p>',
            'severity' => AnnouncementSeverity::Warning,
            'status' => AnnouncementStatus::Draft,
            'created_by_admin_id' => $admin->id,
        ]);
        $announcement->tenants()->sync([$tenant->getTenantKey() => ['fan_out_status' => 'pending']]);

        app(PublishAnnouncement::class)->handle($announcement->fresh());

        $this->assertSame(AnnouncementStatus::Published, $announcement->fresh()->status);

        $tenant->run(function () use ($announcement, $user): void {
            $this->assertDatabaseHas('tenant_announcements', [
                'announcement_id' => $announcement->id,
                'is_active' => true,
                'title' => $announcement->title,
            ]);
            $this->assertDatabaseHas('notifications', [
                'notifiable_type' => $user->getMorphClass(),
                'notifiable_id' => $user->getKey(),
            ]);
            $row = DB::table('notifications')->where('notifiable_id', $user->getKey())->first();
            $data = json_decode((string) $row->data, true);
            $this->assertSame($announcement->id, $data['announcement_id'] ?? null);
            $this->assertSame('filament', $data['format'] ?? null);
        });

        $this->assertDatabaseHas('announcement_tenant', [
            'announcement_id' => $announcement->id,
            'tenant_id' => $tenant->getTenantKey(),
            'fan_out_status' => 'succeeded',
        ]);
    }

    #[Test]
    public function publish_is_idempotent_for_bell_notifications(): void
    {
        config(['queue.default' => 'sync']);

        $tenant = $this->ensureDemo2Tenant();
        $admin = Admin::factory()->create();

        $user = null;
        $tenant->run(function () use (&$user): void {
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            DB::table('notifications')->delete();
            DB::table('tenant_announcements')->delete();
            $user = User::factory()->create();
        });

        $announcement = Announcement::query()->create([
            'title' => 'Duplicate guard',
            'body' => '<p>Run twice</p>',
            'severity' => AnnouncementSeverity::Info,
            'status' => AnnouncementStatus::Published,
            'published_at' => now(),
            'created_by_admin_id' => $admin->id,
        ]);
        $announcement->tenants()->sync([$tenant->getTenantKey() => ['fan_out_status' => 'pending']]);

        FanOutAnnouncementToTenant::dispatchSync($announcement->id, (string) $tenant->getTenantKey());
        FanOutAnnouncementToTenant::dispatchSync($announcement->id, (string) $tenant->getTenantKey());

        $tenant->run(function () use ($user): void {
            $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $user->getKey())->count());
        });
    }

    #[Test]
    public function retire_deactivates_tenant_banner(): void
    {
        config(['queue.default' => 'sync']);

        $tenant = $this->ensureDemo2Tenant();
        $admin = Admin::factory()->create();

        $tenant->run(function (): void {
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            DB::table('notifications')->delete();
            DB::table('tenant_announcements')->delete();
            User::factory()->create();
        });

        $announcement = Announcement::query()->create([
            'title' => 'Retiring notice',
            'body' => '<p>Going away</p>',
            'severity' => AnnouncementSeverity::Info,
            'status' => AnnouncementStatus::Draft,
            'created_by_admin_id' => $admin->id,
        ]);
        $announcement->tenants()->sync([$tenant->getTenantKey() => ['fan_out_status' => 'pending']]);

        app(PublishAnnouncement::class)->handle($announcement->fresh());
        app(RetireAnnouncement::class)->handle($announcement->fresh());

        $tenant->run(function () use ($announcement): void {
            $this->assertDatabaseHas('tenant_announcements', [
                'announcement_id' => $announcement->id,
                'is_active' => false,
            ]);
            $this->assertGreaterThan(0, DB::table('notifications')->count());
        });

        $this->assertSame(AnnouncementStatus::Retired, $announcement->fresh()->status);
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

        if (! self::$demo2TenantReady) {
            $this->artisan('tenants:migrate', [
                '--tenants' => [self::DEMO2_TENANT_ID],
                '--force' => true,
            ])->assertSuccessful();

            self::$demo2TenantReady = true;
        }

        return $tenant->fresh();
    }
}
