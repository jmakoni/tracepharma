<?php

namespace Tests\Unit\Exceptions;

use App\Enums\EpcisGuideline;
use App\Enums\ExceptionReceiveImpact;
use App\Models\Epcis\EpcisDocument;
use App\Support\Epcis\DetectDscsaGuidelineRelease;
use App\Support\Exceptions\ExceptionReceiveImpactMap;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ExceptionReceiveImpactMapTest extends TestCase
{
    protected function tearDown(): void
    {
        config(['tracepharma.epcis.hard_gate_missing_biz_transaction' => false]);

        parent::tearDown();
    }

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
        $this->assertSame(ExceptionReceiveImpact::HardBlocking, ExceptionReceiveImpactMap::forCode('UNKNOWN_GTIN'));
        $this->assertSame(ExceptionReceiveImpact::HardBlocking, ExceptionReceiveImpactMap::forCode('SERIAL_SHIPPED_NOT_COMMISSIONED'));
        $this->assertSame(ExceptionReceiveImpact::Warning, ExceptionReceiveImpactMap::forCode('EVENTS_OUT_OF_ORDER'));
        $this->assertSame(ExceptionReceiveImpact::Soft, ExceptionReceiveImpactMap::forCode('BROKEN_AGGREGATION'));
        $this->assertSame(ExceptionReceiveImpact::HardBlocking, ExceptionReceiveImpactMap::forCode('VOID_SHIPPING'));
        $this->assertSame(ExceptionReceiveImpact::Warning, ExceptionReceiveImpactMap::forCode('ERROR_DECLARATION'));
        $this->assertSame(ExceptionReceiveImpact::Warning, ExceptionReceiveImpactMap::forCode('INBOUND_RECEIVER_REJECTED'));
        $this->assertSame(ExceptionReceiveImpact::Warning, ExceptionReceiveImpactMap::forCode('MIXED_PACKAGING_LEVELS'));
        $this->assertSame(ExceptionReceiveImpact::Warning, ExceptionReceiveImpactMap::forCode('INVALID_NDC_IDENTIFICATION_SHAPE'));
        $this->assertSame(ExceptionReceiveImpact::Warning, ExceptionReceiveImpactMap::forCode('MIXED_DSCSA_GUIDELINE_RELEASE'));
        $this->assertSame(ExceptionReceiveImpact::Soft, ExceptionReceiveImpactMap::forCode('MASTER_DATA_SYNC_LAG'));
        $this->assertSame(ExceptionReceiveImpact::Soft, ExceptionReceiveImpactMap::forCode('SERIAL_ALREADY_COMMISSIONED'));
        $this->assertSame(ExceptionReceiveImpact::HardBlocking, ExceptionReceiveImpactMap::forCode('MISSING_COMMISSIONING'));
        $this->assertSame(ExceptionReceiveImpact::Warning, ExceptionReceiveImpactMap::forCode('NOT_A_REAL_CODE'));
        $this->assertSame(ExceptionReceiveImpact::Soft, ExceptionReceiveImpactMap::forCode('CASE_ONLY_PALLET_COVERED'));
    }

    #[Test]
    public function for_code_on_document_without_own_product_context_matches_for_code(): void
    {
        $this->assertSame(
            ExceptionReceiveImpactMap::forCode('MISSING_DSCSA_STATEMENT'),
            ExceptionReceiveImpactMap::forCodeOnDocument('MISSING_DSCSA_STATEMENT', null),
        );
    }

    #[Test]
    public function missing_biz_transaction_stays_soft_when_hard_gate_off(): void
    {
        config(['tracepharma.epcis.hard_gate_missing_biz_transaction' => false]);

        $this->assertSame(ExceptionReceiveImpact::Soft, ExceptionReceiveImpactMap::forCode('MISSING_BIZ_TRANSACTION'));
        $this->assertSame(
            ExceptionReceiveImpact::Soft,
            ExceptionReceiveImpactMap::forCodeOnDocument('MISSING_BIZ_TRANSACTION', null),
        );

        $document = new EpcisDocument([
            'direction' => 'inbound',
            'asn_number' => null,
            'customer_po' => null,
        ]);

        $this->assertSame(
            ExceptionReceiveImpact::Soft,
            ExceptionReceiveImpactMap::forCodeOnDocument('MISSING_BIZ_TRANSACTION', $document),
        );
    }

    #[Test]
    public function hard_gate_promotes_missing_biz_when_both_po_and_asn_empty(): void
    {
        config(['tracepharma.epcis.hard_gate_missing_biz_transaction' => true]);

        $this->assertSame(
            ExceptionReceiveImpact::HardBlocking,
            ExceptionReceiveImpactMap::forCode('MISSING_BIZ_TRANSACTION'),
        );

        $document = new EpcisDocument([
            'direction' => 'inbound',
            'asn_number' => null,
            'customer_po' => '',
        ]);

        $this->assertSame(
            ExceptionReceiveImpact::HardBlocking,
            ExceptionReceiveImpactMap::forCodeOnDocument('MISSING_BIZ_TRANSACTION', $document),
        );
        $this->assertSame(
            ExceptionReceiveImpact::HardBlocking,
            ExceptionReceiveImpactMap::forCodeOnDocument('MISSING_BIZ_TRANSACTION', null),
        );
    }

    #[Test]
    public function hard_gate_keeps_soft_when_asn_present_without_po(): void
    {
        config(['tracepharma.epcis.hard_gate_missing_biz_transaction' => true]);

        $document = new EpcisDocument([
            'direction' => 'inbound',
            'asn_number' => 'DESADV-1001',
            'customer_po' => null,
        ]);

        $this->assertSame(
            ExceptionReceiveImpact::Soft,
            ExceptionReceiveImpactMap::forCodeOnDocument('MISSING_BIZ_TRANSACTION', $document),
        );
    }

    #[Test]
    public function hard_gate_keeps_soft_when_po_present_without_asn(): void
    {
        config(['tracepharma.epcis.hard_gate_missing_biz_transaction' => true]);

        $document = new EpcisDocument([
            'direction' => 'inbound',
            'asn_number' => null,
            'customer_po' => 'PO-4421',
        ]);

        $this->assertSame(
            ExceptionReceiveImpact::Soft,
            ExceptionReceiveImpactMap::forCodeOnDocument('MISSING_BIZ_TRANSACTION', $document),
        );
    }

    #[Test]
    public function hard_gate_missing_biz_transaction_config_defaults_off(): void
    {
        $this->assertFalse((bool) config('tracepharma.epcis.hard_gate_missing_biz_transaction'));
    }

    #[Test]
    public function serialized_r12_document_keeps_serial_shipped_hard_blocking(): void
    {
        $document = new EpcisDocument([
            'direction' => 'inbound',
            'dscsa_guideline_release' => EpcisGuideline::R12,
        ]);

        $this->assertFalse(DetectDscsaGuidelineRelease::documentIsR12LotOnly($document));
        $this->assertTrue(
            ExceptionReceiveImpactMap::forCodeOnDocument('SERIAL_SHIPPED_NOT_COMMISSIONED', $document)->blocksReceiving(),
        );
        $this->assertTrue(
            ExceptionReceiveImpactMap::forCodeOnDocument('MISSING_PARENT', $document)->blocksReceiving(),
        );
    }

    #[Test]
    public function r12_lot_only_document_does_not_hard_block_r13_only_or_serial_shipped_codes(): void
    {
        $path = 'epcis-audit-r12-lot-only.xml';
        Storage::disk('local')->put($path, <<<'XML'
<EPCISDocument>
  <attribute id="urn:epcglobal:cbv:mda#additionalTradeItemIdentificationTypeCode">FDA_NDC_11</attribute>
  <quantityList>
    <quantityElement>
      <epcClass>urn:epc:class:lgtin:030116.0200116.606412T</epcClass>
      <quantity>12</quantity>
    </quantityElement>
  </quantityList>
</EPCISDocument>
XML);

        $document = new EpcisDocument([
            'direction' => 'inbound',
            'dscsa_guideline_release' => EpcisGuideline::R12,
            'payload_path' => $path,
            'payload_disk' => 'local',
        ]);

        try {
            $this->assertTrue(DetectDscsaGuidelineRelease::documentIsR12LotOnly($document));
            $this->assertFalse(
                ExceptionReceiveImpactMap::forCodeOnDocument('DROP_SHIPMENT_INDICATOR_MISSING', $document)->blocksReceiving(),
            );
            $this->assertFalse(
                ExceptionReceiveImpactMap::forCodeOnDocument('MISSING_PARENT', $document)->blocksReceiving(),
            );
            $this->assertFalse(
                ExceptionReceiveImpactMap::forCodeOnDocument('SERIAL_SHIPPED_NOT_COMMISSIONED', $document)->blocksReceiving(),
            );
            $this->assertFalse(
                ExceptionReceiveImpactMap::forCodeOnDocument('LOT_MISMATCH', $document)->blocksReceiving(),
            );
            $this->assertSame(
                ExceptionReceiveImpact::Soft,
                ExceptionReceiveImpactMap::forCodeOnDocument('SERIAL_SHIPPED_NOT_COMMISSIONED', $document),
            );
        } finally {
            Storage::disk('local')->delete($path);
        }
    }

    #[Test]
    public function item_level_serial_shipped_without_document_stays_hard_blocking(): void
    {
        $this->assertTrue(
            ExceptionReceiveImpactMap::forCodeOnDocument('SERIAL_SHIPPED_NOT_COMMISSIONED', null)->blocksReceiving(),
        );
    }

    #[Test]
    public function events_out_of_order_never_blocks_receive(): void
    {
        $r13 = new EpcisDocument([
            'direction' => 'inbound',
            'dscsa_guideline_release' => EpcisGuideline::R13,
        ]);

        $this->assertFalse(ExceptionReceiveImpactMap::forCode('EVENTS_OUT_OF_ORDER')->blocksReceiving());
        $this->assertFalse(
            ExceptionReceiveImpactMap::forCodeOnDocument('EVENTS_OUT_OF_ORDER', $r13)->blocksReceiving(),
        );
    }
}
