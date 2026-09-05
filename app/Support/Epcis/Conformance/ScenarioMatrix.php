<?php

declare(strict_types=1);

namespace App\Support\Epcis\Conformance;

/**
 * Curated internal GS1 US Rx / EPCIS scenario matrix (existing fixtures only).
 *
 * This is internal DSCSA/IG evidence — not TraceReady, Gateway Checker, or GS1 Trustmark certified.
 */
final class ScenarioMatrix
{
    /**
     * @return list<array{
     *     id: string,
     *     title: string,
     *     fixture: string,
     *     expect: 'pass'|'fail',
     *     uuid_placeholder: string,
     *     ig_note: string
     * }>
     */
    public static function scenarios(): array
    {
        return [
            [
                'id' => 'rx-r12-minimal-pack',
                'title' => 'R1.2 minimal object events (commission / pack / ship)',
                'fixture' => 'tests/Fixtures/epcis/minimal_object_shipping.xml',
                'expect' => 'pass',
                'uuid_placeholder' => '11111111-2222-3333-4444-555555555555',
                'ig_note' => 'GS1 US Rx EPCIS R1.2 — minimal valid inbound pack',
            ],
            [
                'id' => 'rx-r12-missing-locations',
                'title' => 'R1.2 commissioning missing readPoint / bizLocation',
                'fixture' => 'tests/Fixtures/epcis/commissioning_sscc_missing_locations.xml',
                'expect' => 'fail',
                'uuid_placeholder' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
                'ig_note' => 'GS1 US Rx EPCIS R1.2 — expected catalog hard-fail (MISSING_MANDATORY_FIELD)',
            ],
            [
                'id' => 'rx-schema-1-3-pack',
                'title' => 'EPCIS 1.3 schema minimal pack',
                'fixture' => 'tests/Fixtures/epcis/minimal_object_shipping_1.3.xml',
                'expect' => 'pass',
                'uuid_placeholder' => '11111111-2222-3333-4444-555555555555',
                'ig_note' => 'EPCIS 1.3 document accepted by TracePharma ingest/validation',
            ],
            [
                'id' => 'rx-r12-shipping-masterdata',
                'title' => 'R1.2 shipping with master data vocabulary',
                'fixture' => 'tests/Fixtures/epcis/minimal_with_shipping_refs.xml',
                'expect' => 'pass',
                'uuid_placeholder' => '22222222-3333-4444-5555-666666666666',
                'ig_note' => 'GS1 US Rx EPCIS R1.2 — shipping + VocabularyElement master data',
            ],
            [
                'id' => 'rx-r12-3pl-four-party',
                'title' => 'R1.2 3PL four-party shipping',
                'fixture' => 'tests/Fixtures/epcis/shipping_3pl_four_party.xml',
                'expect' => 'pass',
                'uuid_placeholder' => '33333333-4444-5555-6666-777777777777',
                'ig_note' => 'GS1 US Rx EPCIS R1.2 — four-party (3PL) source/destination pattern',
            ],
        ];
    }
}
