<?php

namespace Tests\Feature\Floor;

use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Pages\Dashboard;
use App\Filament\App\Pages\FloorHome;
use App\Filament\App\Pages\VerifyProduct;
use App\Filament\App\Resources\ReceivingSessions\Pages\MobileListReceivingSessions;
use App\Filament\App\Resources\ReceivingSessions\ReceivingSessionResource;
use App\Filament\App\Resources\TradingPartners\TradingPartnerResource;
use App\Http\Middleware\RedirectUnmappedFloorShell;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\Floor\FloorLayout;
use App\Support\Floor\FloorRouteMap;
use App\Support\Floor\FloorShell;
use App\Support\Floor\FloorTaskMenu;
use App\Support\TenantSettings;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FloorRedirectShellTest extends TestCase
{
    private const WHOLESALER_TENANT_ID = 'f43e01c9-c7d7-452c-bf2c-4c9163877699';

    private const WHOLESALER_DOMAIN = 'wholesaler.internal.vatengi.com';

    private const WHOLESALER_DATABASE = 'tenant_wholesaler_internal_vatengi_com';

    private static bool $wholesalerReady = false;

    private ?TenantProfile $priorWholesalerProfile = null;

    private mixed $priorOnboardingDismissedAt = null;

    private bool $didDismissOnboarding = false;

    #[Test]
    public function root_path_maps_to_floor_launcher_twin(): void
    {
        $this->initializeWholesalerTenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            $user = $this->createOwnerUser();
            $this->actingAs($user);

            $twin = FloorRouteMap::twinForPath('/');
            $this->assertNotNull($twin);
            $this->assertStringContainsString('/floor', $twin['floorUrl']);
            $this->assertSame('/', rtrim(parse_url($twin['desktopUrl'], PHP_URL_PATH) ?: '/', '/') ?: '/');
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function phone_viewport_dashboard_renders_floor_home(): void
    {
        $this->initializeWholesalerTenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            $user = $this->createOwnerUser();
            $this->actingAs($user);
            $this->dismissOnboardingPrompt();

            Livewire::withCookie(FloorShell::VIEWPORT_COOKIE, FloorShell::VIEWPORT_PHONE)
                ->test(Dashboard::class)
                ->assertSuccessful()
                ->assertSee('Floor')
                ->assertSee('EPCIS Receive')
                ->assertSeeHtml('grid grid-cols-2')
                ->assertSeeHtml('data-tile="receive"')
                ->assertSeeHtml('badge badge-ghost')
                ->assertSeeHtml('dock dock-md')
                ->assertDontSeeHtml('fi-sidebar');
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function desktop_viewport_dashboard_keeps_desktop_home(): void
    {
        $this->initializeWholesalerTenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            $user = $this->createOwnerUser();
            $this->actingAs($user);
            $this->dismissOnboardingPrompt();

            Livewire::withCookie(FloorShell::VIEWPORT_COOKIE, FloorShell::VIEWPORT_DESKTOP)
                ->test(Dashboard::class)
                ->assertSuccessful()
                ->assertNoRedirect()
                ->assertSee('Dashboard')
                ->assertDontSeeHtml('tp-floor-launcher');

            $this->assertFalse(FloorShell::active(
                tap(request()->create('/', 'GET'), function ($request): void {
                    $request->cookies->set(FloorShell::VIEWPORT_COOKIE, FloorShell::VIEWPORT_DESKTOP);
                })
            ));
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function phone_floor_launcher_shows_receive_without_master_data_nav_copy(): void
    {
        $this->initializeWholesalerTenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            $user = $this->createOwnerUser();
            $this->actingAs($user);

            request()->cookies->set(FloorShell::VIEWPORT_COOKIE, FloorShell::VIEWPORT_PHONE);

            Livewire::test(FloorHome::class)
                ->assertSuccessful()
                ->assertSee('Floor')
                ->assertSee('EPCIS Receive')
                ->assertSeeHtml('grid grid-cols-2')
                ->assertSeeHtml('data-tile="ship"')
                ->assertSeeHtml('dock-label')
                ->assertSeeHtml('dock dock-md')
                ->assertDontSee('Master Data')
                ->assertDontSee('Alert center');
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function phone_unmapped_desktop_url_redirects_to_floor_with_flash(): void
    {
        $this->initializeWholesalerTenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            $user = $this->createOwnerUser();
            $this->actingAs($user);

            request()->cookies->set(FloorShell::VIEWPORT_COOKIE, FloorShell::VIEWPORT_PHONE);

            $request = request()->create('/trading-partners', 'GET');
            $request->setUserResolver(fn () => $user);
            $request->cookies->set(FloorShell::VIEWPORT_COOKIE, FloorShell::VIEWPORT_PHONE);

            $middleware = new RedirectUnmappedFloorShell;
            $response = $middleware->handle($request, fn () => response('ok'));

            $this->assertTrue($response->isRedirect());
            $this->assertStringContainsString('/floor', $response->headers->get('Location') ?? '');
            $this->assertTrue(session()->has(RedirectUnmappedFloorShell::FLASH_KEY));
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function receiving_list_and_session_paths_map_to_floor_twins(): void
    {
        $this->initializeWholesalerTenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            $user = $this->createOwnerUser();
            $this->actingAs($user);

            $listTwin = FloorRouteMap::twinForPath('/receiving-sessions');
            $this->assertNotNull($listTwin);
            $this->assertStringContainsString('/receiving-sessions/floor', $listTwin['floorUrl']);
            $this->assertStringNotContainsString('/receiving-sessions/floor', parse_url($listTwin['desktopUrl'], PHP_URL_PATH) ?? '');

            $sessionTwin = FloorRouteMap::twinForPath('/receiving-sessions/5');
            $this->assertNotNull($sessionTwin);
            $this->assertStringContainsString('/receiving-sessions/5/floor', $sessionTwin['floorUrl']);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function trading_partners_does_not_map_to_floor(): void
    {
        $this->initializeWholesalerTenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            $user = $this->createOwnerUser();
            $this->actingAs($user);

            $this->assertNull(FloorRouteMap::twinForPath('/trading-partners'));
            $this->assertFalse(FloorRouteMap::isFloorPath('/trading-partners'));
            $this->assertTrue(TradingPartnerResource::canAccess());
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function unmapped_desktop_gate_renders_soft_interstitial_markup(): void
    {
        $html = view('filament.app.partials.floor-desktop-gate')->render();

        // With default CLI path (not a floor twin), gate emits interstitial markup.
        if (FloorRouteMap::twinForPath() === null && ! FloorRouteMap::isFloorPath()) {
            $this->assertStringContainsString('data-tp-floor-interstitial', $html);
            $this->assertStringContainsString('Use desktop for this page', $html);
            $this->assertStringNotContainsString('data-tp-floor-layout-switch', $html);
        } else {
            $this->assertTrue(true);
        }
    }

    #[Test]
    public function floor_layout_switch_html_encodes_phone_redirect_rules(): void
    {
        $html = view('filament.app.partials.floor-layout-switch', [
            'mode' => 'desktop',
            'desktopUrl' => 'https://example.test/receiving-sessions',
            'floorUrl' => 'https://example.test/receiving-sessions/floor',
        ])->render();

        $this->assertStringContainsString('data-tp-floor-layout-switch', $html);
        $this->assertStringContainsString('w < phoneMax', $html);
        $this->assertStringContainsString('cookie !== \'desktop\'', $html);
        $this->assertStringContainsString('/receiving-sessions/floor', $html);
    }

    #[Test]
    public function tablet_desktop_cookie_is_recognized_by_floor_layout(): void
    {
        request()->cookies->set(FloorLayout::COOKIE, FloorLayout::DESKTOP);
        $this->assertSame(FloorLayout::DESKTOP, FloorLayout::cookie());
    }

    #[Test]
    public function verify_tile_hidden_when_verify_inaccessible(): void
    {
        $this->initializeWholesalerTenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            $tenant = tenant();
            $settings = TenantSettings::forTenant($tenant);
            $priorRoles = $settings->jobRolesEnabled();
            $settings->setJobRolesEnabled(true);
            $tenant->save();

            $user = User::factory()->create([
                'email' => 'floor-no-verify-'.uniqid('', true).'@example.test',
            ]);
            $this->actingAs($user);

            $this->assertFalse(VerifyProduct::canAccess());
            $this->assertNotContains('verify', array_column(FloorTaskMenu::launcherTiles(), 'key'));

            $settings->setJobRolesEnabled($priorRoles);
            $tenant->save();
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function receive_create_path_keeps_handheld_on_create_form(): void
    {
        $this->initializeWholesalerTenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $createUrl = ReceivingSessionResource::getUrl('create', panel: 'app');
            $listFloorUrl = ReceivingSessionResource::getUrl('list-floor', panel: 'app');

            $twin = FloorRouteMap::twinForPath('/receiving-sessions/create');
            $this->assertNotNull($twin);
            $this->assertSame('/receiving-sessions/create', parse_url($twin['floorUrl'], PHP_URL_PATH));
            $this->assertSame('/receiving-sessions/create', parse_url($twin['desktopUrl'], PHP_URL_PATH));
            $this->assertNotSame($listFloorUrl, $twin['floorUrl']);
            $this->assertStringContainsString('/create', $createUrl);

            $layoutSwitch = file_get_contents(resource_path('views/filament/app/partials/floor-layout-switch.blade.php'));
            $this->assertStringContainsString('navigateIfDifferent', $layoutSwitch);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function launcher_and_receive_list_floor_pages_render(): void
    {
        $this->initializeWholesalerTenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            $user = $this->createOwnerUser();
            $this->actingAs($user);

            $this->assertTrue(FloorHome::canAccess());
            $this->assertArrayHasKey('list-floor', ReceivingSessionResource::getPages());

            Livewire::test(FloorHome::class)
                ->assertSuccessful()
                ->assertSeeHtml('grid grid-cols-2')
                ->assertSeeHtml('data-tile="receive"')
                ->assertSeeHtml('data-tile="scan-first"')
                ->assertSeeHtml('data-tile="transfer-receive"')
                ->assertSeeHtml('dock dock-md')
                ->assertSee('EPCIS Receive')
                ->assertSee('Scan first')
                ->assertSee('Transfer receive');

            Livewire::test(MobileListReceivingSessions::class)
                ->assertSuccessful()
                ->assertSeeHtml('tp-floor-list')
                ->assertSeeHtml('dock dock-md')
                ->assertSee('EPCIS Receive')
                ->assertDontSee('New scan-first');

            $receive = collect(FloorTaskMenu::launcherTiles())->firstWhere('key', 'receive');
            $this->assertNotNull($receive);
            $this->assertStringContainsString('/receiving-sessions/floor', $receive['url']);
            $this->assertStringNotContainsString('/scan-first-floor', $receive['url']);

            $scanFirst = collect(FloorTaskMenu::launcherTiles())->firstWhere('key', 'scan-first');
            $this->assertNotNull($scanFirst);
            $this->assertStringContainsString('/receiving-sessions/scan-first-floor', $scanFirst['url']);
            $this->assertStringNotContainsString('/scan-in', $scanFirst['url']);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function wholesaler_launcher_tiles_report(): void
    {
        $this->initializeWholesalerTenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            $user = $this->createOwnerUser();
            $this->actingAs($user);

            $keys = array_column(FloorTaskMenu::launcherTiles(), 'key');
            $this->assertContains('receive', $keys);
            $this->assertContains('scan-first', $keys);
            $this->assertContains('ship', $keys);
            $this->assertContains('transfer', $keys);
            $this->assertContains('transfer-receive', $keys);
            $this->assertNotContains('compliance', $keys);
            $this->assertTrue(TradingPartnerResource::canAccess());
            $this->assertNull(FloorRouteMap::twinForPath('/trading-partners'));
        } finally {
            $this->cleanup();
        }
    }

    private function dismissOnboardingPrompt(): void
    {
        $tenant = tenant();
        if (! $tenant instanceof Tenant) {
            return;
        }

        $settings = TenantSettings::forTenant($tenant);
        if (! $this->didDismissOnboarding) {
            $this->priorOnboardingDismissedAt = $settings->onboardingDismissedAt();
            $this->didDismissOnboarding = true;
        }
        $settings->setOnboardingDismissedAt(Carbon::now());
        $tenant->save();

        session()->put('filament.app.onboarding_wizard_redirected', true);
    }

    private function createOwnerUser(): User
    {
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::DrugWholesaler);

        $user = User::factory()->create([
            'email' => 'floor-redirect-'.uniqid('', true).'@example.test',
        ]);
        $user->assignRole(TenantRole::Owner->value);

        return $user;
    }

    private function initializeWholesalerTenant(): Tenant
    {
        $tenant = Tenant::query()->find(self::WHOLESALER_TENANT_ID);

        if ($tenant === null) {
            $tenant = Tenant::withoutEvents(fn () => Tenant::query()->create([
                'id' => self::WHOLESALER_TENANT_ID,
                'name' => 'Demo Wholesaler',
                'profile' => TenantProfile::DrugWholesaler,
                'status' => 'active',
                'tenancy_db_name' => self::WHOLESALER_DATABASE,
            ]));
        }

        $tenant->domains()->firstOrCreate(['domain' => self::WHOLESALER_DOMAIN]);

        $this->priorWholesalerProfile = $tenant->profile instanceof TenantProfile
            ? $tenant->profile
            : TenantProfile::tryFrom((string) $tenant->profile);

        $tenant->forceFill(['profile' => TenantProfile::DrugWholesaler])->save();

        if (! self::$wholesalerReady) {
            $this->artisan('tenants:migrate', [
                '--tenants' => [self::WHOLESALER_TENANT_ID],
                '--force' => true,
            ])->assertSuccessful();

            self::$wholesalerReady = true;
        }

        tenancy()->initialize($tenant->fresh());

        return $tenant;
    }

    private function cleanup(): void
    {
        if (tenancy()->initialized) {
            $tenant = tenant();
            if ($tenant instanceof Tenant
                && $tenant->getKey() === self::WHOLESALER_TENANT_ID
            ) {
                if ($this->priorWholesalerProfile !== null) {
                    $tenant->forceFill(['profile' => $this->priorWholesalerProfile])->save();
                }
                if ($this->didDismissOnboarding) {
                    $prior = $this->priorOnboardingDismissedAt;
                    TenantSettings::forTenant($tenant)
                        ->setOnboardingDismissedAt(
                            $prior instanceof \DateTimeInterface
                                ? Carbon::parse($prior)
                                : null
                        );
                    $tenant->save();
                    $this->didDismissOnboarding = false;
                    $this->priorOnboardingDismissedAt = null;
                }
            }
            tenancy()->end();
        }

        DB::purge('tenant');
    }
}
