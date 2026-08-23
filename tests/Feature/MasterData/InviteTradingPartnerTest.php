<?php

namespace Tests\Feature\MasterData;

use App\Enums\InboundTransport;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Pages\InviteTradingPartner;
use App\Filament\App\Resources\TradingPartners\TradingPartnerResource;
use App\Models\InboundConnection;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InviteTradingPartnerTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $partnerIds = [];

    /** @var list<int> */
    private array $connectionIds = [];

    #[Test]
    public function page_creates_partner_and_https_inbound_without_rewriting_partners(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->assertSame('Trading Partners', TradingPartnerResource::getNavigationLabel());
            $this->assertSame('invite-partner', InviteTradingPartner::getSlug());

            Filament::setCurrentPanel(Filament::getPanel('app'));
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            $user = User::factory()->create();
            $user->assignRole(TenantRole::Owner->value);
            $this->actingAs($user);
            $this->assertTrue(InviteTradingPartner::canAccess());

            $gln = '03'.str_pad((string) random_int(0, 99_999_999_999), 11, '0', STR_PAD_LEFT);

            Livewire::test(InviteTradingPartner::class)
                ->assertSuccessful()
                ->set('name', 'Invite Partner Co')
                ->set('gln', $gln)
                ->set('email', 'invite-poc@example.test')
                ->set('transport', 'https')
                ->callAction('invitePartner')
                ->assertHasNoActionErrors();

            $partner = TradingPartner::query()->where('gln', $gln)->first();
            $this->assertNotNull($partner);
            $this->partnerIds[] = (int) $partner->getKey();
            $this->assertSame('invite-poc@example.test', $partner->email);

            $connection = InboundConnection::query()
                ->where('trading_partner_id', $partner->getKey())
                ->first();
            $this->assertNotNull($connection);
            $this->connectionIds[] = (int) $connection->getKey();
            $this->assertSame(InboundTransport::Https, $connection->transport);
            $this->assertTrue($connection->is_active);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function non_owner_cannot_invite_a_partner(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            $user = User::factory()->create();
            $this->actingAs($user);

            $this->assertFalse(InviteTradingPartner::canAccess());
            Livewire::test(InviteTradingPartner::class)->assertForbidden();
        } finally {
            $this->cleanup();
        }
    }

    private function initializeDemo2Tenant(): void
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
        }

        if (! self::$demo2TenantReady) {
            $this->artisan('tenants:migrate', [
                '--tenants' => [self::DEMO2_TENANT_ID],
                '--force' => true,
            ])->assertSuccessful();
            self::$demo2TenantReady = true;
        }

        tenancy()->initialize($tenant);
    }

    private function cleanup(): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        foreach ($this->connectionIds as $connectionId) {
            InboundConnection::query()->whereKey($connectionId)->delete();
        }
        $this->connectionIds = [];

        foreach ($this->partnerIds as $partnerId) {
            TradingPartner::query()->whereKey($partnerId)->delete();
        }
        $this->partnerIds = [];

        tenancy()->end();
    }
}
