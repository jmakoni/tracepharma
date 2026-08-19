<?php

namespace Tests\Feature\MasterData;

use App\Enums\AtpVerificationSource;
use App\Enums\PartnerType;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Resources\TradingPartners\Pages\ViewTradingPartner;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Recording that a partner is an authorized trading partner: who looked, when, and against
 * what evidence. The record belongs to the partner, so a later compliance review can show
 * the diligence without re-running the lookup.
 */
class TradingPartnerAtpVerificationTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $partnerIds = [];

    /** @var list<int> */
    private array $userIds = [];

    #[Test]
    public function master_data_maintainer_records_a_verification_that_persists(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            $user = $this->createUserWithRole(TenantRole::Owner);
            $partner = $this->createPartner();

            Livewire::test(ViewTradingPartner::class, ['record' => $partner->getKey()])
                ->callAction('recordAtpVerification', [
                    'atp_verified_at' => '2026-08-14 09:30:00',
                    'atp_verification_source' => AtpVerificationSource::FdaWdd3pl->value,
                    'atp_verification_url' => 'https://example.test/wdd/lookup',
                    'atp_verification_note' => 'WDD registry lookup for IL and TX.',
                ])
                ->assertHasNoActionErrors();

            $partner->refresh();

            $this->assertSame('2026-08-14 09:30:00', $partner->atp_verified_at?->toDateTimeString());
            $this->assertSame((int) $user->getKey(), (int) $partner->atp_verified_by);
            $this->assertSame(AtpVerificationSource::FdaWdd3pl, $partner->atp_verification_source);
            $this->assertSame('https://example.test/wdd/lookup', $partner->atp_verification_url);
            $this->assertSame('WDD registry lookup for IL and TX.', $partner->atp_verification_note);
            $this->assertSame((int) $user->getKey(), (int) $partner->atpVerifier?->getKey());
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function source_options_include_fda_decrs(): void
    {
        $options = AtpVerificationSource::options();

        $this->assertArrayHasKey(AtpVerificationSource::FdaDecrs->value, $options);
        $this->assertSame('FDA DECRS', $options[AtpVerificationSource::FdaDecrs->value]);
    }

    #[Test]
    public function manufacturer_verification_can_use_decrs(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            $user = $this->createUserWithRole(TenantRole::Owner);
            $partner = $this->createPartner([
                'partner_type' => PartnerType::Manufacturer,
            ]);

            Livewire::test(ViewTradingPartner::class, ['record' => $partner->getKey()])
                ->callAction('recordAtpVerification', [
                    'atp_verified_at' => '2026-08-16 19:00:00',
                    'atp_verification_source' => AtpVerificationSource::FdaDecrs->value,
                    'atp_verification_note' => 'DECRS plant registration for the manufacturer HQ.',
                ])
                ->assertHasNoActionErrors();

            $partner->refresh();

            $this->assertSame(AtpVerificationSource::FdaDecrs, $partner->atp_verification_source);
            $this->assertSame((int) $user->getKey(), (int) $partner->atp_verified_by);
            $this->assertSame('DECRS plant registration for the manufacturer HQ.', $partner->atp_verification_note);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function a_later_verification_replaces_the_previous_one(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            $this->createUserWithRole(TenantRole::Owner);
            $partner = $this->createPartner([
                'atp_verified_at' => now()->subYear(),
                'atp_verification_source' => AtpVerificationSource::PartnerDocument,
                'atp_verification_note' => 'License copy emailed by the partner.',
            ]);

            Livewire::test(ViewTradingPartner::class, ['record' => $partner->getKey()])
                ->callAction('recordAtpVerification', [
                    'atp_verified_at' => '2026-08-14 11:00:00',
                    'atp_verification_source' => AtpVerificationSource::StateBoard->value,
                    'atp_verification_note' => '',
                ])
                ->assertHasNoActionErrors();

            $partner->refresh();

            $this->assertSame('2026-08-14 11:00:00', $partner->atp_verified_at?->toDateTimeString());
            $this->assertSame(AtpVerificationSource::StateBoard, $partner->atp_verification_source);
            $this->assertNull($partner->atp_verification_note);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function a_user_who_cannot_update_partners_does_not_get_the_action(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            $this->createUserWithRole(TenantRole::ReceivingTechnician);
            $partner = $this->createPartner();

            Livewire::test(ViewTradingPartner::class, ['record' => $partner->getKey()])
                ->assertActionHidden('recordAtpVerification');

            $this->assertNull($partner->fresh()?->atp_verified_at);
        } finally {
            $this->cleanup();
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createPartner(array $attributes = []): TradingPartner
    {
        $partner = TradingPartner::query()->create(array_merge([
            'name' => 'ATP Verification Partner '.Str::random(6),
            'gln' => fake()->unique()->numerify('#############'),
            'partner_type' => PartnerType::Wholesaler,
            'country_code' => 'US',
            'is_active' => true,
        ], $attributes));

        $this->partnerIds[] = (int) $partner->getKey();

        return $partner;
    }

    private function createUserWithRole(TenantRole $role): User
    {
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);

        $user = User::factory()->create();
        $user->assignRole($role->value);
        $this->userIds[] = (int) $user->getKey();

        $this->actingAs($user);

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
        if (tenancy()->initialized) {
            if ($this->partnerIds !== []) {
                TradingPartner::query()->whereIn('id', $this->partnerIds)->delete();
                $this->partnerIds = [];
            }

            if ($this->userIds !== []) {
                User::query()->whereIn('id', $this->userIds)->delete();
                $this->userIds = [];
            }

            tenancy()->end();
        }
    }
}
