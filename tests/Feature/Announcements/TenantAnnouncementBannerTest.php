<?php

declare(strict_types=1);

namespace Tests\Feature\Announcements;

use App\Enums\AnnouncementSeverity;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Livewire\App\TenantAnnouncementBanner;
use App\Models\Tenant;
use App\Models\TenantAnnouncement;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\TenantSettings;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TenantAnnouncementBannerTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    #[Test]
    public function active_tenant_announcement_is_visible_until_dismissed_by_user(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $announcement = null;
            $userOne = null;
            $userTwo = null;

            $tenant->run(function () use (&$announcement, &$userOne, &$userTwo): void {
                DB::table('tenant_announcement_dismissals')->delete();
                DB::table('tenant_announcements')->delete();

                app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);

                $userOne = User::factory()->create();
                $userOne->assignRole(TenantRole::Owner->value);
                $userTwo = User::factory()->create();
                $userTwo->assignRole(TenantRole::Owner->value);

                $announcement = TenantAnnouncement::query()->create([
                    'announcement_id' => (string) Str::uuid(),
                    'title' => 'Scheduled maintenance tonight',
                    'body' => '<p>Systems offline 02:00–04:00 UTC</p>',
                    'severity' => AnnouncementSeverity::Warning,
                    'published_at' => now(),
                    'is_active' => true,
                ]);
            });

            $this->actingAs($userOne, 'web');
            Filament::setCurrentPanel(Filament::getPanel('app'));
            session()->put('filament.app.onboarding_wizard_redirected', true);

            Livewire::test(TenantAnnouncementBanner::class)
                ->assertSee('Scheduled maintenance tonight');

            $hookHtml = view('filament.app.hooks.tenant-announcement-banner')->render();
            $this->assertStringContainsString('Scheduled maintenance tonight', $hookHtml);

            tenancy()->end();

            $this->actingAs($userOne, 'web')
                ->get('https://'.self::DEMO2_DOMAIN.'/', [
                    'HTTP_HOST' => self::DEMO2_DOMAIN,
                ])
                ->assertOk()
                ->assertSee('Scheduled maintenance tonight');

            tenancy()->initialize($tenant);
            Filament::setCurrentPanel(Filament::getPanel('app'));

            Livewire::test(TenantAnnouncementBanner::class)
                ->call('dismiss', $announcement->id)
                ->assertDontSee('Scheduled maintenance tonight');

            $this->assertDatabaseHas('tenant_announcement_dismissals', [
                'tenant_announcement_id' => $announcement->id,
                'user_id' => $userOne->id,
            ]);

            tenancy()->end();

            $this->actingAs($userOne, 'web')
                ->get('https://'.self::DEMO2_DOMAIN.'/', [
                    'HTTP_HOST' => self::DEMO2_DOMAIN,
                ])
                ->assertOk()
                ->assertDontSee('Scheduled maintenance tonight');

            tenancy()->initialize($tenant);
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $this->actingAs($userTwo, 'web');
            Filament::setCurrentPanel(Filament::getPanel('app'));

            Livewire::test(TenantAnnouncementBanner::class)
                ->assertSee('Scheduled maintenance tonight');

            tenancy()->end();

            $this->actingAs($userTwo, 'web')
                ->get('https://'.self::DEMO2_DOMAIN.'/', [
                    'HTTP_HOST' => self::DEMO2_DOMAIN,
                ])
                ->assertOk()
                ->assertSee('Scheduled maintenance tonight');
        } finally {
            tenancy()->end();
        }
    }

    private function initializeDemo2Tenant(): Tenant
    {
        $tenant = $this->ensureDemo2Tenant();
        tenancy()->initialize($tenant);
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
        TenantSettings::forTenant($tenant)->setOnboardingDismissedAt(now());
        $tenant->save();

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
