<?php

declare(strict_types=1);

namespace Tests\Feature\MasterData;

use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Resources\OutboundShippingSessions\Pages\ListOutboundShippingSessions;
use App\Filament\App\Resources\Principals\Pages\ListPrincipals;
use App\Filament\App\Resources\ReceivingSessions\Pages\ListReceivingSessions;
use App\Models\Epcis\Epc;
use App\Models\Receiving\ReceivingSession;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\Custody\PrincipalCustody;
use App\Support\OnboardingCopy;
use App\Support\PrincipalsHonesty;
use App\Support\TenantFeatures;
use App\Support\TenantSettings;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PrincipalsHonestyTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    private ?TenantProfile $priorProfile = null;

    /** @var list<int> */
    private array $userIds = [];

    #[Test]
    public function logistics_3pl_lists_show_principals_honesty_sentence(): void
    {
        $this->initializeDemo2Tenant(TenantProfile::Logistics3pl);

        try {
            TenantSettings::forTenant(tenant())->setPrincipalCustodyEnforced(false);
            tenant()->save();

            Filament::setCurrentPanel(Filament::getPanel('app'));
            $this->actingAs($this->createOwner(TenantProfile::Logistics3pl));

            $this->assertTrue(TenantFeatures::forTenant(tenant())->supportsPrincipals());
            $this->assertTrue(PrincipalsHonesty::forTenant()->shouldShow());
            $this->assertSame(PrincipalsHonesty::SENTENCE, PrincipalsHonesty::forTenant()->sentence());

            Livewire::test(ListPrincipals::class)
                ->assertSuccessful()
                ->assertSee(PrincipalsHonesty::SENTENCE);

            Livewire::test(ListReceivingSessions::class)
                ->assertSuccessful()
                ->assertSee(PrincipalsHonesty::SENTENCE);

            Livewire::test(ListOutboundShippingSessions::class)
                ->assertSuccessful()
                ->assertSee(PrincipalsHonesty::SENTENCE);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function logistics_3pl_enforced_hides_soft_sentence_and_shows_enforced_sentence(): void
    {
        $this->initializeDemo2Tenant(TenantProfile::Logistics3pl);

        try {
            TenantSettings::forTenant(tenant())->setPrincipalCustodyEnforced(true);
            tenant()->save();

            Filament::setCurrentPanel(Filament::getPanel('app'));
            $this->actingAs($this->createOwner(TenantProfile::Logistics3pl));

            $this->assertTrue(PrincipalCustody::forTenant()->isEnforced());
            $honesty = PrincipalsHonesty::forTenant();
            $this->assertTrue($honesty->shouldShow());
            $this->assertSame(PrincipalsHonesty::ENFORCED_SENTENCE, $honesty->sentence());
            $this->assertNotSame(PrincipalsHonesty::SENTENCE, $honesty->sentence());

            Livewire::test(ListPrincipals::class)
                ->assertSuccessful()
                ->assertSee(PrincipalsHonesty::ENFORCED_SENTENCE)
                ->assertDontSee(PrincipalsHonesty::SENTENCE);

            Livewire::test(ListReceivingSessions::class)
                ->assertSuccessful()
                ->assertSee(PrincipalsHonesty::ENFORCED_SENTENCE)
                ->assertDontSee(PrincipalsHonesty::SENTENCE);

            Livewire::test(ListOutboundShippingSessions::class)
                ->assertSuccessful()
                ->assertSee(PrincipalsHonesty::ENFORCED_SENTENCE)
                ->assertDontSee(PrincipalsHonesty::SENTENCE);
        } finally {
            if (tenancy()->initialized) {
                TenantSettings::forTenant(tenant())->setPrincipalCustodyEnforced(false);
                tenant()->save();
            }
            $this->cleanup();
        }
    }

    #[Test]
    public function pharmacy_lists_do_not_show_principals_honesty_sentence(): void
    {
        $this->initializeDemo2Tenant(TenantProfile::Pharmacy);

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            $this->actingAs($this->createOwner(TenantProfile::Pharmacy));

            $this->assertFalse(TenantFeatures::forTenant(tenant())->supportsPrincipals());
            $this->assertFalse(PrincipalsHonesty::forTenant()->shouldShow());

            Livewire::test(ListReceivingSessions::class)
                ->assertSuccessful()
                ->assertDontSee(PrincipalsHonesty::SENTENCE);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function logistics_3pl_onboarding_copy_includes_honesty_sentence(): void
    {
        $copy = OnboardingCopy::forTenant(
            (new Tenant)->forceFill(['profile' => TenantProfile::Logistics3pl])
        );

        $this->assertStringContainsString(PrincipalsHonesty::SENTENCE, $copy->banner());
        $this->assertStringContainsString(PrincipalsHonesty::SENTENCE, $copy->subheading());
        $this->assertStringContainsString('3PL', $copy->banner());
    }

    #[Test]
    public function principal_custody_enforcement_defaults_off_with_schema_ready(): void
    {
        $this->initializeDemo2Tenant(TenantProfile::Logistics3pl);

        try {
            TenantSettings::forTenant(tenant())->setPrincipalCustodyEnforced(false);
            tenant()->save();

            $this->assertTrue(Schema::hasColumn((new Epc)->getTable(), 'principal_id'));
            $this->assertTrue(Schema::hasColumn((new ReceivingSession)->getTable(), 'principal_id'));
            $this->assertTrue(in_array('principal_id', (new Epc)->getFillable(), true));
            $this->assertFalse(TenantSettings::forTenant(tenant())->principalCustodyEnforced());
            $this->assertFalse(PrincipalCustody::forTenant()->isEnforced());
        } finally {
            $this->cleanup();
        }
    }

    private function createOwner(TenantProfile $profile): User
    {
        app(TenantRoleSeeder::class)->seedForProfile($profile);

        $user = User::factory()->create();
        $user->assignRole(TenantRole::Owner->value);
        $this->userIds[] = (int) $user->getKey();

        return $user;
    }

    private function initializeDemo2Tenant(TenantProfile $profile): Tenant
    {
        $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);

        if ($tenant === null) {
            $tenant = Tenant::withoutEvents(fn () => Tenant::query()->create([
                'id' => self::DEMO2_TENANT_ID,
                'name' => 'Demo 2',
                'profile' => $profile,
                'status' => 'active',
                'tenancy_db_name' => self::DEMO2_DATABASE,
            ]));
            $tenant->domains()->create(['domain' => self::DEMO2_DOMAIN]);
        } else {
            $this->priorProfile = $tenant->profile;
            $tenant->forceFill(['profile' => $profile])->save();
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
        if (tenancy()->initialized && $this->userIds !== []) {
            User::query()->whereIn('id', $this->userIds)->delete();
            $this->userIds = [];
        }

        tenancy()->end();

        if ($this->priorProfile !== null) {
            Tenant::query()->whereKey(self::DEMO2_TENANT_ID)->update([
                'profile' => $this->priorProfile,
            ]);
            $this->priorProfile = null;
        }
    }
}
