<?php

namespace Tests\Feature\MasterData;

use App\Enums\PartnerType;
use App\Enums\TenantProfile;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Rules\RejectPartnerGlnUnderOrgPrefix;
use App\Support\Gs1\Gtin;
use App\Support\TenantSettings;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AllowAssignPartnerGlnsFromPrefixTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private const GLN_PREFIX = '094225';

    private const COMPANY_PREFIX = '0942251';

    private static bool $demo2TenantReady = false;

    private ?string $priorGln = null;

    private ?string $priorCompanyPrefix = null;

    private bool $priorAllow = false;

    #[Test]
    public function the_rule_rejects_partner_glns_under_org_prefix_when_setting_is_off(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $orgGln = $this->uniqueGln('1');
            $underPrefix = $this->uniqueGln('1');
            $outsidePrefix = '0614141000005';

            TenantSettings::forTenant($tenant)
                ->setGln($orgGln)
                ->setCompanyPrefix(self::COMPANY_PREFIX)
                ->setAllowAssignPartnerGlnsFromPrefix(false);
            $tenant->save();
            tenancy()->end();
            tenancy()->initialize($tenant->fresh());

            $this->assertTrue($this->isRejected($underPrefix));
            $this->assertFalse($this->isRejected($outsidePrefix));
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function the_rule_allows_partner_glns_under_org_prefix_when_setting_is_on(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $orgGln = $this->uniqueGln('1');
            $underPrefix = $this->uniqueGln('1');

            TenantSettings::forTenant($tenant)
                ->setGln($orgGln)
                ->setCompanyPrefix(self::COMPANY_PREFIX)
                ->setAllowAssignPartnerGlnsFromPrefix(true);
            $tenant->save();
            tenancy()->end();
            tenancy()->initialize($tenant->fresh());

            $partner = TradingPartner::query()->create([
                'name' => 'Assigned Prefix Partner 094225',
                'gln' => $underPrefix,
                'partner_type' => PartnerType::Pharmacy,
                'is_active' => true,
            ]);

            $this->assertFalse($this->isRejected($underPrefix));
            $this->assertNotNull($partner->fresh()->sgln);
        } finally {
            $this->cleanup($tenant);
        }
    }

    private function isRejected(?string $gln): bool
    {
        $rejected = false;
        (new RejectPartnerGlnUnderOrgPrefix)->validate('gln', $gln, function () use (&$rejected): void {
            $rejected = true;
        });

        return $rejected;
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
        $this->priorAllow = $settings->allowAssignPartnerGlnsFromPrefix();

        return $tenant;
    }

    private function cleanup(Tenant $tenant): void
    {
        if (tenancy()->initialized) {
            DB::table('trading_partners')->where('gln', 'like', self::GLN_PREFIX.'%')->delete();

            $current = $tenant->fresh() ?? $tenant;
            TenantSettings::forTenant($current)
                ->setGln($this->priorGln)
                ->setCompanyPrefix($this->priorCompanyPrefix)
                ->setAllowAssignPartnerGlnsFromPrefix($this->priorAllow);
            $current->save();

            tenancy()->end();
        }

        $this->priorGln = null;
        $this->priorCompanyPrefix = null;
        $this->priorAllow = false;
    }
}
