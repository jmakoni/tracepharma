<?php

declare(strict_types=1);

namespace Tests\Feature\BuyingGroup;

use App\Enums\PartnerType;
use App\Enums\TenantProfile;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Support\BuyingGroup\BuyingGroupMemberMetricsCollector;
use App\Support\MasterData\SiteAtpReadiness;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BuyingGroupMemberMetricsCollectorTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    /** @var list<int> */
    private array $partnerIds = [];

    /** @var list<int> */
    private array $siteIds = [];

    protected function tearDown(): void
    {
        SiteAtpReadiness::forget();

        if (tenancy()->initialized) {
            if ($this->siteIds !== []) {
                Site::query()->whereIn('id', $this->siteIds)->delete();
                $this->siteIds = [];
            }
            if ($this->partnerIds !== []) {
                TradingPartner::query()->whereIn('id', $this->partnerIds)->delete();
                $this->partnerIds = [];
            }
            tenancy()->end();
        }

        parent::tearDown();
    }

    #[Test]
    public function partner_fact_cap_does_not_truncate_atp_gap_count(): void
    {
        $this->initializeDemo2Tenant();

        for ($i = 0; $i < 3; $i++) {
            $partner = TradingPartner::factory()->create([
                'name' => "BG Metrics Partner {$i}",
                'partner_type' => PartnerType::Wholesaler,
                'is_active' => true,
            ]);
            $this->partnerIds[] = (int) $partner->getKey();

            $site = Site::factory()->create([
                'trading_partner_id' => $partner->getKey(),
                'name' => "BG Metrics Site {$i}",
                'is_active' => true,
                'state' => 'IL',
            ]);
            $this->siteIds[] = (int) $site->getKey();
        }

        SiteAtpReadiness::forget();

        $full = (new BuyingGroupMemberMetricsCollector(partnerFactLimit: 10_000))->collect();
        $capped = (new BuyingGroupMemberMetricsCollector(partnerFactLimit: 2))->collect();

        $this->assertGreaterThanOrEqual(3, $full['atp_gap_count']);
        $this->assertSame(
            $full['atp_gap_count'],
            $capped['atp_gap_count'],
            'ATP gap count must scan all in-scope sites even when partner facts are capped.',
        );
        $this->assertCount(2, $capped['partners']);
        $this->assertSame($full['health_score'], $capped['health_score']);
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
            $tenant->forceFill([
                'profile' => TenantProfile::Pharmacy,
                'status' => 'active',
            ])->save();
            $tenant->domains()->firstOrCreate(['domain' => self::DEMO2_DOMAIN]);
        }

        $this->artisan('tenants:migrate', [
            '--tenants' => [self::DEMO2_TENANT_ID],
            '--force' => true,
        ])->assertSuccessful();

        tenancy()->initialize($tenant);

        return $tenant;
    }
}
