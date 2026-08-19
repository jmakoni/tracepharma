<?php

namespace Tests\Unit\Models\Epcis;

use App\Enums\EpcisAuthoredKind;
use App\Models\Epcis\EpcisDocument;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EpcisDocumentDirectionDisplayLabelTest extends TestCase
{
    #[Test]
    #[DataProvider('generatedOutboundLabels')]
    public function distinguishes_generated_outbound_document_kinds_via_notes_and_filename_fallback(
        string $notes,
        string $filename,
        string $expected,
    ): void {
        $document = new EpcisDocument([
            'direction' => 'outbound',
            'notes' => $notes,
            'original_filename' => $filename,
        ]);

        $this->assertSame($expected, $document->directionDisplayLabel());
    }

    #[Test]
    #[DataProvider('authoredKinds')]
    public function prefers_persisted_authored_kind_over_notes_and_filename_fallback(
        EpcisAuthoredKind $kind,
        string $expected,
    ): void {
        $document = new EpcisDocument([
            'direction' => 'outbound',
            'authored_kind' => $kind,
            // Notes/filename deliberately point at a different kind to prove
            // authored_kind wins once persisted.
            'notes' => 'Partner shipment',
            'original_filename' => 'partner-asn.xml',
        ]);

        $this->assertSame($expected, $document->directionDisplayLabel());
    }

    /**
     * @return array<string, array{0: EpcisAuthoredKind, 1: string}>
     */
    public static function authoredKinds(): array
    {
        return [
            'shipping' => [EpcisAuthoredKind::Shipping, 'Outbound'],
            'receiving' => [EpcisAuthoredKind::Receiving, 'Generated receiving'],
            'transferring' => [EpcisAuthoredKind::Transferring, 'Generated transferring'],
            'sscc commissioning' => [EpcisAuthoredKind::SsccCommissioning, 'Generated SSCC commissioning'],
            'sscc aggregation' => [EpcisAuthoredKind::SsccAggregation, 'Generated SSCC aggregation'],
            'sscc disaggregation' => [EpcisAuthoredKind::SsccDisaggregation, 'Generated SSCC disaggregation'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function generatedOutboundLabels(): array
    {
        return [
            'receiving notes' => [
                'Generated receiving EPCIS (custody attestation, not TI/TS) for receiving session #12.',
                'receiving-12.xml',
                'Generated receiving',
            ],
            'receiving filename fallback' => [
                '',
                'receiving-99.xml',
                'Generated receiving',
            ],
            'sscc commissioning notes' => [
                'Generated SSCC commissioning EPCIS for sscc_label_batch_id=7.',
                'sscc-batch-7-commission.xml',
                'Generated SSCC commissioning',
            ],
            'sscc commissioning filename' => [
                '',
                'sscc-batch-7-commission.xml',
                'Generated SSCC commissioning',
            ],
            'sscc aggregation notes' => [
                'Generated SSCC aggregation EPCIS for sscc_label_batch_id=7.',
                'sscc-batch-7.xml',
                'Generated SSCC aggregation',
            ],
            'sscc aggregation label filename' => [
                '',
                'sscc-label-42.xml',
                'Generated SSCC aggregation',
            ],
            'sscc disaggregation' => [
                'Generated SSCC disaggregation EPCIS for sscc_label_batch_id=7.',
                'sscc-disaggregation-7.xml',
                'Generated SSCC disaggregation',
            ],
            'transferring notes' => [
                'Generated transferring EPCIS (intracompany custody) for transferring session #3.',
                'transfer-3.xml',
                'Generated transferring',
            ],
            'plain outbound' => [
                'Partner shipment',
                'partner-asn.xml',
                'Outbound',
            ],
            'generated outbound shipping notes' => [
                'Generated outbound shipping EPCIS for ship order session #22.',
                'ship-22.xml',
                'Outbound',
            ],
        ];
    }

    #[Test]
    public function authored_kind_shipping_wins_over_conflicting_receiving_notes(): void
    {
        $document = new EpcisDocument([
            'direction' => 'outbound',
            'authored_kind' => EpcisAuthoredKind::Shipping,
            // Notes/filename deliberately match the receiving heuristic to
            // prove the persisted authored_kind still wins.
            'notes' => 'Generated receiving EPCIS (custody attestation, not TI/TS) for receiving session #12.',
            'original_filename' => 'receiving-12.xml',
        ]);

        $this->assertSame('Outbound', $document->directionDisplayLabel());
    }

    #[Test]
    public function inbound_and_unknown_directions_use_simple_labels(): void
    {
        $inbound = new EpcisDocument(['direction' => 'inbound', 'notes' => 'Generated receiving']);
        $this->assertSame('Inbound', $inbound->directionDisplayLabel());

        $blank = new EpcisDocument(['direction' => null]);
        $this->assertSame('—', $blank->directionDisplayLabel());
    }

    #[Test]
    public function is_sscc_authored_kind_infers_from_notes_when_column_null(): void
    {
        $sscc = new EpcisDocument([
            'direction' => 'outbound',
            'authored_kind' => null,
            'notes' => 'Generated SSCC aggregation EPCIS for sscc_label_batch_id=7.',
            'original_filename' => 'sscc-batch-7.xml',
        ]);
        $this->assertTrue($sscc->isSsccAuthoredKind());

        $shipping = new EpcisDocument([
            'direction' => 'outbound',
            'authored_kind' => null,
            'notes' => 'Generated outbound shipping EPCIS for ship order session #22.',
            'original_filename' => 'ship-22.xml',
        ]);
        $this->assertFalse($shipping->isSsccAuthoredKind());
    }
}
