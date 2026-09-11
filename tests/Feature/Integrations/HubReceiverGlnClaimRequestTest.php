<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Actions\Integrations\RequestHubReceiverGlnClaim;
use App\Actions\Integrations\ReviewHubReceiverGlnClaimRequest;
use App\Enums\HubReceiverGlnClaimRequestStatus;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Pages\IntegrationHealth;
use App\Models\Admin;
use App\Models\EpcisHubRoute;
use App\Models\HubReceiverGlnClaimRequest;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\EpcisHub\EpcisHubPlatformConfig;
use App\Support\Gs1\Gtin;
use Filament\Facades\Filament;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HubReceiverGlnClaimRequestTest extends TestCase
{
    private const TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const TENANT_DOMAIN = 'demo2.internal.vatengi.com';

    private const TENANT_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private const COMPANY_GLN = '0366159000010';

    private static bool $tenantDatabaseReady = false;

    private Tenant $tenant;

    private ?Admin $admin = null;

    /** @var list<int> */
    private array $siteIds = [];

    /** @var list<int> */
    private array $userIds = [];

    /** @var array{profile: mixed, gln: mixed, inbound_environment: mixed, hub_providers: mixed} */
    private array $originalTenantState;

    protected function setUp(): void
    {
        parent::setUp();

        app(EpcisHubPlatformConfig::class)->setProviders('stage', ['systech']);

        $this->tenant = Tenant::query()->find(self::TENANT_ID)
            ?? Tenant::withoutEvents(fn (): Tenant => Tenant::query()->create([
                'id' => self::TENANT_ID,
                'name' => 'Demo Pharmacy',
                'profile' => TenantProfile::Pharmacy,
                'status' => 'active',
                'tenancy_db_name' => self::TENANT_DATABASE,
            ]));

        $this->tenant->domains()->firstOrCreate(['domain' => self::TENANT_DOMAIN]);
        $this->originalTenantState = $this->tenant->only([
            'profile',
            'gln',
            'inbound_environment',
            'hub_providers',
        ]);
        $this->tenant->forceFill([
            'profile' => TenantProfile::Pharmacy,
            'gln' => self::COMPANY_GLN,
            'inbound_environment' => 'stage',
            'hub_providers' => ['systech'],
        ])->save();
    }

    protected function tearDown(): void
    {
        HubReceiverGlnClaimRequest::query()->where('tenant_id', $this->tenant->getKey())->delete();
        EpcisHubRoute::query()->where('tenant_id', $this->tenant->getKey())->delete();

        if (tenancy()->initialized) {
            Site::query()->whereIn('id', $this->siteIds)->delete();
            User::query()->whereIn('id', $this->userIds)->delete();
            tenancy()->end();
        }

        $this->admin?->delete();
        $this->tenant->forceFill($this->originalTenantState)->save();

        parent::tearDown();
    }

    #[Test]
    public function app_user_creates_a_pending_request_without_an_admin_claim(): void
    {
        $request = $this->requestClaim();

        $this->assertSame(HubReceiverGlnClaimRequestStatus::Pending, $request->status);
        $this->assertFalse(EpcisHubRoute::query()
            ->where('tenant_id', $this->tenant->getKey())
            ->where('provider', 'systech')
            ->where('gln', self::COMPANY_GLN)
            ->where('claimed_via', EpcisHubRoute::CLAIMED_VIA_ADMIN)
            ->exists());
    }

    #[Test]
    public function app_user_has_no_approve_or_review_actions(): void
    {
        $this->initializeTenantDatabase();
        $this->actingAs($this->createOwner());
        Filament::setCurrentPanel(Filament::getPanel('app'));

        Livewire::test(IntegrationHealth::class)
            ->assertOk()
            ->assertActionDoesNotExist('approve')
            ->assertActionDoesNotExist('reject')
            ->assertActionDoesNotExist('review');
    }

    #[Test]
    public function admin_approval_creates_an_admin_claim_and_approves_the_request(): void
    {
        $request = $this->requestClaim();
        $this->admin = Admin::factory()->create();

        app(ReviewHubReceiverGlnClaimRequest::class)->approve($request, $this->admin);

        $this->assertSame(HubReceiverGlnClaimRequestStatus::Approved, $request->fresh()?->status);
        $this->assertDatabaseHas('epcis_hub_routes', [
            'tenant_id' => $this->tenant->getKey(),
            'provider' => 'systech',
            'gln' => self::COMPANY_GLN,
            'claimed_via' => EpcisHubRoute::CLAIMED_VIA_ADMIN,
        ]);
    }

    #[Test]
    public function admin_rejection_rejects_the_request_without_creating_a_route(): void
    {
        $request = $this->requestClaim();
        $this->admin = Admin::factory()->create();

        app(ReviewHubReceiverGlnClaimRequest::class)->reject($request, $this->admin);

        $this->assertSame(HubReceiverGlnClaimRequestStatus::Rejected, $request->fresh()?->status);
        $this->assertFalse(EpcisHubRoute::query()
            ->where('tenant_id', $this->tenant->getKey())
            ->exists());
    }

    #[Test]
    public function saving_a_site_gln_alone_does_not_create_an_admin_claim(): void
    {
        $this->initializeTenantDatabase();
        $body = '888840'.str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $gln = $body.Gtin::checkDigit($body);

        $site = Site::query()->create([
            'name' => 'Unclaimed receiver site',
            'code' => 'UNCLAIMED-'.strtoupper(str()->random(6)),
            'gln' => $gln,
            'is_organization_facility' => true,
            'trading_partner_id' => null,
            'is_active' => true,
        ]);
        $this->siteIds[] = (int) $site->getKey();

        $this->assertFalse(EpcisHubRoute::query()
            ->where('tenant_id', $this->tenant->getKey())
            ->where('gln', $gln)
            ->where('claimed_via', EpcisHubRoute::CLAIMED_VIA_ADMIN)
            ->exists());
        $this->assertFalse(HubReceiverGlnClaimRequest::query()
            ->where('tenant_id', $this->tenant->getKey())
            ->where('gln', $gln)
            ->exists());
    }

    private function requestClaim(): HubReceiverGlnClaimRequest
    {
        return app(RequestHubReceiverGlnClaim::class)->request(
            $this->tenant,
            'systech',
            self::COMPANY_GLN,
            'Enable hub receiving.',
            new User(['name' => 'Tenant Owner', 'email' => 'owner@example.com']),
        );
    }

    private function initializeTenantDatabase(): void
    {
        if (! self::$tenantDatabaseReady) {
            $this->artisan('tenants:migrate', [
                '--tenants' => [self::TENANT_ID],
                '--force' => true,
            ])->assertSuccessful();
            self::$tenantDatabaseReady = true;
        }

        tenancy()->initialize($this->tenant);
    }

    private function createOwner(): User
    {
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
        $user = User::factory()->create();
        $this->userIds[] = (int) $user->getKey();
        $user->assignRole(TenantRole::Owner->value);

        return $user;
    }
}
