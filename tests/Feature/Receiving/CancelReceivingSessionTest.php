<?php

namespace Tests\Feature\Receiving;

use App\Actions\Receiving\CancelReceivingSession;
use App\Actions\Receiving\OpenScanFirstReceivingSession;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Resources\ReceivingSessions\Pages\ViewReceivingSession;
use App\Models\Receiving\ReceivingSession;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use DomainException;
use Filament\Facades\Filament;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CancelReceivingSessionTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $sessionIds = [];

    /** @var list<int> */
    private array $userIds = [];

    #[Test]
    public function it_cancels_open_and_in_progress_sessions(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $open = app(OpenScanFirstReceivingSession::class)->handle();
            $this->trackSession($open);
            $this->assertTrue($open->canCancel());

            $cancelledOpen = app(CancelReceivingSession::class)->handle($open);
            $this->assertSame('cancelled', $cancelledOpen->status);
            $this->assertNotNull($cancelledOpen->completed_at);
            $this->assertFalse($cancelledOpen->canCancel());

            $inProgress = app(OpenScanFirstReceivingSession::class)->handle();
            $this->trackSession($inProgress);
            $inProgress->forceFill(['status' => 'in_progress'])->save();
            $this->assertTrue($inProgress->fresh()->canCancel());

            $cancelledInProgress = app(CancelReceivingSession::class)->handle($inProgress->fresh());
            $this->assertSame('cancelled', $cancelledInProgress->status);
            $this->assertNotNull($cancelledInProgress->completed_at);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function it_blocks_completed_cancelled_and_authored_receiving_epcis(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $completed = app(OpenScanFirstReceivingSession::class)->handle();
            $this->trackSession($completed);
            $completed->forceFill([
                'status' => 'completed',
                'completed_at' => now(),
            ])->save();
            $this->assertFalse($completed->fresh()->canCancel());

            try {
                app(CancelReceivingSession::class)->handle($completed->fresh());
                $this->fail('Expected DomainException for completed session.');
            } catch (DomainException $e) {
                $this->assertStringContainsString('completed', $e->getMessage());
            }

            $alreadyCancelled = app(OpenScanFirstReceivingSession::class)->handle();
            $this->trackSession($alreadyCancelled);
            $alreadyCancelled->forceFill([
                'status' => 'cancelled',
                'completed_at' => now(),
            ])->save();

            try {
                app(CancelReceivingSession::class)->handle($alreadyCancelled->fresh());
                $this->fail('Expected DomainException for cancelled session.');
            } catch (DomainException $e) {
                $this->assertStringContainsString('cancelled', $e->getMessage());
            }

            $authored = app(OpenScanFirstReceivingSession::class)->handle();
            $this->trackSession($authored);
            $authored->forceFill([
                'status' => 'in_progress',
                'receiving_events_generated_at' => now(),
            ])->save();
            $this->assertFalse($authored->fresh()->canCancel());

            try {
                app(CancelReceivingSession::class)->handle($authored->fresh());
                $this->fail('Expected DomainException for authored receiving EPCIS.');
            } catch (DomainException $e) {
                $this->assertStringContainsString('authored receiving EPCIS', $e->getMessage());
            }
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function cancel_receiving_action_is_visible_on_active_view_and_cancels(): void
    {
        $this->initializeDemo2Tenant();

        try {
            config(['tracepharma.regulatory_compliance.password_gate' => false]);
            Filament::setCurrentPanel(Filament::getPanel('app'));
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);

            $user = User::factory()->create([
                'email' => 'cancel-receive-'.uniqid('', true).'@example.test',
            ]);
            $this->userIds[] = (int) $user->getKey();
            $user->assignRole(TenantRole::Owner->value);
            $this->actingAs($user);

            $session = app(OpenScanFirstReceivingSession::class)->handle();
            $this->trackSession($session);

            Livewire::test(ViewReceivingSession::class, ['record' => $session->getKey()])
                ->assertActionVisible('cancelReceiving')
                ->callAction('cancelReceiving')
                ->assertHasNoActionErrors()
                ->assertRedirect();

            $session->refresh();
            $this->assertSame('cancelled', $session->status);
            $this->assertNotNull($session->completed_at);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function cancel_receiving_action_is_hidden_when_completed(): void
    {
        $this->initializeDemo2Tenant();

        try {
            config(['tracepharma.regulatory_compliance.password_gate' => false]);
            Filament::setCurrentPanel(Filament::getPanel('app'));
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);

            $user = User::factory()->create([
                'email' => 'cancel-hidden-'.uniqid('', true).'@example.test',
            ]);
            $this->userIds[] = (int) $user->getKey();
            $user->assignRole(TenantRole::Owner->value);
            $this->actingAs($user);

            $session = app(OpenScanFirstReceivingSession::class)->handle();
            $this->trackSession($session);
            $session->forceFill([
                'status' => 'completed',
                'completed_at' => now(),
            ])->save();

            Livewire::test(ViewReceivingSession::class, ['record' => $session->getKey()])
                ->assertActionHidden('cancelReceiving');
        } finally {
            $this->cleanup();
        }
    }

    private function trackSession(ReceivingSession $session): void
    {
        $this->sessionIds[] = (int) $session->getKey();
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
        if ($this->sessionIds !== []) {
            ReceivingSession::query()->whereIn('id', $this->sessionIds)->delete();
            $this->sessionIds = [];
        }

        foreach ($this->userIds as $userId) {
            User::query()->whereKey($userId)->delete();
        }
        $this->userIds = [];

        if (tenancy()->initialized) {
            tenancy()->end();
        }
    }
}
