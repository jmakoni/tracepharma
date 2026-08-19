<?php

namespace Tests\Feature\MasterData;

use App\Enums\PartnerType;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Resources\Sites\Pages\CreateSite;
use App\Filament\App\Resources\TradingPartners\Pages\ListTradingPartners;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Models\User;
use App\Rules\RejectTenantGln;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\Gs1\Gtin;
use App\Support\TenantSettings;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * EPCIS ingest already refuses to mirror our own GLNs into the partner list; this covers
 * the manual entry path, where an operator typing our GLN onto a trading partner or one
 * of its sites would create the same self-partner by hand.
 *
 * GLNs are prefixed 094223 so rows stay traceable in the shared demo2 tenant.
 */
class RejectTenantGlnTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private const GLN_PREFIX = '094223';

    private static bool $demo2TenantReady = false;

    private ?string $priorGln = null;

    private ?string $priorCompanyPrefix = null;

    /** @var list<int> */
    private array $userIds = [];

    #[Test]
    public function the_rule_rejects_our_own_glns_and_lets_real_counterparties_through(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $organizationGln = $this->uniqueGln('10');
            $facilityGln = $this->uniqueGln('11');
            $partnerGln = $this->uniqueGln('12');

            $this->createOrganizationFacility($facilityGln);
            $this->useOrganizationGln($tenant, $organizationGln);

            $this->assertTrue($this->isRejected($organizationGln), 'Our organization GLN is not a counterparty.');
            $this->assertTrue($this->isRejected($facilityGln), 'Our own facilities are not counterparties.');
            $this->assertFalse($this->isRejected($partnerGln), 'A real counterparty GLN must pass.');
            $this->assertFalse($this->isRejected(''), 'Blank is left to the required rule.');
            $this->assertFalse($this->isRejected(null));
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function a_partner_owned_site_may_not_take_one_of_our_glns(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $organizationGln = $this->uniqueGln('20');
            $tenant = $this->useOrganizationGln($tenant, $organizationGln);
            $this->actAsMasterDataOwner();

            $partner = TradingPartner::query()->create([
                'name' => 'Reject Gln Supplier 094223',
                'gln' => $this->uniqueGln('21'),
                'partner_type' => PartnerType::Wholesaler,
                'country_code' => 'US',
                'is_active' => true,
            ]);

            Livewire::test(CreateSite::class)
                ->fillForm([
                    'trading_partner_id' => $partner->getKey(),
                    'name' => 'Reject Gln Partner Dock 094223',
                    'gln' => $organizationGln,
                ])
                ->call('create')
                ->assertHasFormErrors(['gln']);

            $this->assertNull(
                Site::query()->where('gln', $organizationGln)->first(),
                'A rejected site must not persist.',
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function an_organization_facility_may_carry_the_organization_gln(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $organizationGln = $this->uniqueGln('30');
            $tenant = $this->useOrganizationGln($tenant, $organizationGln);
            $this->actAsMasterDataOwner();

            Livewire::test(CreateSite::class)
                ->fillForm([
                    'trading_partner_id' => null,
                    'name' => 'Reject Gln Our Dock 094223',
                    'gln' => $organizationGln,
                ])
                ->call('create')
                ->assertHasNoFormErrors(['gln']);

            $site = Site::query()->where('gln', $organizationGln)->first();

            $this->assertNotNull($site, 'Our own facility is exactly where this GLN belongs.');
            $this->assertNull($site->trading_partner_id);
            $this->assertTrue((bool) $site->is_organization_facility);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function the_trading_partner_form_rejects_our_own_gln(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $organizationGln = $this->uniqueGln('40');
            $tenant = $this->useOrganizationGln($tenant, $organizationGln);
            $this->actAsMasterDataOwner();

            Livewire::test(ListTradingPartners::class)
                ->mountAction('create')
                ->setActionData([
                    'name' => 'Reject Gln Us As Partner 094223',
                    'gln' => $organizationGln,
                    'partner_type' => PartnerType::Wholesaler->value,
                    'is_active' => true,
                ])
                ->callMountedAction()
                ->assertHasActionErrors(['gln']);

            $this->assertNull(
                TradingPartner::query()->where('gln', $organizationGln)->first(),
                'We are never our own trading partner.',
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    private function isRejected(mixed $gln): bool
    {
        $rejected = false;

        (new RejectTenantGln)->validate('gln', $gln, function () use (&$rejected): void {
            $rejected = true;
        });

        return $rejected;
    }

    private function actAsMasterDataOwner(): void
    {
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);

        $user = User::factory()->create();
        $user->assignRole(TenantRole::Owner->value);
        $this->userIds[] = (int) $user->getKey();

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('app'));
    }

    private function createOrganizationFacility(string $gln): Site
    {
        return Site::query()->create([
            'name' => 'Reject Gln Dock 094223',
            'gln' => $gln,
            'is_active' => true,
            'is_organization_facility' => true,
            'country_code' => 'US',
        ]);
    }

    private function useOrganizationGln(Tenant $tenant, string $gln): Tenant
    {
        TenantSettings::forTenant($tenant)
            ->setGln($gln)
            ->setCompanyPrefix(substr($gln, 0, 7));
        $tenant->save();

        tenancy()->end();
        $fresh = $tenant->fresh();
        tenancy()->initialize($fresh);

        return $fresh;
    }

    private function uniqueGln(string $marker): string
    {
        $body12 = self::GLN_PREFIX.$marker.str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);

        return $body12.Gtin::checkDigit($body12);
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

        $settings = TenantSettings::forTenant($tenant);
        $this->priorGln = $settings->gln();
        $this->priorCompanyPrefix = $settings->companyPrefix();

        return $tenant;
    }

    private function cleanup(Tenant $tenant): void
    {
        if (tenancy()->initialized) {
            DB::table('sites')->where('gln', 'like', self::GLN_PREFIX.'%')->delete();
            DB::table('trading_partners')->where('gln', 'like', self::GLN_PREFIX.'%')->delete();

            if ($this->userIds !== []) {
                DB::table('model_has_roles')
                    ->where('model_type', User::class)
                    ->whereIn('model_id', $this->userIds)
                    ->delete();
                User::query()->whereIn('id', $this->userIds)->delete();
            }

            $current = $tenant->fresh() ?? $tenant;
            TenantSettings::forTenant($current)
                ->setGln($this->priorGln)
                ->setCompanyPrefix($this->priorCompanyPrefix);
            $current->save();

            tenancy()->end();
        }

        $this->userIds = [];
        $this->priorGln = null;
        $this->priorCompanyPrefix = null;
    }
}
