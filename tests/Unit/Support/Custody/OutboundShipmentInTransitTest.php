<?php

namespace Tests\Unit\Support\Custody;

use App\Enums\EpcisAuthoredKind;
use App\Support\Custody\OutboundShipmentInTransit;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OutboundShipmentInTransitTest extends TestCase
{
    #[Test]
    public function matches_authored_shipping_and_transferring_handoffs(): void
    {
        $this->assertTrue(OutboundShipmentInTransit::matches(
            $this->shippingMeta(EpcisAuthoredKind::Shipping->value),
        ));

        // A transfer between our own sites is still a handoff: the goods are on a
        // truck, at neither site, until the destination receives them.
        $this->assertTrue(OutboundShipmentInTransit::matches(
            $this->shippingMeta(EpcisAuthoredKind::Transferring->value),
        ));
    }

    #[Test]
    public function matches_transfers_authored_before_the_authored_kind_column(): void
    {
        $this->assertTrue(OutboundShipmentInTransit::matches($this->shippingMeta(
            authoredKind: null,
            documentNotes: 'Generated transferring EPCIS (intracompany custody) for transferring session #7.',
        )));
    }

    #[Test]
    public function ignores_receiving_documents_and_other_authored_kinds(): void
    {
        $this->assertFalse(OutboundShipmentInTransit::matches(
            $this->shippingMeta(EpcisAuthoredKind::Receiving->value),
        ));

        $this->assertFalse(OutboundShipmentInTransit::matches(
            $this->shippingMeta(EpcisAuthoredKind::SsccAggregation->value),
        ));

        // A partner telling us they shipped says nothing about our custody.
        $meta = $this->shippingMeta(EpcisAuthoredKind::Transferring->value);
        $meta['document_direction'] = 'inbound';
        $this->assertFalse(OutboundShipmentInTransit::matches($meta));
    }

    #[Test]
    public function ignores_a_transfer_receipt_at_the_destination(): void
    {
        $this->assertFalse(OutboundShipmentInTransit::matches([
            'event_type' => 'ObjectEvent',
            'biz_step' => 'urn:epcglobal:cbv:bizstep:receiving',
            'disposition' => 'urn:epcglobal:cbv:disp:in_progress',
            'document_direction' => 'outbound',
            'authored_kind' => EpcisAuthoredKind::Transferring->value,
            'document_notes' => null,
        ]));
    }

    #[Test]
    public function sql_condition_binds_both_handoff_kinds(): void
    {
        [$sql, $bindings] = OutboundShipmentInTransit::eventCondition();

        $this->assertSame(substr_count($sql, '?'), count($bindings));
        $this->assertContains(EpcisAuthoredKind::Shipping->value, $bindings);
        $this->assertContains(EpcisAuthoredKind::Transferring->value, $bindings);
    }

    /**
     * @return array{
     *     event_type: string,
     *     biz_step: string,
     *     disposition: string,
     *     document_direction: string,
     *     authored_kind: ?string,
     *     document_notes: ?string
     * }
     */
    private function shippingMeta(?string $authoredKind, ?string $documentNotes = null): array
    {
        return [
            'event_type' => 'ObjectEvent',
            'biz_step' => 'urn:epcglobal:cbv:bizstep:shipping',
            'disposition' => 'urn:epcglobal:cbv:disp:in_transit',
            'document_direction' => 'outbound',
            'authored_kind' => $authoredKind,
            'document_notes' => $documentNotes,
        ];
    }
}
