<?php

namespace Tests\Feature\MasterData;

use App\Actions\MasterData\RederiveOrganizationSglns;
use App\Enums\PartnerType;
use App\Enums\TenantProfile;
use App\Filament\Admin\Resources\Tenants\Pages\EditTenant;
use App\Models\Admin;
use App\Models\LocationDevice;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Support\Gs1\Gtin;
use App\Support\Gs1\SglnResolution;
use App\Support\TenantSettings;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The GS1 Company Prefix is where our own GLNs split, so it is also the only thing that
 * can say which SGLN identifies one of our docks. Change the prefix and every SGLN
 * derived from the old one names a location that is no longer on our records — while
 * still round-tripping to the same 13 digits, which is why nothing downstream notices.
 *
 * These tests pin what the change is allowed to touch: our own facilities and the
 * devices on them, and nothing a partner stated.
 *
 * GLNs are prefixed 094224 so rows stay traceable in the shared demo2 tenant.
 */
class RederiveOrganizationSglnsTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private const GLN_PREFIX = '094224';

    /** Our prefix before the change: GLNs marked 1 sit under it. */
    private const NARROW_PREFIX = '0942241';

    /** Our prefix after the change: one digit shorter, so every marker sits under it. */
    private const WIDE_PREFIX = '094224';

    private static bool $demo2TenantReady = false;

    private ?string $priorGln = null;

    private ?string $priorCompanyPrefix = null;

    /** @var list<int> */
    private array $adminIds = [];

    #[Test]
    public function saving_a_new_company_prefix_re_authors_our_facilities_and_their_devices(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $orgGln = $this->uniqueGln('1');
            $this->useCompanyPrefix($tenant, self::NARROW_PREFIX, $orgGln);

            $site = $this->createOrganizationSite($this->uniqueGln('1'));
            $device = $this->createLocationDevice($site, $this->uniqueGln('1'));

            $this->assertSame($this->splitOn(self::NARROW_PREFIX, $site->gln), $site->fresh()->sgln);
            $this->assertSame($this->splitOn(self::NARROW_PREFIX, $device->gln), $device->fresh()->sgln);

            TenantSettings::forTenant($tenant)->saveOrganization([
                'gln' => $orgGln,
                'company_prefix' => self::WIDE_PREFIX,
            ]);

            $this->assertSame(
                $this->splitOn(self::WIDE_PREFIX, $site->gln),
                $site->fresh()->sgln,
                'Our own dock splits where our company prefix now says it splits.',
            );
            $this->assertSame(
                $this->splitOn(self::WIDE_PREFIX, $device->gln),
                $device->fresh()->sgln,
                'A dock door inside that facility moves with it.',
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function it_keeps_the_sub_location_a_recorded_sgln_named(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->useCompanyPrefix($tenant, self::NARROW_PREFIX, $this->uniqueGln('1'));

            $gln = $this->uniqueGln('1');
            $site = $this->createOrganizationSite($gln, $this->splitOn(self::NARROW_PREFIX, $gln, '4'));

            $this->assertSame($this->splitOn(self::NARROW_PREFIX, $gln, '4'), $site->fresh()->sgln);

            app(RederiveOrganizationSglns::class)->handle(self::WIDE_PREFIX);

            $this->assertSame(
                $this->splitOn(self::WIDE_PREFIX, $gln, '4'),
                $site->fresh()->sgln,
                'The prefix moves where the digits split, not which door they lead to.',
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function a_partner_location_keeps_the_sgln_the_partner_stated(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->useCompanyPrefix($tenant, self::NARROW_PREFIX, $this->uniqueGln('1'));

            $partner = TradingPartner::query()->create([
                'name' => 'Rederive Sgln Partner 094224',
                'gln' => $this->uniqueGln('3'),
                'partner_type' => PartnerType::Pharmacy,
                'is_active' => true,
            ]);

            // Their GLN happens to sit under our wider prefix. It is still their location,
            // and the split they publish is the one that identifies it.
            $partnerGln = $this->uniqueGln('3');
            $theirSgln = $this->splitOn('0942243', $partnerGln);

            $partnerSite = Site::query()->create([
                'name' => 'Rederive Sgln Partner Dock 094224',
                'gln' => $partnerGln,
                'sgln' => $theirSgln,
                'trading_partner_id' => $partner->getKey(),
                'is_organization_facility' => false,
                'is_active' => true,
            ]);

            $this->assertSame($theirSgln, $partnerSite->fresh()->sgln);

            app(RederiveOrganizationSglns::class)->handle(self::WIDE_PREFIX);

            $this->assertSame(
                $theirSgln,
                $partnerSite->fresh()->sgln,
                'Our company prefix says nothing about where a partner GLN splits.',
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function a_facility_outside_the_new_prefix_keeps_an_sgln_that_still_encodes_it(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->useCompanyPrefix($tenant, self::WIDE_PREFIX, $this->uniqueGln('1'));

            // Organizations hold more than one GS1 prefix. A facility issued under another
            // one is not re-authored — but its recorded SGLN is still its identity.
            $gln = $this->uniqueGln('3');
            $recorded = $this->splitOn('0942243', $gln);
            $site = $this->createOrganizationSite($gln, $recorded);

            $this->assertSame($recorded, $site->fresh()->sgln);

            app(RederiveOrganizationSglns::class)->handle(self::NARROW_PREFIX);

            $this->assertSame($recorded, $site->fresh()->sgln);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function it_clears_a_stored_sgln_it_cannot_stand_behind(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->useCompanyPrefix($tenant, self::NARROW_PREFIX, $this->uniqueGln('1'));

            $gln = $this->uniqueGln('3');
            $site = $this->createOrganizationSite($gln);

            // What the old generated column produced: two segments where GS1 Pure Identity
            // has three. It describes no location, and this GLN is not ours to re-split.
            DB::table('sites')
                ->where('id', $site->getKey())
                ->update(['sgln' => 'urn:epc:id:sgln:'.substr($gln, 0, 12).'.0']);

            app(RederiveOrganizationSglns::class)->handle(self::NARROW_PREFIX);

            $this->assertSame(
                SglnResolution::fromPrefixLength($gln, self::NARROW_PREFIX),
                $site->fresh()->sgln,
                'An organization facility with no stand-behind URN is encoded on the org prefix length.',
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function the_ops_command_reports_a_dry_run_before_it_writes(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->useCompanyPrefix($tenant, self::NARROW_PREFIX, $this->uniqueGln('1'));

            $site = $this->createOrganizationSite($this->uniqueGln('1'));
            $narrowUrn = $this->splitOn(self::NARROW_PREFIX, $site->gln);
            $this->assertSame($narrowUrn, $site->fresh()->sgln);

            // The prefix moved somewhere this repair cannot see it happen — the admin
            // panel writes tenant identity from the central database.
            $tenant->forceFill(['company_prefix' => self::WIDE_PREFIX])->save();

            // Ops runs it the way ops runs it: from the central context, tenant by tenant.
            tenancy()->end();

            $this->artisan('tracepharma:rederive-organization-sglns', [
                '--tenants' => [self::DEMO2_TENANT_ID],
                '--dry-run' => true,
            ])->assertSuccessful();

            tenancy()->initialize($tenant->fresh());
            $this->assertSame($narrowUrn, $site->fresh()->sgln, 'A dry run reports and writes nothing.');
            tenancy()->end();

            $this->artisan('tracepharma:rederive-organization-sglns', [
                '--tenants' => [self::DEMO2_TENANT_ID],
            ])->assertSuccessful();

            tenancy()->initialize($tenant->fresh());
            $this->assertSame($this->splitOn(self::WIDE_PREFIX, $site->gln), $site->fresh()->sgln);
        } finally {
            $this->cleanup($tenant);
        }
    }

    /**
     * The admin panel edits tenant identity from the central database, so the re-derive
     * inside saveOrganization cannot reach the tenant's own sites. Saving a new prefix
     * there has to enter the tenant and repair them anyway.
     */
    #[Test]
    public function saving_a_new_company_prefix_from_the_admin_panel_re_authors_our_facilities(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $orgGln = $this->uniqueGln('1');
            $this->useCompanyPrefix($tenant, self::NARROW_PREFIX, $orgGln);

            $site = $this->createOrganizationSite($this->uniqueGln('1'));
            $this->assertSame($this->splitOn(self::NARROW_PREFIX, $site->gln), $site->fresh()->sgln);

            tenancy()->end();

            $admin = Admin::factory()->create();
            $this->adminIds[] = (int) $admin->getKey();
            $this->actingAs($admin, 'admin');
            Filament::setCurrentPanel(Filament::getPanel('admin'));

            Livewire::test(EditTenant::class, ['record' => $tenant->getKey()])
                ->fillForm(['company_prefix' => self::WIDE_PREFIX])
                ->call('save')
                ->assertHasNoFormErrors();

            $this->assertSame(
                self::WIDE_PREFIX,
                TenantSettings::forTenant($tenant->fresh())->companyPrefix(),
            );

            tenancy()->initialize($tenant->fresh());

            $this->assertSame(
                $this->splitOn(self::WIDE_PREFIX, $site->gln),
                $site->fresh()->sgln,
                'A prefix changed from the central panel still re-splits our own docks.',
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    private function createOrganizationSite(string $gln, ?string $sgln = null): Site
    {
        return Site::query()->create([
            'name' => 'Rederive Sgln Dock 094224 '.substr($gln, 6, 6),
            'gln' => $gln,
            'sgln' => $sgln,
            'trading_partner_id' => null,
            'is_organization_facility' => true,
            'is_active' => true,
        ]);
    }

    private function createLocationDevice(Site $site, string $gln): LocationDevice
    {
        return LocationDevice::query()->create([
            'site_id' => (int) $site->getKey(),
            'name' => 'Rederive Sgln Dock Door 094224 '.substr($gln, 6, 6),
            'gln' => $gln,
        ]);
    }

    private function splitOn(string $prefix, ?string $gln, string $extension = '0'): string
    {
        $digits = (string) $gln;

        return 'urn:epc:id:sgln:'.$prefix.'.'.substr($digits, strlen($prefix), 12 - strlen($prefix)).'.'.$extension;
    }

    /**
     * Set identity without going through saveOrganization, which is the thing under test.
     */
    private function useCompanyPrefix(Tenant $tenant, string $prefix, string $gln): void
    {
        TenantSettings::forTenant($tenant)
            ->setGln($gln)
            ->setCompanyPrefix($prefix);
        $tenant->save();

        tenancy()->end();
        tenancy()->initialize($tenant->fresh());
    }

    private function uniqueGln(string $marker): string
    {
        $body12 = self::GLN_PREFIX.$marker.str_pad((string) random_int(0, 99999), 5, '0', STR_PAD_LEFT);

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
        if (! tenancy()->initialized) {
            tenancy()->initialize($tenant->fresh() ?? $tenant);
        }

        DB::table('location_devices')->where('gln', 'like', self::GLN_PREFIX.'%')->delete();
        DB::table('sites')->where('gln', 'like', self::GLN_PREFIX.'%')->delete();
        DB::table('trading_partners')->where('gln', 'like', self::GLN_PREFIX.'%')->delete();

        // Restored directly: saveOrganization would re-derive against the demo baseline.
        $current = $tenant->fresh() ?? $tenant;
        $current->forceFill([
            'gln' => $this->priorGln,
            'company_prefix' => $this->priorCompanyPrefix,
        ])->save();

        tenancy()->end();

        if ($this->adminIds !== []) {
            DB::table('admins')->whereIn('id', $this->adminIds)->delete();
            $this->adminIds = [];
        }

        $this->priorGln = null;
        $this->priorCompanyPrefix = null;
    }
}
