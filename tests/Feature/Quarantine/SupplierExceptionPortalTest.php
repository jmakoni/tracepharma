<?php

namespace Tests\Feature\Quarantine;

use App\Enums\ExceptionSeverity;
use App\Enums\ExceptionStatus;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Resources\Exceptions\Pages\ViewException;
use App\Models\Epcis\Epc;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Exceptions\ExceptionType;
use App\Models\Quarantine\QuarantineHold;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Models\User;
use App\Services\Exceptions\ExceptionService;
use App\Services\Quarantine\QuarantineService;
use App\Services\Quarantine\SupplierPortalService;
use App\Support\Auth\Permissions;
use App\Support\Auth\TenantRoleSeeder;
use Database\Seeders\ExceptionCaseSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SupplierExceptionPortalTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $epcIds = [];

    /** @var list<int> */
    private array $caseIds = [];

    /** @var list<int> */
    private array $partnerIds = [];

    /** @var list<int> */
    private array $userIds = [];

    #[Test]
    public function copy_supplier_portal_link_is_hidden_from_personas_without_the_ability(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);

            $technician = User::factory()->create();
            $technician->assignRole(TenantRole::ReceivingTechnician->value);
            $technician->givePermissionTo(Permissions::SitesAccessAll);
            $this->userIds[] = (int) $technician->getKey();

            $partner = $this->createPartner('Unauthorized Portal Partner');
            $case = $this->createCaseForPartner($partner, 'Portal auth gate');

            Filament::setCurrentPanel(Filament::getPanel('app'));
            $this->actingAs($technician);

            Livewire::test(ViewException::class, ['record' => $case->getKey()])
                ->assertActionHidden('copySupplierPortalLink')
                ->assertActionHidden('copySupplierLink');
        } finally {
            if (! tenancy()->initialized) {
                tenancy()->initialize($tenant);
            }
            $this->cleanup();
        }
    }

    #[Test]
    public function partner_portal_lists_only_explicitly_shared_open_cases(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $partner = $this->createPartner('Shared Portal Partner');
            $shared = $this->createCaseForPartner($partner, 'Shared with supplier');
            $shared->forceFill([
                'share_uuid' => (string) str()->uuid(),
                'share_expires_at' => now()->addDays(30),
            ])->save();
            $this->createCaseForPartner($partner, 'Internal only');

            URL::forceRootUrl('http://'.self::DEMO2_DOMAIN);
            $url = app(SupplierPortalService::class)->signedPartnerExceptionsUrl($partner);

            tenancy()->end();

            $response = $this->get($url);

            $response->assertOk();
            $response->assertSee('Shared with supplier', false);
            $response->assertSee((string) $shared->id, false);
            $response->assertDontSee('Internal only', false);
        } finally {
            if (! tenancy()->initialized) {
                tenancy()->initialize($tenant);
            }
            $this->cleanup();
        }
    }

    #[Test]
    public function partner_portal_lists_only_that_partners_open_cases(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $partnerA = $this->createPartner('Portal Partner A');
            $partnerB = $this->createPartner('Portal Partner B');

            $openA = $this->createCaseForPartner($partnerA, 'Open for A');
            $openA->forceFill([
                'share_uuid' => (string) str()->uuid(),
                'share_expires_at' => now()->addDays(30),
            ])->save();
            $openB = $this->createCaseForPartner($partnerB, 'Open for B');
            $resolvedA = $this->createCaseForPartner($partnerA, 'Resolved for A');
            $resolvedA->forceFill(['status' => ExceptionStatus::Resolved->value])->save();

            URL::forceRootUrl('http://'.self::DEMO2_DOMAIN);
            $url = app(SupplierPortalService::class)->signedPartnerExceptionsUrl($partnerA);

            tenancy()->end();

            $response = $this->get($url);

            $response->assertOk();
            $response->assertSee('Portal Partner A', false);
            $response->assertSee('Open for A', false);
            $response->assertSee((string) $openA->id, false);
            $response->assertDontSee('Open for B', false);
            $response->assertDontSee('Resolved for A', false);
            $response->assertDontSee('Open for B', false);
        } finally {
            if (! tenancy()->initialized) {
                tenancy()->initialize($tenant);
            }
            $this->cleanup();
        }
    }

    #[Test]
    public function partner_portal_rejects_invalid_signature(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $partner = $this->createPartner('Unsigned Partner');
            app(SupplierPortalService::class)->ensurePartnerPortalLink($partner);

            URL::forceRootUrl('http://'.self::DEMO2_DOMAIN);
            $url = 'http://'.self::DEMO2_DOMAIN.'/supplier-exceptions/'.$partner->portal_share_uuid;

            tenancy()->end();

            $this->get($url)->assertForbidden();
        } finally {
            if (! tenancy()->initialized) {
                tenancy()->initialize($tenant);
            }
            $this->cleanup();
        }
    }

    #[Test]
    public function partner_portal_link_expires(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            config(['tracepharma.supplier_portal.link_ttl_days' => 7]);

            $partner = $this->createPartner('Expiring Partner');
            $this->createCaseForPartner($partner, 'Open while the link lives');

            URL::forceRootUrl('http://'.self::DEMO2_DOMAIN);
            $url = app(SupplierPortalService::class)->signedPartnerExceptionsUrl($partner);

            tenancy()->end();

            $this->get($url)->assertOk();

            $this->travelTo(now()->addDays(8));
            $this->get($url)->assertForbidden();
        } finally {
            $this->travelBack();
            if (! tenancy()->initialized) {
                tenancy()->initialize($tenant);
            }
            $this->cleanup();
        }
    }

    #[Test]
    public function partner_portal_rejects_a_deactivated_partner(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $partner = $this->createPartner('Deactivated Partner');
            $this->createCaseForPartner($partner, 'Open for a deactivated partner');

            URL::forceRootUrl('http://'.self::DEMO2_DOMAIN);
            $url = app(SupplierPortalService::class)->signedPartnerExceptionsUrl($partner);

            $partner->update(['is_active' => false]);

            tenancy()->end();

            $this->get($url)->assertForbidden();
        } finally {
            if (! tenancy()->initialized) {
                tenancy()->initialize($tenant);
            }
            $this->cleanup();
        }
    }

    #[Test]
    public function an_inactive_partner_cannot_be_issued_a_portal_link(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $partner = $this->createPartner('Never Shared Partner');
            $partner->update(['is_active' => false]);

            $this->expectException(\RuntimeException::class);

            app(SupplierPortalService::class)->signedPartnerExceptionsUrl($partner->refresh());
        } finally {
            if (! tenancy()->initialized) {
                tenancy()->initialize($tenant);
            }
            $this->cleanup();
        }
    }

    #[Test]
    public function revoking_the_portal_link_closes_outstanding_urls(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $partner = $this->createPartner('Revoked Partner');
            $this->createCaseForPartner($partner, 'Open before revoke');

            URL::forceRootUrl('http://'.self::DEMO2_DOMAIN);
            $portal = app(SupplierPortalService::class);
            $url = $portal->signedPartnerExceptionsUrl($partner);

            tenancy()->end();
            $this->get($url)->assertOk();

            tenancy()->initialize($tenant);
            $portal->revokePartnerPortalLink($partner->refresh());
            $this->assertNull($partner->refresh()->portal_share_uuid);

            tenancy()->end();
            $this->get($url)->assertForbidden();
        } finally {
            if (! tenancy()->initialized) {
                tenancy()->initialize($tenant);
            }
            $this->cleanup();
        }
    }

    #[Test]
    public function rotating_the_portal_link_issues_a_new_uuid_and_closes_the_old_url(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $partner = $this->createPartner('Rotated Partner');
            $this->createCaseForPartner($partner, 'Open before rotate');

            URL::forceRootUrl('http://'.self::DEMO2_DOMAIN);
            $portal = app(SupplierPortalService::class);
            $url = $portal->signedPartnerExceptionsUrl($partner);
            $original = $partner->refresh()->portal_share_uuid;

            $portal->rotatePartnerPortalLink($partner);
            $rotated = $partner->refresh()->portal_share_uuid;

            $this->assertNotNull($rotated);
            $this->assertNotSame($original, $rotated);

            $freshUrl = $portal->signedPartnerExceptionsUrl($partner->refresh());

            tenancy()->end();

            $this->get($url)->assertForbidden();
            $this->get($freshUrl)->assertOk();
        } finally {
            if (! tenancy()->initialized) {
                tenancy()->initialize($tenant);
            }
            $this->cleanup();
        }
    }

    #[Test]
    public function revoked_partner_forbids_case_signed_url(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $partner = $this->createPartner('Revoked Case Link Partner');
            $case = $this->createCaseForPartner($partner, 'Open before case-link revoke');

            URL::forceRootUrl('http://'.self::DEMO2_DOMAIN);
            app(SupplierPortalService::class)->ensurePartnerPortalLink($partner);
            $url = app(QuarantineService::class)->signedSupplierUrl($case->fresh());

            tenancy()->end();
            $this->get($url)->assertOk();

            tenancy()->initialize($tenant);
            app(SupplierPortalService::class)->revokePartnerPortalLink($partner->refresh());
            $this->assertNull($partner->refresh()->portal_share_uuid);

            tenancy()->end();
            $this->get($url)->assertForbidden();
        } finally {
            if (! tenancy()->initialized) {
                tenancy()->initialize($tenant);
            }
            $this->cleanup();
        }
    }

    #[Test]
    public function inactive_partner_forbids_case_signed_url(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $partner = $this->createPartner('Inactive Case Link Partner');
            $case = $this->createCaseForPartner($partner, 'Open for inactive partner case link');

            URL::forceRootUrl('http://'.self::DEMO2_DOMAIN);
            app(SupplierPortalService::class)->ensurePartnerPortalLink($partner);
            $url = app(QuarantineService::class)->signedSupplierUrl($case->fresh());

            $partner->update(['is_active' => false]);

            tenancy()->end();
            $this->get($url)->assertForbidden();
        } finally {
            if (! tenancy()->initialized) {
                tenancy()->initialize($tenant);
            }
            $this->cleanup();
        }
    }

    #[Test]
    public function terminal_case_supplier_url_returns_forbidden(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $epc = $this->createEpc(substr((string) str()->uuid(), 0, 8));
            $case = app(QuarantineService::class)->quarantineFromFindRecall(
                epcIds: [$epc->id],
                reason: 'Terminal gate test',
            );
            $this->caseIds[] = (int) $case->getKey();

            URL::forceRootUrl('http://'.self::DEMO2_DOMAIN);
            $url = app(QuarantineService::class)->signedSupplierUrl($case->fresh());

            $case->forceFill(['status' => ExceptionStatus::Resolved->value])->save();

            tenancy()->end();

            $this->get($url)->assertForbidden();
        } finally {
            if (! tenancy()->initialized) {
                tenancy()->initialize($tenant);
            }
            $this->cleanup();
        }
    }

    private function createPartner(string $name): TradingPartner
    {
        $partner = TradingPartner::query()->create([
            'name' => $name.' '.substr((string) str()->uuid(), 0, 8),
            'partner_type' => 'manufacturer',
            'is_active' => true,
        ]);
        $this->partnerIds[] = (int) $partner->getKey();

        return $partner;
    }

    private function createCaseForPartner(TradingPartner $partner, string $title): ExceptionCase
    {
        $type = ExceptionType::query()
            ->where('code', 'SUSPECT_PRODUCT')
            ->where('is_active', true)
            ->firstOrFail();

        $case = app(ExceptionService::class)->create([
            'exception_type_id' => $type->getKey(),
            'trading_partner_id' => $partner->getKey(),
            'title' => $title,
            'description' => $title,
            'severity' => ExceptionSeverity::High->value,
            'status' => ExceptionStatus::New->value,
        ]);
        $this->caseIds[] = (int) $case->getKey();

        return $case;
    }

    private function createEpc(string $suffix): Epc
    {
        $epc = Epc::query()->create([
            'epc_type' => 'sgtin',
            'epc_uri' => "urn:epc:id:sgtin:030116.0200116.q{$suffix}",
            'gtin14' => '00301162001162',
            'serial_number' => "q{$suffix}",
            'company_prefix' => '030116',
            'first_seen_at' => now(),
        ]);
        $this->epcIds[] = (int) $epc->id;

        return $epc;
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

        $this->seed(ExceptionCaseSeeder::class);

        return $tenant;
    }

    private function cleanup(): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        foreach ($this->caseIds as $caseId) {
            $case = ExceptionCase::query()->find($caseId);
            if ($case === null) {
                continue;
            }

            $case->activities()->delete();
            QuarantineHold::query()->where('exception_id', $caseId)->delete();
            $case->epcs()->detach();
            $case->delete();
        }
        $this->caseIds = [];

        foreach ($this->epcIds as $id) {
            QuarantineHold::query()->where('epc_id', $id)->delete();
            Epc::query()->whereKey($id)->delete();
        }
        $this->epcIds = [];

        foreach ($this->partnerIds as $id) {
            TradingPartner::query()->whereKey($id)->update(['portal_share_uuid' => null]);
            TradingPartner::query()->whereKey($id)->delete();
        }
        $this->partnerIds = [];

        if ($this->userIds !== []) {
            DB::table('model_has_roles')
                ->where('model_type', User::class)
                ->whereIn('model_id', $this->userIds)
                ->delete();
            User::query()->whereIn('id', $this->userIds)->delete();
            $this->userIds = [];
        }

        tenancy()->end();
    }
}
