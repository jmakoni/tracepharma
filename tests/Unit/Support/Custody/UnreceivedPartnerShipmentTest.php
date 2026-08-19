<?php

namespace Tests\Unit\Support\Custody;

use App\Enums\EpcisAuthoredKind;
use App\Support\Custody\OutboundShipmentInTransit;
use App\Support\Custody\UnreceivedPartnerShipment;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UnreceivedPartnerShipmentTest extends TestCase
{
    #[Test]
    public function matches_a_partner_shipment_whatever_location_it_names(): void
    {
        // The ASN a supplier sends us: their shipping event, our dock in bizLocation.
        $this->assertTrue(UnreceivedPartnerShipment::matches($this->partnerShippingMeta()));

        // A document uploaded as outbound but with no authoring markers is not ours
        // either — it is still a shipment, and still nothing we have received.
        $meta = $this->partnerShippingMeta();
        $meta['document_direction'] = 'outbound';
        $this->assertTrue(UnreceivedPartnerShipment::matches($meta));
    }

    #[Test]
    public function leaves_our_own_handoffs_to_the_authored_predicate(): void
    {
        foreach ([EpcisAuthoredKind::Shipping, EpcisAuthoredKind::Transferring] as $kind) {
            $meta = $this->partnerShippingMeta();
            $meta['document_direction'] = 'outbound';
            $meta['authored_kind'] = $kind->value;

            $this->assertTrue(OutboundShipmentInTransit::matches($meta));
            $this->assertFalse(
                UnreceivedPartnerShipment::matches($meta),
                'Shipped stock must keep the "already shipped" refusal.',
            );
        }
    }

    #[Test]
    public function ignores_events_that_put_the_unit_somewhere(): void
    {
        // The receiving event the floor authors on receipt — the whole point of the
        // predicate is that this is what confers custody.
        $this->assertFalse(UnreceivedPartnerShipment::matches([
            'event_type' => 'ObjectEvent',
            'biz_step' => 'urn:epcglobal:cbv:bizstep:receiving',
            'disposition' => 'urn:epcglobal:cbv:disp:in_progress',
            'document_direction' => 'outbound',
            'authored_kind' => EpcisAuthoredKind::Receiving->value,
            'document_notes' => null,
        ]));

        // A receiving or packing observation on a partner's document still reports
        // possession at the location it names, so it is left alone.
        foreach (['receiving', 'packing', 'commissioning'] as $step) {
            $this->assertFalse(UnreceivedPartnerShipment::matches([
                'event_type' => 'ObjectEvent',
                'biz_step' => 'urn:epcglobal:cbv:bizstep:'.$step,
                'disposition' => 'urn:epcglobal:cbv:disp:in_progress',
                'document_direction' => 'inbound',
                'authored_kind' => null,
                'document_notes' => null,
            ]));
        }

        $this->assertFalse(UnreceivedPartnerShipment::matches(null));
    }

    #[Test]
    public function sql_condition_binds_every_placeholder(): void
    {
        [$sql, $bindings] = UnreceivedPartnerShipment::eventCondition();

        $this->assertSame(substr_count($sql, '?'), count($bindings));
        $this->assertSame('%'.UnreceivedPartnerShipment::BIZ_STEP_NEEDLE.'%', $bindings[0]);
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
    private function partnerShippingMeta(): array
    {
        return [
            'event_type' => 'ObjectEvent',
            'biz_step' => 'urn:epcglobal:cbv:bizstep:shipping',
            'disposition' => 'urn:epcglobal:cbv:disp:in_transit',
            'document_direction' => 'inbound',
            'authored_kind' => null,
            'document_notes' => null,
        ];
    }
}
