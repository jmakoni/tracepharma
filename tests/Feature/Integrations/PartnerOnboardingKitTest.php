<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Enums\PartnerType;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Pages\PartnerOnboardingKitPage;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\PartnerOnboardingKit;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PartnerOnboardingKitTest extends TestCase
{
    private const TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DOMAIN = 'demo2.internal.vatengi.com';

    private static bool $tenantReady = false;

    #[Test]
    public function kit_exposes_onboarding_steps_and_it_brief(): void
    {
        $this->initializeTenant();

        try {
            $kit = app(PartnerOnboardingKit::class);
            $steps = $kit->steps();

            $this->assertCount(5, $steps);
            $this->assertSame('create_partner', $steps[0]['id']);
            $this->assertSame('downstream_portal', $steps[4]['id']);
            $this->assertStringContainsString('TracePharma partner onboarding', $kit->exportBrief());
            $this->assertGreaterThanOrEqual(0, $kit->score());
            $this->assertLessThanOrEqual(100, $kit->score());
        } finally {
            tenancy()->end();
        }
    }

    #[Test]
    public function kit_marks_upstream_partner_step_done_when_gln_partner_exists(): void
    {
        $this->initializeTenant();

        try {
            TradingPartner::query()->create([
                'name' => 'Kit Supplier '.Str::uuid(),
                'partner_type' => PartnerType::Wholesaler->value,
                'gln' => '0860000'.random_int(100000, 999999),
                'is_active' => true,
            ]);

            $step = collect(app(PartnerOnboardingKit::class)->steps())
                ->firstWhere('id', 'create_partner');

            $this->assertTrue($step['done'] ?? false);
        } finally {
            tenancy()->end();
        }
    }

    #[Test]
    public function partner_onboarding_page_renders_for_owner(): void
    {
        $this->initializeTenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            $user = User::factory()->create();
            $user->assignRole(TenantRole::Owner->value);
            $this->actingAs($user);

            Livewire::test(PartnerOnboardingKitPage::class)
                ->assertSuccessful()
                ->assertSee('Partner onboarding kit')
                ->assertSee('Create trading partner');
        } finally {
            tenancy()->end();
        }
    }

    private function initializeTenant(): Tenant
    {
        $tenant = Tenant::query()->find(self::TENANT_ID);

        if ($tenant === null) {
            $tenant = Tenant::withoutEvents(fn () => Tenant::query()->create([
                'id' => self::TENANT_ID,
                'name' => 'Demo Pharmacy',
                'profile' => TenantProfile::Pharmacy,
                'status' => 'active',
                'tenancy_db_name' => 'tenant_demo2_internal_vatengi_com',
            ]));
            $tenant->domains()->create(['domain' => self::DOMAIN]);
        }

        if (! self::$tenantReady) {
            $this->artisan('tenants:migrate', [
                '--tenants' => [self::TENANT_ID],
                '--force' => true,
            ])->assertSuccessful();
            self::$tenantReady = true;
        }

        tenancy()->initialize($tenant);

        return $tenant;
    }
}
