<?php

namespace Tests\Feature\Policies;

use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Models\Epcis\EpcisDocument;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Exceptions\ExceptionType;
use App\Models\Fda\FdaProduct;
use App\Models\Fda3911Report;
use App\Models\Site;
use App\Models\SsccLabel;
use App\Models\Tenant;
use App\Models\User;
use App\Policies\ActivityPolicy;
use App\Policies\EpcisDocumentPolicy;
use App\Policies\ExceptionCasePolicy;
use App\Policies\Fda3911ReportPolicy;
use App\Policies\FdaProductPolicy;
use App\Policies\RolePolicy;
use App\Policies\SsccLabelPolicy;
use App\Policies\UserPolicy;
use App\Support\Auth\TenantRoleSeeder;
use Database\Seeders\ExceptionTypeSeeder;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AppResourcePolicyTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $userIds = [];

    /** @var list<int> */
    private array $siteIds = [];

    /** @var list<int> */
    private array $documentIds = [];

    /** @var list<int> */
    private array $exceptionIds = [];

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            foreach ($this->exceptionIds as $id) {
                ExceptionCase::query()->whereKey($id)->delete();
            }
            foreach ($this->documentIds as $id) {
                EpcisDocument::query()->whereKey($id)->delete();
            }
            foreach ($this->userIds as $id) {
                User::query()->whereKey($id)->delete();
            }
            foreach ($this->siteIds as $id) {
                Site::query()->whereKey($id)->delete();
            }
            tenancy()->end();
        }

        parent::tearDown();
    }

    #[Test]
    public function user_policy_allows_owner_and_blocks_self_delete(): void
    {
        $this->initializeDemo2Tenant();

        $owner = User::factory()->create();
        $owner->assignRole(TenantRole::Owner->value);
        $this->userIds[] = (int) $owner->getKey();

        $other = User::factory()->create();
        $this->userIds[] = (int) $other->getKey();

        $policy = new UserPolicy;

        $this->assertTrue($policy->viewAny($owner));
        $this->assertTrue($policy->delete($owner, $other));
        $this->assertFalse($policy->delete($owner, $owner));
    }

    #[Test]
    public function epcis_document_policy_is_site_scoped_for_restricted_users(): void
    {
        $this->initializeDemo2Tenant();

        $siteA = Site::factory()->owned()->create(['code' => 'POL-A-'.fake()->unique()->numerify('###')]);
        $siteB = Site::factory()->owned()->create(['code' => 'POL-B-'.fake()->unique()->numerify('###')]);
        $this->siteIds[] = (int) $siteA->getKey();
        $this->siteIds[] = (int) $siteB->getKey();

        $restricted = User::factory()->create();
        $restricted->assignRole(TenantRole::ReceivingTechnician->value);
        $restricted->syncSites([(int) $siteA->getKey()]);
        $this->userIds[] = (int) $restricted->getKey();

        $docAllowed = EpcisDocument::query()->create([
            'document_uuid' => (string) str()->uuid(),
            'direction' => 'inbound',
            'status' => 'validated',
            'format' => 'xml',
            'original_filename' => 'policy-a.xml',
            'ship_to_site_id' => $siteA->getKey(),
            'creation_date' => now(),
            'received_at' => now(),
        ]);
        $docDenied = EpcisDocument::query()->create([
            'document_uuid' => (string) str()->uuid(),
            'direction' => 'inbound',
            'status' => 'validated',
            'format' => 'xml',
            'original_filename' => 'policy-b.xml',
            'ship_to_site_id' => $siteB->getKey(),
            'creation_date' => now(),
            'received_at' => now(),
        ]);
        $docNull = EpcisDocument::query()->create([
            'document_uuid' => (string) str()->uuid(),
            'direction' => 'inbound',
            'status' => 'validated',
            'format' => 'xml',
            'original_filename' => 'policy-null.xml',
            'ship_to_site_id' => null,
            'creation_date' => now(),
            'received_at' => now(),
        ]);
        $this->documentIds = [
            (int) $docAllowed->getKey(),
            (int) $docDenied->getKey(),
            (int) $docNull->getKey(),
        ];

        $policy = new EpcisDocumentPolicy;
        $this->assertTrue($policy->viewAny($restricted));
        $this->assertTrue($policy->view($restricted, $docAllowed));
        $this->assertFalse($policy->view($restricted, $docDenied));
        $this->assertFalse($policy->view($restricted, $docNull));
        $this->assertFalse($policy->delete($restricted, $docAllowed));
    }

    #[Test]
    public function epcis_document_policy_allows_access_all_to_view_partner_ship_to(): void
    {
        $this->initializeDemo2Tenant();

        $partnerSite = Site::factory()->create([
            'code' => 'POL-PART-'.fake()->unique()->numerify('###'),
            'trading_partner_id' => \App\Models\TradingPartner::factory(),
        ]);
        $this->siteIds[] = (int) $partnerSite->getKey();

        $owner = User::factory()->create();
        $owner->assignRole(TenantRole::Owner->value);
        $this->userIds[] = (int) $owner->getKey();

        $doc = EpcisDocument::query()->create([
            'document_uuid' => (string) str()->uuid(),
            'direction' => 'inbound',
            'status' => 'validated',
            'format' => 'xml',
            'original_filename' => 'partner-ship-to.xml',
            'ship_to_site_id' => $partnerSite->getKey(),
            'creation_date' => now(),
            'received_at' => now(),
        ]);
        $this->documentIds[] = (int) $doc->getKey();

        $policy = new EpcisDocumentPolicy;
        $this->assertTrue($policy->viewAny($owner));
        $this->assertTrue($policy->view($owner, $doc));
        $this->assertTrue(\App\Support\Auth\SiteAccess::canAccessShipToSite($owner, (int) $partnerSite->getKey()));
    }

    #[Test]
    public function exception_case_policy_is_site_scoped_for_restricted_users(): void
    {
        $this->initializeDemo2Tenant();

        ExceptionTypeSeeder::ensure('DESTINATION_LOCATION_MISMATCH');
        $typeId = ExceptionType::query()->where('code', 'DESTINATION_LOCATION_MISMATCH')->value('id');

        $siteA = Site::factory()->owned()->create(['code' => 'EX-A-'.fake()->unique()->numerify('###')]);
        $siteB = Site::factory()->owned()->create(['code' => 'EX-B-'.fake()->unique()->numerify('###')]);
        $this->siteIds[] = (int) $siteA->getKey();
        $this->siteIds[] = (int) $siteB->getKey();

        $restricted = User::factory()->create();
        $restricted->assignRole(TenantRole::ReceivingTechnician->value);
        $restricted->syncSites([(int) $siteA->getKey()]);
        $this->userIds[] = (int) $restricted->getKey();

        $allowed = ExceptionCase::query()->create([
            'exception_type_id' => $typeId,
            'title' => 'Allowed case',
            'severity' => 'medium',
            'status' => 'new',
            'site_id' => $siteA->getKey(),
        ]);
        $denied = ExceptionCase::query()->create([
            'exception_type_id' => $typeId,
            'title' => 'Denied case',
            'severity' => 'medium',
            'status' => 'new',
            'site_id' => $siteB->getKey(),
        ]);
        $this->exceptionIds = [(int) $allowed->getKey(), (int) $denied->getKey()];

        $policy = new ExceptionCasePolicy;
        $this->assertTrue($policy->viewAny($restricted));
        $this->assertTrue($policy->view($restricted, $allowed));
        $this->assertFalse($policy->view($restricted, $denied));
    }

    #[Test]
    public function role_and_fda3911_policies_mirror_can_access_gates(): void
    {
        $this->initializeDemo2Tenant();

        $owner = User::factory()->create();
        $owner->assignRole(TenantRole::Owner->value);
        $this->userIds[] = (int) $owner->getKey();

        $rolePolicy = new RolePolicy;
        $this->assertTrue($rolePolicy->viewAny($owner));
        $this->assertFalse($rolePolicy->create($owner));
        $this->assertTrue($rolePolicy->update($owner, new Role));
        $this->assertFalse($rolePolicy->delete($owner, new Role));

        $fda3911Policy = new Fda3911ReportPolicy;
        $this->assertTrue($fda3911Policy->viewAny($owner));
        $this->assertTrue($fda3911Policy->create($owner));
        $this->assertFalse($fda3911Policy->delete($owner, new Fda3911Report));
    }

    #[Test]
    public function sscc_label_policy_allows_create_for_generate_action(): void
    {
        $this->initializeDemo2Tenant();

        $owner = User::factory()->create();
        $owner->assignRole(TenantRole::Owner->value);
        $this->userIds[] = (int) $owner->getKey();

        $policy = new SsccLabelPolicy;
        $this->assertTrue($policy->viewAny($owner));
        $this->assertTrue($policy->create($owner));
        $this->assertFalse($policy->update($owner, new SsccLabel));
    }

    #[Test]
    public function fda_product_policy_is_read_only(): void
    {
        $this->initializeDemo2Tenant();

        $owner = User::factory()->create();
        $owner->assignRole(TenantRole::Owner->value);
        $this->userIds[] = (int) $owner->getKey();

        $policy = new FdaProductPolicy;
        $this->assertTrue($policy->viewAny($owner));
        $this->assertTrue($policy->view($owner, new FdaProduct));
        $this->assertFalse($policy->create($owner));
        $this->assertFalse($policy->update($owner, new FdaProduct));
        $this->assertFalse($policy->delete($owner, new FdaProduct));
    }

    #[Test]
    public function gate_registers_policies_for_spatie_role_and_activity(): void
    {
        $this->assertInstanceOf(RolePolicy::class, Gate::getPolicyFor(Role::class));
        $this->assertInstanceOf(ActivityPolicy::class, Gate::getPolicyFor(Activity::class));
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
        }

        if (! self::$demo2TenantReady) {
            $this->artisan('tenants:migrate', [
                '--tenants' => [self::DEMO2_TENANT_ID],
                '--force' => true,
            ])->assertSuccessful();
            self::$demo2TenantReady = true;
        }

        tenancy()->initialize($tenant);
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);

        return tenant() instanceof Tenant ? tenant() : $tenant;
    }
}
