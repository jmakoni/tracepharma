<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Pages\InspectionDayReadinessPage;
use App\Filament\App\Pages\Quarantine;
use App\Filament\App\Pages\RecallClosureDashboard;
use App\Filament\App\Resources\Fda3911Reports\Pages\ListFda3911Reports;
use App\Filament\App\Resources\TracingRequests\Pages\ListTracingRequests;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InspectionDayAndRecallClosurePagesTest extends TestCase
{
    private const TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private static bool $tenantReady = false;

    #[Test]
    public function inspection_day_and_recall_closure_pages_render_for_owner(): void
    {
        $this->initializeTenant(TenantProfile::Pharmacy);

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            $user = User::factory()->create();
            $user->assignRole(TenantRole::Owner->value);
            $this->actingAs($user);

            Livewire::test(InspectionDayReadinessPage::class)
                ->assertSuccessful()
                ->assertSee('Inspection day readiness')
                ->assertSee('Competitive FDA walk-in demo path')
                ->assertSee('Not a live NABP Pulse feed');

            Livewire::test(RecallClosureDashboard::class)
                ->assertSuccessful()
                ->assertSee('Recall closure');
        } finally {
            tenancy()->end();
        }
    }

    #[Test]
    public function compliance_surfaces_show_pulse_honest_helpers(): void
    {
        $this->initializeTenant(TenantProfile::Pharmacy);

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            $user = User::factory()->create();
            $user->assignRole(TenantRole::Owner->value);
            $this->actingAs($user);

            Livewire::test(Quarantine::class)
                ->assertSuccessful()
                ->assertSee('not Pulse directory quarantine');

            Livewire::test(ListFda3911Reports::class)
                ->assertSuccessful()
                ->assertSee('not an automated Pulse or FDA e-submit API');

            Livewire::test(ListTracingRequests::class)
                ->assertSuccessful()
                ->assertSee('not a live Pulse investigation network');
        } finally {
            tenancy()->end();
        }
    }

    private function initializeTenant(TenantProfile $profile = TenantProfile::Pharmacy): Tenant
    {
        $tenant = Tenant::query()->find(self::TENANT_ID);

        if ($tenant === null) {
            $tenant = Tenant::withoutEvents(fn () => Tenant::query()->create([
                'id' => self::TENANT_ID,
                'name' => 'Demo Pharmacy',
                'profile' => $profile->value,
                'status' => 'active',
                'tenancy_db_name' => 'tenant_demo2_internal_vatengi_com',
            ]));
            $tenant->domains()->create(['domain' => 'demo2.internal.vatengi.com']);
        }

        $tenant->forceFill(['profile' => $profile])->save();

        if (! self::$tenantReady) {
            $this->artisan('tenants:migrate', [
                '--tenants' => [self::TENANT_ID],
                '--force' => true,
            ])->assertSuccessful();
            self::$tenantReady = true;
        }

        tenancy()->end();
        tenancy()->initialize($tenant->fresh());

        return $tenant->fresh();
    }
}
