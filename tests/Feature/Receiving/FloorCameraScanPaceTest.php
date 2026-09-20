<?php

namespace Tests\Feature\Receiving;

use App\Actions\Receiving\OpenScanFirstReceivingSession;
use App\Enums\FloorCameraScanPace;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Resources\ReceivingSessions\Pages\MobileViewReceivingSession;
use App\Models\Receiving\ReceivingSession;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FloorCameraScanPaceTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    private ?int $sessionId = null;

    #[Test]
    public function floor_receive_persists_camera_scan_pace_for_user(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $user = $this->createOwnerUser();
            $this->actingAs($user);

            $session = app(OpenScanFirstReceivingSession::class)->handle();
            $this->sessionId = (int) $session->getKey();

            $this->assertSame(FloorCameraScanPace::Balanced, $user->fresh()->floorCameraScanPace());

            Livewire::test(MobileViewReceivingSession::class, [
                'record' => $session->getKey(),
            ])
                ->call('setFloorCameraScanPace', 'rapid')
                ->assertHasNoErrors();

            $user->refresh();
            $this->assertSame(FloorCameraScanPace::Rapid, $user->floorCameraScanPace());
            $this->assertSame('rapid', data_get($user->preferences, 'floor.camera_scan_pace'));

            $config = $user->floorCameraScanAlpineConfig();
            $this->assertSame(450, $config['cooldownMs']);
            $this->assertSame(30, $config['fps']);

            Livewire::test(MobileViewReceivingSession::class, [
                'record' => $session->getKey(),
            ])
                ->call('setFloorCameraScanPace', 'careful')
                ->assertHasNoErrors();

            $user->refresh();
            $this->assertSame(FloorCameraScanPace::Careful, $user->floorCameraScanPace());
            $this->assertSame(1800, $user->floorCameraScanAlpineConfig()['cooldownMs']);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function invalid_pace_falls_back_to_balanced_without_error(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $user = $this->createOwnerUser();
            $user->setFloorCameraScanPace(FloorCameraScanPace::Rapid);
            $user->save();
            $this->actingAs($user);

            $session = app(OpenScanFirstReceivingSession::class)->handle();
            $this->sessionId = (int) $session->getKey();

            Livewire::test(MobileViewReceivingSession::class, [
                'record' => $session->getKey(),
            ])
                ->call('setFloorCameraScanPace', 'not-a-pace')
                ->assertHasNoErrors();

            $user->refresh();
            $this->assertSame(FloorCameraScanPace::Balanced, $user->floorCameraScanPace());
        } finally {
            $this->cleanup();
        }
    }

    private function createOwnerUser(): User
    {
        $profile = tenant()?->profile instanceof TenantProfile
            ? tenant()->profile
            : TenantProfile::Pharmacy;

        app(TenantRoleSeeder::class)->seedForProfile($profile);

        $user = User::factory()->create([
            'email' => 'pace-'.uniqid('', true).'@example.test',
        ]);
        $user->assignRole(TenantRole::Owner->value);

        return $user;
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

        try {
            if ($this->sessionId !== null) {
                ReceivingSession::query()->whereKey($this->sessionId)->delete();
            }
        } finally {
            $this->sessionId = null;
            tenancy()->end();
        }
    }
}
