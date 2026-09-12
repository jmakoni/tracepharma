<?php

declare(strict_types=1);

namespace Tests\Feature\Announcements;

use App\Enums\AnnouncementSeverity;
use App\Enums\TenantProfile;
use App\Models\Tenant;
use App\Models\TenantAnnouncement;
use App\Models\TenantAnnouncementDismissal;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TenantAnnouncementSchemaTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    #[Test]
    public function tenant_announcement_tables_exist_and_models_persist(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->assertTrue(Schema::hasTable('tenant_announcements'));
            $this->assertTrue(Schema::hasTable('tenant_announcement_dismissals'));

            $user = User::factory()->create();
            $announcementId = (string) Str::uuid();

            $announcement = TenantAnnouncement::query()->create([
                'announcement_id' => $announcementId,
                'title' => 'Scheduled maintenance',
                'body' => '<p>Saturday 02:00 UTC</p>',
                'severity' => AnnouncementSeverity::Warning,
                'published_at' => now(),
                'is_active' => true,
            ]);

            $dismissal = TenantAnnouncementDismissal::query()->create([
                'tenant_announcement_id' => $announcement->id,
                'user_id' => $user->id,
                'dismissed_at' => now(),
            ]);

            $this->assertDatabaseHas('tenant_announcements', [
                'id' => $announcement->id,
                'announcement_id' => $announcementId,
                'severity' => 'warning',
                'is_active' => true,
            ]);

            $this->assertDatabaseHas('tenant_announcement_dismissals', [
                'id' => $dismissal->id,
                'tenant_announcement_id' => $announcement->id,
                'user_id' => $user->id,
            ]);

            $this->assertCount(1, $announcement->dismissals);
            $this->assertTrue($announcement->dismissals->contains($dismissal));
        } finally {
            tenancy()->end();
        }
    }

    private function initializeDemo2Tenant(): Tenant
    {
        $tenant = $this->ensureDemo2Tenant();
        tenancy()->initialize($tenant);
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);

        return $tenant;
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
