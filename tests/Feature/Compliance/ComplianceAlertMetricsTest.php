<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Enums\PartnerType;
use App\Enums\SiteAtpReadinessStatus;
use App\Enums\TenantProfile;
use App\Models\Epcis\EpcisDocument;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Support\Compliance\ComplianceAlertMetrics;
use App\Support\MasterData\AtpLicenseRelevance;
use App\Support\MasterData\SiteAtpReadiness;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ComplianceAlertMetricsTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $partnerIds = [];

    /** @var list<int> */
    private array $siteIds = [];

    /** @var list<int> */
    private array $documentIds = [];

    protected function tearDown(): void
    {
        SiteAtpReadiness::forget();
        $this->cleanupTenantRows();

        parent::tearDown();
    }

    #[Test]
    public function manufacturer_wdd_site_without_ship_from_is_not_in_missing_atp_alert(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $this->setTenantReceivingState($tenant, 'CA');

        try {
            $partner = TradingPartner::factory()->create([
                'partner_type' => PartnerType::Manufacturer,
                'fda_organization_id' => 5092,
                'street_address' => '1200 E Business Center Dr',
                'city' => 'Mt Prospect',
                'state' => 'IL',
                'zipcode' => '60056',
            ]);
            $this->partnerIds[] = (int) $partner->getKey();

            $hq = Site::factory()->create([
                'trading_partner_id' => $partner->id,
                'name' => 'Xttrium Laboratories, Inc. - HQ Site',
                'is_headquarters' => true,
                'street_address' => '1200 E Business Center Dr',
                'city' => 'Mt Prospect',
                'state' => 'IL',
                'zipcode' => '60056',
            ]);
            $this->siteIds[] = (int) $hq->getKey();

            $glenview = Site::factory()->create([
                'trading_partner_id' => $partner->id,
                'name' => 'XTTRIUM LABORATORIES, INC. - Glenview',
                'fda_wdd_facility_id' => 1970,
                'street_address' => '3400 W Lake Ave',
                'city' => 'Glenview',
                'state' => 'IL',
                'zipcode' => '60026',
            ]);
            $this->siteIds[] = (int) $glenview->getKey();

            $this->assertFalse(AtpLicenseRelevance::siteInComplianceAlertScope($hq->fresh(['tradingPartner'])));
            $this->assertFalse(AtpLicenseRelevance::siteInComplianceAlertScope($glenview->fresh(['tradingPartner'])));
        } finally {
            $this->cleanupTenantRows();
        }
    }

    #[Test]
    public function manufacturer_wdd_site_with_ship_from_is_named_in_missing_atp_alert(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $this->setTenantReceivingState($tenant, 'CA');

        try {
            $partner = TradingPartner::factory()->create([
                'name' => 'AAA Compliance Metrics Mfr',
                'partner_type' => PartnerType::Manufacturer,
                'fda_organization_id' => 5092,
            ]);
            $this->partnerIds[] = (int) $partner->getKey();

            $dc = Site::factory()->create([
                'trading_partner_id' => $partner->id,
                'name' => 'AAA Compliance Metrics DC',
                'fda_wdd_facility_id' => 1970,
                'street_address' => '3400 W Lake Ave',
                'city' => 'Glenview',
                'state' => 'IL',
                'zipcode' => '60026',
            ]);
            $this->siteIds[] = (int) $dc->getKey();

            $document = EpcisDocument::query()->create([
                'document_uuid' => (string) Str::uuid(),
                'direction' => 'inbound',
                'status' => 'validated',
                'ship_from_site_id' => $dc->id,
                'original_filename' => 'glenview-ship-from-proof.xml',
                'file_sha256' => hash('sha256', 'glenview-ship-from-proof-'.uniqid()),
                'creation_date' => now(),
                'received_at' => now(),
            ]);
            $this->documentIds[] = (int) $document->getKey();

            $dc = $dc->fresh(['tradingPartner']);

            $this->assertTrue(AtpLicenseRelevance::siteInComplianceAlertScope($dc));
            $this->assertSame(
                SiteAtpReadinessStatus::NoLicenses,
                SiteAtpReadiness::summarize($dc)['status'],
            );

            $alerts = app(ComplianceAlertMetrics::class)->alerts(null);
            $missing = collect($alerts)->first(
                fn (array $alert): bool => $alert['title'] === 'Missing ATP evidence',
            );

            $this->assertNotNull($missing);
            $this->assertStringContainsString(
                $partner->name.' — '.$dc->name,
                (string) $missing['detail'],
            );
            $this->assertStringContainsString('lack in-force licence for CA', (string) $missing['detail']);
        } finally {
            $this->cleanupTenantRows();
        }
    }

    #[Test]
    public function manufacturer_headquarters_with_fda_org_is_fda_registered(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $partner = TradingPartner::factory()->create([
                'partner_type' => PartnerType::Manufacturer,
                'fda_organization_id' => 5092,
            ]);
            $this->partnerIds[] = (int) $partner->getKey();

            $hq = Site::factory()->create([
                'trading_partner_id' => $partner->id,
                'is_headquarters' => true,
                'name' => 'Mfr HQ FDA Org',
                'state' => 'IL',
            ]);
            $this->siteIds[] = (int) $hq->getKey();

            SiteAtpReadiness::forget($hq);
            $stats = SiteAtpReadiness::summarize($hq->fresh(['tradingPartner']));

            $this->assertSame(SiteAtpReadinessStatus::FdaRegistered, $stats['status']);
            $this->assertSame('Ready', SiteAtpReadiness::badgeLabel($hq));
            $this->assertSame('FDA registered · all states', SiteAtpReadiness::badgeDescription($hq));
        } finally {
            $this->cleanupTenantRows();
        }
    }

    private function setTenantReceivingState(Tenant $tenant, ?string $state): void
    {
        $tenant->receiving_state = $state;
        $tenant->save();

        tenancy()->end();
        tenancy()->initialize($tenant->fresh());
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

    private function cleanupTenantRows(): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        if ($this->documentIds !== []) {
            EpcisDocument::query()->whereIn('id', $this->documentIds)->delete();
        }

        if ($this->siteIds !== []) {
            Site::query()->whereIn('id', $this->siteIds)->delete();
        }

        if ($this->partnerIds !== []) {
            Site::query()->whereIn('trading_partner_id', $this->partnerIds)->delete();
            TradingPartner::query()->whereIn('id', $this->partnerIds)->delete();
        }

        $this->documentIds = [];
        $this->siteIds = [];
        $this->partnerIds = [];

        tenancy()->end();
    }
}
