<?php

namespace Tests\Unit\Support\Receiving;

use App\Models\Site;
use App\Support\Receiving\EligibleReceiveSites;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EligibleReceiveSitesIsEligibleTest extends TestCase
{
    #[Test]
    public function active_owned_site_with_gln_is_eligible(): void
    {
        $site = Site::make([
            'is_organization_facility' => true,
            'trading_partner_id' => null,
            'is_active' => true,
            'gln' => '0366159011001',
            'code' => 'WH-01',
        ]);

        $this->assertTrue(EligibleReceiveSites::isEligible($site));
    }

    #[Test]
    public function ours_alone_is_not_enough_without_active_or_gln(): void
    {
        $inactive = Site::make([
            'is_organization_facility' => true,
            'trading_partner_id' => null,
            'is_active' => false,
            'gln' => '0366159011001',
            'code' => 'WH-01',
        ]);
        $missingGln = Site::make([
            'is_organization_facility' => true,
            'trading_partner_id' => null,
            'is_active' => true,
            'gln' => '',
            'code' => 'WH-01',
        ]);

        $this->assertFalse(EligibleReceiveSites::isEligible($inactive));
        $this->assertFalse(EligibleReceiveSites::isEligible($missingGln));
    }

    #[Test]
    public function partner_linked_and_test_coded_sites_are_not_eligible(): void
    {
        $partnerLinked = Site::make([
            'is_organization_facility' => true,
            'trading_partner_id' => 9,
            'is_active' => true,
            'gln' => '0366159011001',
            'code' => 'WH-01',
        ]);
        $testCoded = Site::make([
            'is_organization_facility' => true,
            'trading_partner_id' => null,
            'is_active' => true,
            'gln' => '0366159011001',
            'code' => 'TEST-ABC',
        ]);
        $notOurs = Site::make([
            'is_organization_facility' => false,
            'trading_partner_id' => null,
            'is_active' => true,
            'gln' => '0366159011001',
            'code' => 'WH-01',
        ]);

        $this->assertFalse(EligibleReceiveSites::isEligible($partnerLinked));
        $this->assertFalse(EligibleReceiveSites::isEligible($testCoded));
        $this->assertFalse(EligibleReceiveSites::isEligible($notOurs));
    }
}
