<?php

namespace Tests\Unit\Exceptions;

use App\Enums\ExceptionReceiveImpact;
use App\Support\Exceptions\ExceptionReceiveImpactMap;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ExceptionReceiveImpactMapTest extends TestCase
{
    #[Test]
    public function hard_and_business_rule_block_receiving(): void
    {
        $this->assertTrue(ExceptionReceiveImpact::HardBlocking->blocksReceiving());
        $this->assertTrue(ExceptionReceiveImpact::BusinessRule->blocksReceiving());
        $this->assertFalse(ExceptionReceiveImpact::Warning->blocksReceiving());
        $this->assertFalse(ExceptionReceiveImpact::Soft->blocksReceiving());
    }

    #[Test]
    public function maps_pharmacy_inbound_codes_to_expected_tiers(): void
    {
        $this->assertSame(ExceptionReceiveImpact::HardBlocking, ExceptionReceiveImpactMap::forCode('SUSPECT_PRODUCT'));
        $this->assertSame(ExceptionReceiveImpact::HardBlocking, ExceptionReceiveImpactMap::forCode('MISSING_DSCSA_STATEMENT'));
        $this->assertSame(ExceptionReceiveImpact::BusinessRule, ExceptionReceiveImpactMap::forCode('UNKNOWN_GTIN'));
        $this->assertSame(ExceptionReceiveImpact::Warning, ExceptionReceiveImpactMap::forCode('MIXED_PACKAGING_LEVELS'));
        $this->assertSame(ExceptionReceiveImpact::Soft, ExceptionReceiveImpactMap::forCode('MASTER_DATA_SYNC_LAG'));
        $this->assertSame(ExceptionReceiveImpact::Soft, ExceptionReceiveImpactMap::forCode('SERIAL_ALREADY_COMMISSIONED'));
        $this->assertSame(ExceptionReceiveImpact::Warning, ExceptionReceiveImpactMap::forCode('NOT_A_REAL_CODE'));
    }
}
