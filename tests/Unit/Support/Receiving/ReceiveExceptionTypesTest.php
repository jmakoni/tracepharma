<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Receiving;

use App\Enums\ExceptionReceiveImpact;
use App\Support\Exceptions\ExceptionReceiveImpactMap;
use App\Support\Receiving\ReceiveExceptionTypes;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReceiveExceptionTypesTest extends TestCase
{
    #[Test]
    public function keeps_osd_names_separate_from_dscsa(): void
    {
        $map = ReceiveExceptionTypes::typeMap();

        $this->assertArrayHasKey(ReceiveExceptionTypes::SHORTAGE, $map);
        $this->assertArrayHasKey(ReceiveExceptionTypes::OVERAGE, $map);
        $this->assertArrayHasKey(ReceiveExceptionTypes::DAMAGED, $map);
        $this->assertArrayHasKey(ReceiveExceptionTypes::PRODUCT_NO_DATA, $map);
        $this->assertArrayHasKey(ReceiveExceptionTypes::DATA_NO_PRODUCT, $map);
        $this->assertArrayHasKey(ReceiveExceptionTypes::AGGREGATION_BREAK, $map);
        $this->assertArrayHasKey(ReceiveExceptionTypes::REASON_UNDECLARED_PARTIAL, $map);

        $this->assertSame('PARTIAL_SHIPMENT_UNDECLARED', ReceiveExceptionTypes::REASON_UNDECLARED_PARTIAL);
        $this->assertNotContains(ReceiveExceptionTypes::REASON_UNDECLARED_PARTIAL, ReceiveExceptionTypes::HARD_BLOCK_COMPLETE);
        $this->assertNotContains(ReceiveExceptionTypes::REASON_UNDECLARED_PARTIAL, ReceiveExceptionTypes::SHORT_CLOSE_REQUIRED);
    }

    #[Test]
    public function complete_impact_blocks_product_no_data_aggregation_and_damaged_only(): void
    {
        $this->assertSame(ExceptionReceiveImpact::HardBlocking, ExceptionReceiveImpactMap::forCode(ReceiveExceptionTypes::PRODUCT_NO_DATA));
        $this->assertSame(ExceptionReceiveImpact::HardBlocking, ExceptionReceiveImpactMap::forCode(ReceiveExceptionTypes::AGGREGATION_BREAK));
        $this->assertSame(ExceptionReceiveImpact::HardBlocking, ExceptionReceiveImpactMap::forCode(ReceiveExceptionTypes::DAMAGED));
        $this->assertSame(ExceptionReceiveImpact::Warning, ExceptionReceiveImpactMap::forCode(ReceiveExceptionTypes::DATA_NO_PRODUCT));
        $this->assertSame(ExceptionReceiveImpact::Warning, ExceptionReceiveImpactMap::forCode(ReceiveExceptionTypes::SHORTAGE));
        $this->assertSame(ExceptionReceiveImpact::Warning, ExceptionReceiveImpactMap::forCode(ReceiveExceptionTypes::OVERAGE));
        $this->assertSame(ExceptionReceiveImpact::Warning, ExceptionReceiveImpactMap::forCode(ReceiveExceptionTypes::REASON_UNDECLARED_PARTIAL));
    }
}
