<?php

namespace Tests\Feature\MasterData;

use App\Actions\MasterData\CreateHqSiteForTradingPartner;
use App\Enums\FacilityType;
use App\Enums\PartnerType;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Resources\Sites\Pages\ListSites;
use App\Filament\App\Resources\Sites\Tables\SitesTable;
use App\Models\AtpLicense;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Models\User;
use App\Policies\SitePolicy;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\Gs1\Gtin;
use App\Support\MasterData\SiteReferences;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Support\Exceptions\Cancel;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Sites carry the same DSCSA weight as the partners that own them: a location named by
 * an EPCIS document or a session must survive for the retention window, while an
 * unreferenced one goes with its partner so the GLN is free to be imported again.
 *
 * GLNs are prefixed 094221 so rows stay traceable in the shared demo2 tenant.
 */
class SiteDeleteGuardTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private const GLN_PREFIX = '094221';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $partnerIds = [];

    /** @var list<int> */
    private array $siteIds = [];

    /** @var list<int> */
    private array $userIds = [];

    #[Test]
    public function unreferenced_site_is_deleted_with_its_atp_licenses(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $site = $this->createPartnerSite();
            $license = $this->createLicenseFor($site);

            $this->assertFalse(SiteReferences::isReferenced($site));
            $this->assertNull(SiteReferences::summary($site));

            $site->delete();

            $this->assertNull(Site::query()->find($site->getKey()));
            $this->assertNull(
                AtpLicense::query()->find($license->getKey()),
                'ATP licenses authorize a location and have no meaning without it.',
            );
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function referenced_site_delete_is_blocked_and_names_the_reference(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $site = $this->createPartnerSite();
            $this->createOutboundSessionFor($site);

            $this->assertTrue(SiteReferences::isReferenced($site));
            $this->assertSame('1 outbound shipping session', SiteReferences::summary($site));

            try {
                $site->delete();
                $this->fail('Deleting a referenced site must throw.');
            } catch (DomainException $exception) {
                $this->assertStringContainsString('1 outbound shipping session', $exception->getMessage());
                $this->assertStringContainsString('Deactivate', $exception->getMessage());
            }

            $this->assertNotNull(Site::query()->find($site->getKey()));
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function deleting_a_partner_cascades_its_sites_and_frees_the_gln_for_a_re_import(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $gln = $this->uniqueGln('10');
            $partner = $this->createPartner(['gln' => $gln]);

            $hqSite = app(CreateHqSiteForTradingPartner::class)->handle($partner);
            $this->assertNotNull($hqSite);
            $this->siteIds[] = (int) $hqSite->getKey();

            $extraSite = $this->createPartnerSite(['trading_partner_id' => $partner->getKey()]);

            $partner->delete();

            $this->assertNull(TradingPartner::query()->find($partner->getKey()));
            $this->assertNull(Site::query()->find($hqSite->getKey()));
            $this->assertNull(Site::query()->find($extraSite->getKey()));

            $reimported = $this->createPartner(['gln' => $gln]);
            $reimportedHq = app(CreateHqSiteForTradingPartner::class)->handle($reimported);

            $this->assertNotNull(
                $reimportedHq,
                'The GLN must be free again, otherwise the partner comes back without a location.',
            );
            $this->siteIds[] = (int) $reimportedHq->getKey();
            $this->assertSame($gln, $reimportedHq->gln);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function a_referenced_partner_site_blocks_the_partner_delete(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $partner = $this->createPartner();
            $keptSite = $this->createPartnerSite(['trading_partner_id' => $partner->getKey()]);
            $referencedSite = $this->createPartnerSite(['trading_partner_id' => $partner->getKey()]);
            $this->createOutboundSessionFor($referencedSite);

            try {
                $partner->delete();
                $this->fail('A partner whose site is still referenced must not be deleted.');
            } catch (DomainException $exception) {
                $this->assertStringContainsString('1 outbound shipping session', $exception->getMessage());
            }

            $this->assertNotNull(TradingPartner::query()->find($partner->getKey()));
            $this->assertNotNull(
                Site::query()->find($keptSite->getKey()),
                'A blocked cascade must not delete the sites it already walked past.',
            );
            $this->assertNotNull(Site::query()->find($referencedSite->getKey()));
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function delete_action_cancels_with_a_notification_when_the_site_is_referenced(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $site = $this->createPartnerSite();
            $deleteAction = $this->recordAction('delete', $site);
            $this->assertInstanceOf(DeleteAction::class, $deleteAction);

            $deleteAction->callBefore();

            $this->createOutboundSessionFor($site);

            $deleteAction->record($site->fresh());

            try {
                $deleteAction->callBefore();
                $this->fail('The delete action must cancel when the site is still referenced.');
            } catch (Cancel) {
                $notifications = collect(session()->get('filament.notifications', []));

                $this->assertTrue(
                    $notifications->contains(fn (array $notification): bool => ($notification['title'] ?? null) === 'Site cannot be deleted'),
                    'The blocked delete must explain itself with a notification.',
                );
            }

            $this->assertNotNull(Site::query()->find($site->getKey()));
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function bulk_delete_authorizes_each_record_so_referenced_sites_are_skipped(): void
    {
        $this->initializeDemo2Tenant();

        try {
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            $this->actingAs($this->createUserWithRole(TenantRole::Owner));

            $unreferenced = $this->createPartnerSite();
            $referenced = $this->createPartnerSite();
            $this->createOutboundSessionFor($referenced);

            $table = SitesTable::configure(Table::make(new ListSites));

            $bulkActions = collect($table->getToolbarActions())
                ->flatMap(fn (Action|ActionGroup $action): array => $action instanceof ActionGroup ? $action->getActions() : [$action])
                ->keyBy(fn (Action $action): string => $action->getName());

            $delete = $bulkActions->get('delete');
            $this->assertInstanceOf(DeleteBulkAction::class, $delete);

            $this->assertTrue($delete->getIndividualRecordAuthorizationResponse($unreferenced)->allowed());
            $this->assertTrue($delete->getIndividualRecordAuthorizationResponse($referenced->fresh())->denied());
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function delete_policy_requires_a_master_data_role_and_zero_references(): void
    {
        $this->initializeDemo2Tenant();

        try {
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);

            $owner = $this->createUserWithRole(TenantRole::Owner);
            $technician = $this->createUserWithRole(TenantRole::ReceivingTechnician);

            $site = $this->createPartnerSite();
            $policy = new SitePolicy;

            $this->assertTrue($policy->viewAny($technician));
            $this->assertTrue($policy->view($technician, $site));
            $this->assertFalse($policy->create($technician));
            $this->assertFalse($policy->update($technician, $site));
            $this->assertFalse($policy->delete($technician, $site));

            $this->assertTrue($policy->create($owner));
            $this->assertTrue($policy->update($owner, $site));
            $this->assertTrue($policy->delete($owner, $site));

            $this->createOutboundSessionFor($site);

            $this->assertTrue(
                $policy->deleteAny($owner),
                'Bulk delete stays available; individual records are filtered by the delete ability.',
            );
            $this->assertFalse(
                $policy->delete($owner, $site->fresh()),
                'A referenced site must not be deletable, even by the tenant owner.',
            );
        } finally {
            $this->cleanup();
        }
    }

    private function recordAction(string $name, Site $record): Action
    {
        $table = SitesTable::configure(Table::make(new ListSites));

        $recordActions = $table->getRecordActions();
        $this->assertCount(1, $recordActions);
        $this->assertInstanceOf(ActionGroup::class, $recordActions[0]);

        $actions = collect($recordActions[0]->getActions())
            ->each(fn (Action $action) => $action->record($record));

        $action = $actions->first(fn (Action $action): bool => $action->getName() === $name);

        $this->assertNotNull($action, "The sites table is missing the [{$name}] action.");

        return $action;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createPartner(array $attributes = []): TradingPartner
    {
        $partner = TradingPartner::factory()->create(array_merge([
            'name' => 'Site Guard Partner '.uniqid(),
            'partner_type' => PartnerType::Wholesaler,
        ], $attributes));

        $this->partnerIds[] = (int) $partner->getKey();

        return $partner;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createPartnerSite(array $attributes = []): Site
    {
        if (! array_key_exists('trading_partner_id', $attributes)) {
            $attributes['trading_partner_id'] = $this->createPartner()->getKey();
        }

        $site = Site::factory()->create(array_merge([
            'name' => 'Site Guard Site '.uniqid(),
            'gln' => $this->uniqueGln('90'),
            'is_organization_facility' => false,
        ], $attributes));

        $this->siteIds[] = (int) $site->getKey();

        return $site;
    }

    private function createLicenseFor(Site $site): AtpLicense
    {
        return AtpLicense::query()->create([
            'site_id' => $site->getKey(),
            'facility_type' => FacilityType::Wdd,
            'license_number' => 'SG-'.uniqid(),
            'license_state' => 'TX',
            'license_expiration_date' => now()->addYear()->toDateString(),
            'reporting_year' => (int) now()->year,
        ]);
    }

    private function createOutboundSessionFor(Site $site): int
    {
        return (int) DB::table('outbound_shipping_sessions')->insertGetId([
            'site_id' => $site->getKey(),
            'status' => 'open',
            'opened_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createUserWithRole(TenantRole $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);

        $this->userIds[] = (int) $user->getKey();

        return $user;
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

        return $tenant;
    }

    /**
     * Raw deletes on purpose: the cleanup must not run the guard it is cleaning up after.
     */
    private function cleanup(): void
    {
        if (tenancy()->initialized) {
            $siteIds = Site::query()
                ->where('gln', 'like', self::GLN_PREFIX.'%')
                ->pluck('id')
                ->merge($this->siteIds)
                ->unique()
                ->all();

            if ($siteIds !== []) {
                DB::table('outbound_shipping_sessions')->whereIn('site_id', $siteIds)->delete();
                DB::table('atp_licenses')->whereIn('site_id', $siteIds)->delete();
                DB::table('sscc_number_ranges')->whereIn('site_id', $siteIds)->delete();
                DB::table('sites')->whereIn('id', $siteIds)->delete();
            }

            if ($this->partnerIds !== []) {
                DB::table('sites')->whereIn('trading_partner_id', $this->partnerIds)->delete();
                DB::table('sscc_number_ranges')->whereIn('trading_partner_id', $this->partnerIds)->delete();
                DB::table('trading_partners')->whereIn('id', $this->partnerIds)->delete();
            }

            if ($this->userIds !== []) {
                DB::table('model_has_roles')
                    ->where('model_type', User::class)
                    ->whereIn('model_id', $this->userIds)
                    ->delete();
                User::query()->whereIn('id', $this->userIds)->delete();
            }

            tenancy()->end();
        }

        $this->siteIds = [];
        $this->partnerIds = [];
        $this->userIds = [];
    }
}
