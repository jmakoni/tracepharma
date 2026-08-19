<?php

declare(strict_types=1);

namespace Tests\Feature\Epcis;

use App\Actions\Tenants\ProvisionTenantPair;
use App\Enums\TenantProfile;
use App\Models\Tenant;
use App\Models\Admin;
use App\Notifications\AggregationLinkForeignKeyAlert;
use App\Support\AggregationLinkForeignKeyDoctor;
use App\Support\TenantHostname;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Stancl\Tenancy\Database\Models\Domain;
use Tests\TestCase;

class DoctorAggregationLinkFkCommandTest extends TestCase
{
    /** @var list<string> */
    private array $slugs = [];

    protected function tearDown(): void
    {
        foreach ($this->slugs as $slug) {
            foreach (TenantHostname::PAIR_ENVIRONMENTS as $environment) {
                $domain = Domain::query()
                    ->where('domain', TenantHostname::forSlug($slug, $environment))
                    ->first();

                if ($domain === null) {
                    continue;
                }

                Tenant::query()->find($domain->tenant_id)?->delete();
            }
        }

        parent::tearDown();
    }

    #[Test]
    public function doctor_reports_healthy_tenant_without_cascade_foreign_key(): void
    {
        $tenant = $this->provisionTenant();

        $this->artisan('tracepharma:doctor-aggregation-link-fk', [
            '--tenant' => [$tenant->id],
        ])->assertSuccessful();

        $this->assertNull(app(AggregationLinkForeignKeyDoctor::class)->inspectTenant($tenant));
    }

    #[Test]
    public function doctor_detects_cascade_foreign_key_and_fixes_it_with_migrate(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function (): void {
            $this->assertTrue(Schema::hasTable('aggregation_links'));

            DB::table('migrations')
                ->where('migration', '2026_08_16_180000_preserve_retired_aggregation_links_on_event_prune')
                ->delete();

            Schema::table('aggregation_links', function (Blueprint $table): void {
                $table->foreign('established_by_event_id')
                    ->references('id')
                    ->on('epcis_events')
                    ->cascadeOnDelete();
            });
        });

        $this->assertNotNull(app(AggregationLinkForeignKeyDoctor::class)->inspectTenant($tenant));

        $this->artisan('tracepharma:doctor-aggregation-link-fk', [
            '--tenant' => [$tenant->id],
        ])->assertFailed();

        $this->artisan('tracepharma:doctor-aggregation-link-fk', [
            '--tenant' => [$tenant->id],
            '--fix' => true,
        ])->assertSuccessful();

        $this->assertNull(app(AggregationLinkForeignKeyDoctor::class)->inspectTenant($tenant));
    }

    #[Test]
    public function doctor_alert_notifies_platform_admins_when_cascade_foreign_key_remains(): void
    {
        Notification::fake();

        $admin = Admin::factory()->create(['email' => 'ops-admin-'.Str::lower(Str::random(8)).'@example.test']);
        $tenant = $this->provisionTenant();

        $tenant->run(function (): void {
            DB::table('migrations')
                ->where('migration', '2026_08_16_180000_preserve_retired_aggregation_links_on_event_prune')
                ->delete();

            Schema::table('aggregation_links', function (Blueprint $table): void {
                $table->foreign('established_by_event_id')
                    ->references('id')
                    ->on('epcis_events')
                    ->cascadeOnDelete();
            });
        });

        $this->artisan('tracepharma:doctor-aggregation-link-fk', [
            '--tenant' => [$tenant->id],
            '--alert' => true,
            '--force' => true,
        ])->assertFailed();

        Notification::assertSentTo(
            $admin,
            AggregationLinkForeignKeyAlert::class,
            fn (AggregationLinkForeignKeyAlert $notification): bool => count($notification->issues()) === 1
                && $notification->issues()[0]['tenant_id'] === $tenant->id,
        );
    }

    #[Test]
    public function doctor_alert_is_throttled_for_repeat_issue_sets(): void
    {
        Notification::fake();

        $admin = Admin::factory()->create(['email' => 'ops-admin-throttle-'.Str::lower(Str::random(8)).'@example.test']);
        $tenant = $this->provisionTenant();

        $tenant->run(function (): void {
            DB::table('migrations')
                ->where('migration', '2026_08_16_180000_preserve_retired_aggregation_links_on_event_prune')
                ->delete();

            Schema::table('aggregation_links', function (Blueprint $table): void {
                $table->foreign('established_by_event_id')
                    ->references('id')
                    ->on('epcis_events')
                    ->cascadeOnDelete();
            });
        });

        $this->artisan('tracepharma:doctor-aggregation-link-fk', [
            '--tenant' => [$tenant->id],
            '--alert' => true,
            '--force' => true,
        ])->assertFailed();

        Notification::assertSentTo($admin, AggregationLinkForeignKeyAlert::class);

        $this->artisan('tracepharma:doctor-aggregation-link-fk', [
            '--tenant' => [$tenant->id],
            '--alert' => true,
        ])->assertFailed();

        Notification::assertSentToTimes($admin, AggregationLinkForeignKeyAlert::class, 1);
    }

    #[Test]
    public function doctor_records_last_audit_in_cache_for_admin_surfacing(): void
    {
        Cache::forget(AggregationLinkForeignKeyDoctor::LAST_AUDIT_CACHE_KEY);

        $tenant = $this->provisionTenant();

        $this->artisan('tracepharma:doctor-aggregation-link-fk', [
            '--tenant' => [$tenant->id],
        ])->assertSuccessful();

        $cached = Cache::get(AggregationLinkForeignKeyDoctor::LAST_AUDIT_CACHE_KEY);

        $this->assertIsArray($cached);
        $this->assertSame([], $cached['issues']);
        $this->assertNotEmpty($cached['checked_at']);
    }

    private function provisionTenant(): Tenant
    {
        $slug = 'agg-fk-'.Str::lower(Str::random(8));
        $this->slugs[] = $slug;

        return app(ProvisionTenantPair::class)->create($slug, [
            'name' => 'Aggregation FK Doctor '.$slug,
            'profile' => TenantProfile::Pharmacy,
            'status' => 'active',
        ]);
    }
}
