<?php

namespace Tests\Feature\Announcements;

use App\Enums\AnnouncementSeverity;
use App\Enums\AnnouncementStatus;
use App\Models\Admin;
use App\Models\Announcement;
use App\Models\Tenant;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AnnouncementSchemaTest extends TestCase
{
    #[Test]
    public function central_announcements_tables_exist_and_model_persists(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();

        $this->assertTrue(Schema::hasTable('announcements'));
        $this->assertTrue(Schema::hasTable('announcement_tenant'));

        $admin = Admin::factory()->create();
        $tenant = Tenant::query()->firstOrFail();

        $announcement = Announcement::query()->create([
            'title' => 'Maintenance window',
            'body' => '<p>Saturday 02:00 UTC</p>',
            'severity' => AnnouncementSeverity::Warning,
            'status' => AnnouncementStatus::Draft,
            'created_by_admin_id' => $admin->id,
        ]);

        $announcement->tenants()->sync([
            $tenant->getTenantKey() => ['fan_out_status' => 'pending'],
        ]);

        $this->assertDatabaseHas('announcements', [
            'id' => $announcement->id,
            'status' => 'draft',
            'severity' => 'warning',
        ]);
        $this->assertDatabaseHas('announcement_tenant', [
            'announcement_id' => $announcement->id,
            'tenant_id' => $tenant->getTenantKey(),
        ]);
    }
}
