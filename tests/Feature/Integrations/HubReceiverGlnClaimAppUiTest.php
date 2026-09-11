<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Pages\IntegrationHealth;
use App\Filament\App\Resources\Sites\Pages\ViewSite;
use App\Models\EpcisHubRoute;
use App\Models\HubReceiverGlnClaimRequest;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\EpcisHub\EpcisHubPlatformConfig;
use App\Support\Gs1\Gtin;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HubReceiverGlnClaimAppUiTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private const COMPANY_GLN = '0366159000010';

    private const HELPER_COPY = 'Binding a site GLN or inbound connection is not a hub claim until platform Admin approves.';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $siteIds = [];

    /** @var list<int> */
    private array $userIds = [];

    /** @var array{profile: mixed, gln: mixed, inbound_environment: mixed, hub_providers: mixed}|null */
    private ?array $originalTenantState = null;

    #[Test]
    public function integration_health_owner_can_request_a_claim_for_a_claimable_receiver_gln(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->actingAs($this->createUser(TenantRole::Owner, TenantProfile::Pharmacy));
            $site = $this->createOrganizationSite();

            Livewire::test(IntegrationHealth::class)
                ->assertActionVisible('requestHubReceiverGlnClaim')
                ->mountAction('requestHubReceiverGlnClaim')
                ->assertMountedActionModalSee(self::HELPER_COPY)
                ->assertFormFieldExists(
                    'gln',
                    fn (Select $field): bool => array_key_exists((string) $site->gln, $field->getOptions()),
                )
                ->fillForm([
                    'provider' => 'systech',
                    'gln' => $site->gln,
                    'reason' => 'Enable hub receiving for this facility.',
                ])
                ->callMountedAction()
                ->assertHasNoActionErrors();

            $this->assertTrue(HubReceiverGlnClaimRequest::query()
                ->where('tenant_id', $tenant->getKey())
                ->where('provider', 'systech')
                ->where('gln', $site->gln)
                ->where('reason', 'Enable hub receiving for this facility.')
                ->pending()
                ->exists());
            $this->assertFalse(EpcisHubRoute::query()
                ->where('tenant_id', $tenant->getKey())
                ->where('gln', $site->gln)
                ->exists());
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function view_site_owner_requests_the_prefilled_site_gln_without_a_gln_field(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->actingAs($this->createUser(TenantRole::Owner, TenantProfile::Pharmacy));
            $site = $this->createOrganizationSite();

            Livewire::test(ViewSite::class, ['record' => $site->getKey()])
                ->assertActionVisible('requestHubReceiverGlnClaim')
                ->mountAction('requestHubReceiverGlnClaim')
                ->assertMountedActionModalSee(self::HELPER_COPY)
                ->assertFormFieldExists('provider')
                ->assertFormFieldExists('reason')
                ->assertFormFieldDoesNotExist('gln')
                ->fillForm([
                    'provider' => 'systech',
                    'reason' => 'Enable hub receiving for this site.',
                ])
                ->callMountedAction()
                ->assertHasNoActionErrors();

            $this->assertTrue(HubReceiverGlnClaimRequest::query()
                ->where('tenant_id', $tenant->getKey())
                ->where('provider', 'systech')
                ->where('gln', $site->gln)
                ->where('reason', 'Enable hub receiving for this site.')
                ->pending()
                ->exists());
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function integration_viewer_cannot_request_claims_from_either_app_page(): void
    {
        $tenant = $this->initializeDemo2Tenant(TenantProfile::DrugWholesaler);

        try {
            $this->actingAs($this->createUser(
                TenantRole::WmsIntegrationSpecialist,
                TenantProfile::DrugWholesaler,
            ));
            $site = $this->createOrganizationSite();

            Livewire::test(IntegrationHealth::class)
                ->assertOk()
                ->assertActionHidden('requestHubReceiverGlnClaim');

            Livewire::test(ViewSite::class, ['record' => $site->getKey()])
                ->assertOk()
                ->assertActionHidden('requestHubReceiverGlnClaim');
        } finally {
            $this->cleanup($tenant);
        }
    }

    private function initializeDemo2Tenant(?TenantProfile $profile = null): Tenant
    {
        $profile ??= TenantProfile::Pharmacy;
        $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);

        if ($tenant === null) {
            $tenant = Tenant::withoutEvents(fn (): Tenant => Tenant::query()->create([
                'id' => self::DEMO2_TENANT_ID,
                'name' => 'Demo Pharmacy',
                'profile' => $profile,
                'status' => 'active',
                'tenancy_db_name' => self::DEMO2_DATABASE,
            ]));
            $tenant->domains()->create(['domain' => self::DEMO2_DOMAIN]);
        } else {
            $tenant->domains()->firstOrCreate(['domain' => self::DEMO2_DOMAIN]);
        }

        $this->originalTenantState = $tenant->only([
            'profile',
            'gln',
            'inbound_environment',
            'hub_providers',
        ]);

        app(EpcisHubPlatformConfig::class)->setProviders('stage', ['systech']);
        $tenant->forceFill([
            'profile' => $profile,
            'gln' => self::COMPANY_GLN,
            'inbound_environment' => 'stage',
            'hub_providers' => ['systech'],
        ])->save();

        if (! self::$demo2TenantReady) {
            $this->artisan('tenants:migrate', [
                '--tenants' => [self::DEMO2_TENANT_ID],
                '--force' => true,
            ])->assertSuccessful();
            self::$demo2TenantReady = true;
        }

        tenancy()->initialize($tenant);
        Filament::setCurrentPanel(Filament::getPanel('app'));

        return $tenant;
    }

    private function createUser(TenantRole $role, TenantProfile $profile): User
    {
        app(TenantRoleSeeder::class)->seedForProfile($profile);
        $user = User::factory()->create();
        $this->userIds[] = (int) $user->getKey();
        $user->assignRole($role->value);

        return $user;
    }

    private function createOrganizationSite(): Site
    {
        $body = '888840'.str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $gln = $body.Gtin::checkDigit($body);

        $site = Site::query()->create([
            'name' => 'Hub claim App UI facility',
            'code' => 'HUB-UI-'.strtoupper(str()->random(6)),
            'gln' => $gln,
            'is_organization_facility' => true,
            'trading_partner_id' => null,
            'is_active' => true,
        ]);
        $this->siteIds[] = (int) $site->getKey();

        return $site;
    }

    private function cleanup(Tenant $tenant): void
    {
        HubReceiverGlnClaimRequest::query()->where('tenant_id', $tenant->getKey())->delete();
        EpcisHubRoute::query()->where('tenant_id', $tenant->getKey())->delete();

        if (tenancy()->initialized) {
            Site::query()->whereIn('id', $this->siteIds)->delete();
            User::query()->whereIn('id', $this->userIds)->delete();
            tenancy()->end();
        }

        if ($this->originalTenantState !== null) {
            $tenant->forceFill($this->originalTenantState)->save();
        }

        $this->siteIds = [];
        $this->userIds = [];
        $this->originalTenantState = null;
    }
}
