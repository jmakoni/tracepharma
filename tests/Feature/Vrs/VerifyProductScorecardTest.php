<?php

namespace Tests\Feature\Vrs;

use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Pages\VerifyProduct;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use App\Models\Verification;
use App\Support\Vrs\VerificationScorecardMetrics;
use Filament\Facades\Filament;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VerifyProductScorecardTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $verificationIds = [];

    #[Test]
    public function scorecard_counts_allowed_and_blocked_last_24h(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $since = now()->subHours(6);

            $this->verificationIds[] = (int) Verification::query()->create([
                'gtin14' => '30301164005162',
                'serial' => 'SC-ALLOW-'.random_int(1000, 9999),
                'status' => 'verified',
                'created_at' => $since,
            ])->getKey();

            $this->verificationIds[] = (int) Verification::query()->create([
                'gtin14' => '30301164005162',
                'serial' => 'SC-FAIL-'.random_int(1000, 9999),
                'status' => 'failed',
                'created_at' => $since,
            ])->getKey();

            $metrics = app(VerificationScorecardMetrics::class)->handle();

            $this->assertGreaterThanOrEqual(1, $metrics['allowed']);
            $this->assertGreaterThanOrEqual(1, $metrics['blocked']);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function scorecard_counts_deferred_and_unavailable_last_24h(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $since = now()->subHours(6);

            $this->verificationIds[] = (int) Verification::query()->create([
                'gtin14' => '30301164005162',
                'serial' => 'SC-DEF-'.random_int(1000, 9999),
                'status' => 'deferred',
                'created_at' => $since,
            ])->getKey();

            $this->verificationIds[] = (int) Verification::query()->create([
                'gtin14' => '30301164005162',
                'serial' => 'SC-UNAV-'.random_int(1000, 9999),
                'status' => 'unavailable',
                'created_at' => $since,
            ])->getKey();

            $metrics = app(VerificationScorecardMetrics::class)->handle();

            $this->assertGreaterThanOrEqual(1, $metrics['deferred']);
            $this->assertGreaterThanOrEqual(1, $metrics['unavailable']);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function page_renders_dispense_scorecard_and_api_hint(): void
    {
        $this->initializeDemo2Tenant();

        try {
            config(['vrs.driver' => 'fake']);
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            $user = User::factory()->create();
            $user->assignRole(TenantRole::Owner->value);
            $this->actingAs($user);
            Filament::setCurrentPanel(Filament::getPanel('app'));

            Livewire::test(VerifyProduct::class)
                ->assertSee('Allowed (24h)')
                ->assertSee('Blocked (24h)')
                ->assertSee('Deferred (24h)')
                ->assertSee('Unavailable (24h)')
                ->assertSee('View verification history (today)')
                ->assertSee('/api/v1/dispense-check')
                ->assertSee('PMS dispense bridge');
        } finally {
            $this->cleanup();
        }
    }

    private function initializeDemo2Tenant(): Tenant
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

        tenancy()->initialize($tenant);

        return $tenant;
    }

    private function cleanup(): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        if ($this->verificationIds !== []) {
            Verification::query()->whereKey($this->verificationIds)->delete();
            $this->verificationIds = [];
        }

        tenancy()->end();
    }
}
