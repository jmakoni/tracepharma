<?php

namespace Tests\Unit\Support\Epcis;

use App\Support\Epcis\EpcisQueryFieldRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class EpcisQueryFieldRegistryTest extends TestCase
{
    private EpcisQueryFieldRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new EpcisQueryFieldRegistry;
    }

    #[Test]
    public function fields_for_epcs_includes_identity_lot_and_event_keys(): void
    {
        $keys = array_column($this->registry->fieldsFor('epcs'), 'key');

        $this->assertContains('epc.gtin14', $keys);
        $this->assertContains('ilmd.lot_number', $keys);
        $this->assertContains('doc.asn_number', $keys);
        $this->assertContains('event.event_type', $keys);
        $this->assertContains('bt.value', $keys);
    }

    #[Test]
    public function fields_for_documents_excludes_epc_and_event_only_keys(): void
    {
        $keys = array_column($this->registry->fieldsFor('documents'), 'key');

        $this->assertContains('doc.asn_number', $keys);
        $this->assertContains('doc.status', $keys);
        $this->assertContains('bt.value', $keys);
        $this->assertContains('epc.gtin14', $keys);
        $this->assertContains('ilmd.lot_number', $keys);
        $this->assertNotContains('event.event_type', $keys);
    }

    #[Test]
    public function grouped_options_use_optgroups(): void
    {
        $grouped = $this->registry->groupedOptions('epcs');

        $this->assertArrayHasKey(EpcisQueryFieldRegistry::GROUP_IDENTITY, $grouped);
        $this->assertArrayHasKey(EpcisQueryFieldRegistry::GROUP_PRODUCT_LOT, $grouped);
        $this->assertArrayHasKey(EpcisQueryFieldRegistry::GROUP_SHIPPING_TI, $grouped);
        $this->assertArrayHasKey(EpcisQueryFieldRegistry::GROUP_DOCUMENT, $grouped);
        $this->assertArrayHasKey(EpcisQueryFieldRegistry::GROUP_EVENT, $grouped);
        $this->assertSame('GTIN-14', $grouped[EpcisQueryFieldRegistry::GROUP_IDENTITY]['epc.gtin14']);
    }

    #[Test]
    public function operators_respect_field_type_discipline(): void
    {
        $stringOps = EpcisQueryFieldRegistry::operatorsForType('string');
        $dateOps = EpcisQueryFieldRegistry::operatorsForType('date');
        $enumOps = EpcisQueryFieldRegistry::operatorsForType('enum');
        $boolOps = EpcisQueryFieldRegistry::operatorsForType('bool');

        $this->assertSame($stringOps, $this->registry->operatorsFor('epc.gtin14'));
        $this->assertSame($stringOps, $this->registry->operatorsFor('ilmd.lot_number'));
        $this->assertSame($stringOps, $this->registry->operatorsFor('doc.asn_number'));
        $this->assertSame($stringOps, $this->registry->operatorsFor('epc.epc_uri'));
        $this->assertSame($dateOps, $this->registry->operatorsFor('ilmd.expiry_date'));
        $this->assertSame($enumOps, $this->registry->operatorsFor('doc.status'));
        $this->assertSame($boolOps, $this->registry->operatorsFor('doc.dscsa_affirm'));
        $this->assertSame($stringOps, $this->registry->get('doc.asn_number')['operators'] ?? null);

        $documentFields = [];
        foreach ($this->registry->fieldsFor('documents') as $field) {
            $documentFields[$field['key']] = $field;
        }
        $this->assertSame($stringOps, $documentFields['doc.asn_number']['operators'] ?? null);
        $this->assertSame($dateOps, $documentFields['doc.creation_date']['operators'] ?? null);
        $this->assertSame($enumOps, $documentFields['doc.status']['operators'] ?? null);
    }

    #[Test]
    public function operators_for_type_returns_expected_sets(): void
    {
        $this->assertSame([
            'eq',
            'neq',
            'contains',
            'not_contains',
            'starts_with',
            'ends_with',
            'is_empty',
            'is_not_empty',
        ], EpcisQueryFieldRegistry::operatorsForType('string'));

        $this->assertSame(
            EpcisQueryFieldRegistry::operatorsForType('string'),
            EpcisQueryFieldRegistry::operatorsForType('gln'),
        );

        $this->assertSame([
            'eq',
            'neq',
            'gt',
            'gte',
            'lt',
            'lte',
            'between',
            'not_between',
        ], EpcisQueryFieldRegistry::operatorsForType('numeric'));

        $this->assertSame([
            'eq',
            'before',
            'before_or_equal',
            'after',
            'after_or_equal',
            'between',
            'not_between',
            'is_today',
            'is_yesterday',
            'is_this_week',
            'is_this_month',
        ], EpcisQueryFieldRegistry::operatorsForType('date'));

        $this->assertSame([
            'eq',
            'neq',
            'is_any_of',
            'is_not_any_of',
        ], EpcisQueryFieldRegistry::operatorsForType('enum'));

        $this->assertSame(
            EpcisQueryFieldRegistry::operatorsForType('enum'),
            EpcisQueryFieldRegistry::operatorsForType('fk_partner'),
        );

        $this->assertSame([
            'is_true',
            'is_false',
            'eq',
        ], EpcisQueryFieldRegistry::operatorsForType('bool'));
    }

    #[Test]
    public function selective_keys_match_plan(): void
    {
        $selective = $this->registry->selectiveKeys();

        foreach ([
            'epc.gtin14',
            'epc.sscc18',
            'epc.serial_number',
            'epc.ai_01_21',
            'epc.ai_00',
            'epc.epc_uri',
            'ilmd.lot_number',
            'doc.id',
            'doc.asn_or_po',
            'doc.asn_number',
            'doc.customer_po',
            'bt.value',
        ] as $key) {
            $this->assertContains($key, $selective, "Expected selective key [{$key}]");
            $this->assertTrue($this->registry->isSelective($key));
        }

        $this->assertFalse($this->registry->isSelective('doc.status'));
        $this->assertFalse($this->registry->isSelective('ilmd.expiry_date'));
        $this->assertFalse($this->registry->isSelective('event.event_type'));
    }

    #[Test]
    public function primary_field_keys_and_grouped_options_primary_filter(): void
    {
        $primary = $this->registry->primaryFieldKeys();

        $this->assertSame([
            'epc.gtin14',
            'ilmd.lot_number',
            'epc.sscc18',
            'epc.serial_number',
            'doc.asn_or_po',
            'doc.asn_number',
            'doc.customer_po',
        ], $primary);

        $primaryOnly = $this->registry->groupedOptions('epcs', primaryOnly: true);
        $flat = collect($primaryOnly)->flatMap(fn (array $opts) => array_keys($opts))->all();

        $this->assertContains('epc.gtin14', $flat);
        $this->assertContains('ilmd.lot_number', $flat);
        $this->assertNotContains('doc.id', $flat);
        $this->assertNotContains('event.event_type', $flat);

        $withSelected = $this->registry->groupedOptions('epcs', primaryOnly: true, alwaysIncludeKeys: ['doc.id']);
        $flatWith = collect($withSelected)->flatMap(fn (array $opts) => array_keys($opts))->all();
        $this->assertContains('doc.id', $flatWith);
    }

    #[Test]
    public function get_returns_field_definition(): void
    {
        $field = $this->registry->get('doc.direction');

        $this->assertNotNull($field);
        $this->assertSame('enum', $field['type']);
        $this->assertSame(['inbound' => 'Inbound', 'outbound' => 'Outbound'], $field['options']);
        $this->assertNull($this->registry->get('not.a.field'));
    }
}
