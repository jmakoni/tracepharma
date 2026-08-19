<?php

namespace Tests\Feature\MasterData;

use App\Enums\PartnerType;
use App\Enums\TenantProfile;
use App\Models\LocationDevice;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Support\Gs1\Gtin;
use App\Support\TenantSettings;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The stored SGLN replaced a generated column that concatenated the first twelve digits
 * of the GLN into `urn:epc:id:sgln:{12 digits}.0` — two segments where GS1 Pure Identity
 * has three, so nothing could parse it and every location it described read as
 * unidentified.
 *
 * What replaces it holds only splits we know: ours, from the organization company
 * prefix, and a partner's, as the partner states it.
 *
 * GLNs are prefixed 094224 so rows stay traceable in the shared demo2 tenant.
 */
class DerivedSglnTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private const GLN_PREFIX = '094224';

    private const COMPANY_PREFIX = '0942241';

    private static bool $demo2TenantReady = false;

    private ?string $priorGln = null;

    private ?string $priorCompanyPrefix = null;

    #[Test]
    public function the_sgln_column_is_no_longer_a_generated_two_segment_urn(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            foreach (['sites', 'trading_partners', 'location_devices'] as $table) {
                $column = DB::selectOne(
                    'SELECT EXTRA FROM information_schema.COLUMNS '
                    .'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                    [$table, 'sgln'],
                );

                $this->assertNotNull($column, "{$table}.sgln is missing.");
                $this->assertStringNotContainsStringIgnoringCase(
                    'GENERATED',
                    (string) (((array) $column)['EXTRA'] ?? ''),
                    "{$table}.sgln must be a real column so it can hold a company-prefix split.",
                );
            }

            $this->assertSame(
                0,
                DB::table('sites')->where('sgln', 'not like', 'urn:epc:id:sgln:%.%.%')->whereNotNull('sgln')->count(),
                'No site may carry the legacy two-segment SGLN.',
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function our_own_facility_is_encoded_on_our_company_prefix(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->useCompanyPrefix($tenant);
            $gln = $this->uniqueGln('1');

            $site = Site::query()->create([
                'name' => 'Derived Sgln Dock 094224',
                'gln' => $gln,
                'is_active' => true,
                'is_organization_facility' => true,
            ]);

            $this->assertSame(
                'urn:epc:id:sgln:'.self::COMPANY_PREFIX.'.'.substr($gln, 7, 5).'.0',
                $site->fresh()->sgln,
            );

            $deviceGln = $this->uniqueGln('1');
            $device = LocationDevice::query()->create([
                'site_id' => (int) $site->getKey(),
                'name' => 'Derived Sgln Dock Door 094224',
                'gln' => $deviceGln,
            ]);

            $this->assertSame(
                'urn:epc:id:sgln:'.self::COMPANY_PREFIX.'.'.substr($deviceGln, 7, 5).'.0',
                $device->fresh()->sgln,
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function a_partner_outside_our_prefix_is_left_without_one(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->useCompanyPrefix($tenant);

            // Sharing our first six digits is not sharing our company prefix.
            $partner = TradingPartner::query()->create([
                'name' => 'Derived Sgln Partner 094224',
                'gln' => $this->uniqueGln('3'),
                'partner_type' => PartnerType::Pharmacy,
                'is_active' => true,
            ]);

            $this->assertNull($partner->fresh()->sgln);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function a_partner_keeps_the_sgln_it_states_and_loses_one_that_names_another_location(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->useCompanyPrefix($tenant);
            $gln = $this->uniqueGln('4');
            $theirSgln = 'urn:epc:id:sgln:'.substr($gln, 0, 6).'.'.substr($gln, 6, 6).'.0';

            $partner = TradingPartner::query()->create([
                'name' => 'Derived Sgln Stated Partner 094224',
                'gln' => $gln,
                'sgln' => $theirSgln,
                'partner_type' => PartnerType::Pharmacy,
                'is_active' => true,
            ]);

            $this->assertSame($theirSgln, $partner->fresh()->sgln);

            // An SGLN that encodes a different GLN is not this partner's identity.
            $partner->forceFill(['sgln' => 'urn:epc:id:sgln:0614141.00000.0'])->save();
            $this->assertNull($partner->fresh()->sgln);

            // Neither is the legacy two-segment form the generated column produced.
            $partner->forceFill(['sgln' => 'urn:epc:id:sgln:'.substr($gln, 0, 12).'.0'])->save();
            $this->assertNull($partner->fresh()->sgln);
        } finally {
            $this->cleanup($tenant);
        }
    }

    private function useCompanyPrefix(Tenant $tenant): void
    {
        TenantSettings::forTenant($tenant)
            ->setGln($this->uniqueGln('1'))
            ->setCompanyPrefix(self::COMPANY_PREFIX);
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
        if (tenancy()->initialized) {
            DB::table('location_devices')->where('gln', 'like', self::GLN_PREFIX.'%')->delete();
            DB::table('sites')->where('gln', 'like', self::GLN_PREFIX.'%')->delete();
            DB::table('trading_partners')->where('gln', 'like', self::GLN_PREFIX.'%')->delete();

            $current = $tenant->fresh() ?? $tenant;
            TenantSettings::forTenant($current)
                ->setGln($this->priorGln)
                ->setCompanyPrefix($this->priorCompanyPrefix);
            $current->save();

            tenancy()->end();
        }

        $this->priorGln = null;
        $this->priorCompanyPrefix = null;
    }
}
