<?php

namespace Tests\Feature\Auth;

use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Pages\AcceptLegalDocuments;
use App\Filament\App\Pages\Dashboard;
use App\Http\Middleware\EnsureLegalAcceptance;
use App\Models\CustomerOnboarding;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Admin\TenantImpersonation;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\Legal\LegalAcceptance;
use App\Support\Marketing\PrivacyPolicy;
use App\Support\Marketing\TermsOfService;
use App\Support\TenantSettings;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class LegalAcceptanceGateTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    private ?bool $priorJobRolesEnabled = null;

    #[Test]
    public function technician_is_never_redirected_and_notice_is_not_started(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->enableJobRoles($tenant);
            $technician = $this->createUserWithRole(TenantRole::ReceivingTechnician);
            $this->actingAs($technician);

            $response = $this->throughGate(Request::create('/', 'GET'));

            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame('ok', $response->getContent());
            $this->assertNull($technician->fresh()?->legal_notice_started_at);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function gated_owner_first_hit_starts_notice_and_still_reaches_app_during_grace(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $owner = $this->createUserWithRole(TenantRole::Owner);
            $this->actingAs($owner);
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $started = Carbon::parse('2026-08-18 12:00:00');
            Carbon::setTestNow($started);

            $response = $this->throughGate(Request::create('/', 'GET'));

            $this->assertSame(200, $response->getStatusCode());
            $this->assertTrue($owner->fresh()?->legal_notice_started_at?->equalTo($started));

            Livewire::test(Dashboard::class)->assertOk();

            $banner = view('filament.app.hooks.legal-acceptance-banner')->render();
            $this->assertStringContainsString('Legal documents updated', $banner);
            $this->assertStringContainsString('Review and accept', $banner);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function after_grace_dashboard_redirects_to_accept_and_accept_unblocks(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $central = (string) config('tenancy.database.central_connection', config('database.default'));
            $onboardingCountBefore = CustomerOnboarding::on($central)->count();

            $owner = $this->createUserWithRole(TenantRole::Owner);
            $this->actingAs($owner);
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $started = Carbon::parse('2026-08-18 12:00:00');
            Carbon::setTestNow($started);
            LegalAcceptance::ensureNoticeStarted($owner);
            $owner->refresh();

            Carbon::setTestNow($started->copy()->addDays(15));

            $response = $this->throughGate(Request::create('/', 'GET'));

            $this->assertTrue($response->isRedirect());
            $this->assertSame(
                AcceptLegalDocuments::getUrl(panel: 'app'),
                $response->headers->get('Location'),
            );

            Livewire::test(AcceptLegalDocuments::class)
                ->fillForm([
                    'accept_terms' => true,
                    'accept_privacy' => false,
                ])
                ->call('accept')
                ->assertHasFormErrors(['accept_privacy']);

            Livewire::test(AcceptLegalDocuments::class)
                ->fillForm([
                    'accept_terms' => true,
                    'accept_privacy' => true,
                ])
                ->call('accept')
                ->assertHasNoFormErrors()
                ->assertRedirect();

            $owner->refresh();
            $this->assertTrue(LegalAcceptance::hasAcceptedCurrent($owner));
            $this->assertSame(TermsOfService::version(), $owner->terms_version);
            $this->assertSame(PrivacyPolicy::version(), $owner->privacy_version);
            $this->assertNull($owner->legal_notice_started_at);

            Carbon::setTestNow($started->copy()->addDays(16));
            $afterAccept = $this->throughGate(Request::create('/', 'GET'));
            $this->assertSame(200, $afterAccept->getStatusCode());

            $this->assertSame($onboardingCountBefore, CustomerOnboarding::on($central)->count());
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function impersonation_skips_the_hard_block(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $owner = $this->createUserWithRole(TenantRole::Owner);
            $this->actingAs($owner);
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $started = Carbon::parse('2026-08-18 12:00:00');
            Carbon::setTestNow($started);
            LegalAcceptance::ensureNoticeStarted($owner);
            $owner->refresh();
            Carbon::setTestNow($started->copy()->addDays(15));

            TenantImpersonation::store([
                'admin_id' => 1,
                'reason' => 'support review',
            ]);

            $response = $this->throughGate(Request::create('/', 'GET'));

            $this->assertSame(200, $response->getStatusCode());
            $this->assertTrue(LegalAcceptance::isHardBlocked($owner));
        } finally {
            TenantImpersonation::forget();
            $this->cleanup($tenant);
        }
    }

    private function throughGate(Request $request)
    {
        $user = auth()->user();
        $request->setUserResolver(static fn () => $user);

        return app(EnsureLegalAcceptance::class)->handle(
            $request,
            static fn () => response('ok'),
        );
    }

    private function createUserWithRole(TenantRole $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user->fresh() ?? $user;
    }

    private function enableJobRoles(Tenant $tenant): void
    {
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        TenantSettings::forTenant($tenant)->setJobRolesEnabled(true);
        $tenant->save();
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
        Filament::setCurrentPanel(Filament::getPanel('app'));

        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->priorJobRolesEnabled = TenantSettings::forTenant($tenant)->jobRolesEnabled();

        return $tenant;
    }

    private function cleanup(Tenant $tenant): void
    {
        Carbon::setTestNow();

        if (tenancy()->initialized && $this->priorJobRolesEnabled !== null) {
            TenantSettings::forTenant($tenant->fresh() ?? $tenant)
                ->setJobRolesEnabled($this->priorJobRolesEnabled);
            $tenant->forceFill(['profile' => TenantProfile::Pharmacy])->save();
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }

        if (tenancy()->initialized) {
            tenancy()->end();
        }

        $this->priorJobRolesEnabled = null;
    }
}
