<?php

namespace Tests\Unit\Filament;

use App\Filament\App\Support\EpcisSchemaSearchForm;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class EpcisSchemaSearchFormTest extends TestCase
{
    #[Test]
    public function simple_asn_or_po_searches_inbound_documents_by_shipment_ref(): void
    {
        $payload = EpcisSchemaSearchForm::searchPayloadFromForm([
            'advanced' => false,
            'asn_or_po' => 'C7174125NLC',
            'gtin14' => null,
            'lot_number' => null,
        ]);

        $this->assertSame('documents', $payload['result_type']);
        $this->assertSame([
            [
                'field' => 'doc.asn_or_po',
                'operator' => 'eq',
                'value' => 'C7174125NLC',
            ],
        ], $payload['rules']);
    }

    #[Test]
    public function simple_shipment_ref_pasted_into_gtin_is_rerouted(): void
    {
        $payload = EpcisSchemaSearchForm::searchPayloadFromForm([
            'advanced' => false,
            'gtin14' => 'C7174125NLC',
            'lot_number' => null,
            'asn_or_po' => null,
        ]);

        $this->assertSame('documents', $payload['result_type']);
        $this->assertSame('doc.asn_or_po', $payload['rules'][0]['field'] ?? null);
        $this->assertSame('C7174125NLC', $payload['rules'][0]['value'] ?? null);
    }

    #[Test]
    public function simple_asn_plus_gtin_searches_documents(): void
    {
        $payload = EpcisSchemaSearchForm::searchPayloadFromForm([
            'advanced' => false,
            'asn_or_po' => 'C7174125NLC',
            'gtin14' => '00301162001162',
            'lot_number' => null,
        ]);

        $this->assertSame('documents', $payload['result_type']);
        $this->assertSame('doc.asn_or_po', $payload['rules'][0]['field'] ?? null);
        $this->assertSame('epc.gtin14', $payload['rules'][1]['field'] ?? null);
    }

    #[Test]
    public function simple_lot_searches_inbound_documents(): void
    {
        $payload = EpcisSchemaSearchForm::searchPayloadFromForm([
            'advanced' => false,
            'gtin14' => null,
            'lot_number' => '605140A',
            'asn_or_po' => null,
        ]);

        $this->assertSame('documents', $payload['result_type']);
        $this->assertSame([
            [
                'field' => 'ilmd.lot_number',
                'operator' => 'eq',
                'value' => '605140A',
                'boolean' => 'and',
            ],
        ], $payload['rules']);
    }

    #[Test]
    public function simple_numeric_gtin_is_not_rerouted(): void
    {
        $payload = EpcisSchemaSearchForm::searchPayloadFromForm([
            'advanced' => false,
            'gtin14' => '00301162001162',
            'lot_number' => 'LOT1',
            'asn_or_po' => null,
        ]);

        $this->assertSame('documents', $payload['result_type']);
        $this->assertSame('epc.gtin14', $payload['rules'][0]['field'] ?? null);
        $this->assertSame('ilmd.lot_number', $payload['rules'][1]['field'] ?? null);
    }
}
