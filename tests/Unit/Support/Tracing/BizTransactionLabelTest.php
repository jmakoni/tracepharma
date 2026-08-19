<?php

namespace Tests\Unit\Support\Tracing;

use App\Support\Tracing\BizTransactionLabel;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class BizTransactionLabelTest extends TestCase
{
    #[Test]
    public function it_humanizes_known_cbv_business_transaction_types(): void
    {
        $this->assertSame(
            '(ASN) Despatch Advice',
            BizTransactionLabel::forTypeUri('urn:epcglobal:cbv:btt:desadv'),
        );

        $this->assertSame(
            'Purchase Order',
            BizTransactionLabel::forTypeUri('urn:epcglobal:cbv:btt:po'),
        );

        $this->assertSame(
            'Proof of Delivery',
            BizTransactionLabel::forTypeUri('urn:epcglobal:cbv:btt:pod'),
        );

        $this->assertSame(
            'Invoice',
            BizTransactionLabel::forTypeUri('urn:epcglobal:cbv:btt:inv'),
        );
    }

    #[Test]
    public function it_title_cases_unknown_last_path_segments(): void
    {
        $this->assertSame(
            'Custom Vendor Type',
            BizTransactionLabel::forTypeUri('urn:epcglobal:cbv:btt:custom_vendor_type'),
        );

        $this->assertSame(
            'Weird Type',
            BizTransactionLabel::forTypeUri('urn:example:weird-type'),
        );
    }

    #[Test]
    public function it_handles_bare_local_names_and_blank_input(): void
    {
        $this->assertSame('Purchase Order', BizTransactionLabel::forTypeUri('po'));
        $this->assertSame('—', BizTransactionLabel::forTypeUri(null));
        $this->assertSame('—', BizTransactionLabel::forTypeUri(''));
    }

    #[Test]
    public function it_is_case_insensitive_for_known_types(): void
    {
        $this->assertSame(
            'Purchase Order',
            BizTransactionLabel::forTypeUri('urn:epcglobal:cbv:btt:PO'),
        );
    }
}
