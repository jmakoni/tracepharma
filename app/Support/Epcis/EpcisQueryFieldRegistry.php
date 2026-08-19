<?php

namespace App\Support\Epcis;

/**
 * Curated whitelist of EPCIS schema fields for the Find / Recall query builder.
 *
 * @phpstan-type FieldDef array{
 *     key: string,
 *     label: string,
 *     group: string,
 *     type: string,
 *     table: string,
 *     column: string,
 *     scopes: list<string>,
 *     operators: list<string>,
 *     selective: bool,
 *     options?: array<string, string>|null
 * }
 */
final class EpcisQueryFieldRegistry
{
    public const GROUP_IDENTITY = 'Identity';

    public const GROUP_PRODUCT_LOT = 'Product / lot';

    public const GROUP_SHIPPING_TI = 'Shipping / TI';

    public const GROUP_DOCUMENT = 'Document';

    public const GROUP_EVENT = 'Event';

    /**
     * @return list<string>
     */
    public static function operatorsForType(string $type): array
    {
        return match ($type) {
            'string', 'gln' => [
                'eq',
                'neq',
                'contains',
                'not_contains',
                'starts_with',
                'ends_with',
                'is_empty',
                'is_not_empty',
            ],
            'numeric' => [
                'eq',
                'neq',
                'gt',
                'gte',
                'lt',
                'lte',
                'between',
                'not_between',
            ],
            'date' => [
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
            ],
            'enum', 'fk_partner' => [
                'eq',
                'neq',
                'is_any_of',
                'is_not_any_of',
            ],
            'bool' => [
                'is_true',
                'is_false',
                'eq',
            ],
            default => ['eq'],
        };
    }

    /**
     * @return list<FieldDef>
     */
    public static function all(): array
    {
        return [
            [
                'key' => 'epc.gtin14',
                'label' => 'GTIN-14',
                'group' => self::GROUP_IDENTITY,
                'type' => 'string',
                'table' => 'epc_ilmd',
                'column' => 'gtin14',
                'scopes' => ['epcs', 'documents'],
                'selective' => true,
            ],
            [
                'key' => 'epc.sscc18',
                'label' => 'SSCC',
                'group' => self::GROUP_IDENTITY,
                'type' => 'string',
                'table' => 'epcs',
                'column' => 'sscc18',
                'scopes' => ['epcs'],
                'selective' => true,
            ],
            [
                'key' => 'epc.serial_number',
                'label' => 'Serial',
                'group' => self::GROUP_IDENTITY,
                'type' => 'string',
                'table' => 'epcs',
                'column' => 'serial_number',
                'scopes' => ['epcs'],
                'selective' => true,
            ],
            [
                'key' => 'epc.ai_01_21',
                'label' => 'AI (01)+(21)',
                'group' => self::GROUP_IDENTITY,
                'type' => 'string',
                'table' => 'epcs',
                'column' => 'ai_01_21',
                'scopes' => ['epcs'],
                'selective' => true,
            ],
            [
                'key' => 'epc.ai_00',
                'label' => 'AI (00)',
                'group' => self::GROUP_IDENTITY,
                'type' => 'string',
                'table' => 'epcs',
                'column' => 'ai_00',
                'scopes' => ['epcs'],
                'selective' => true,
            ],
            [
                'key' => 'epc.epc_uri',
                'label' => 'EPC URI',
                'group' => self::GROUP_IDENTITY,
                'type' => 'string',
                'table' => 'epcs',
                'column' => 'epc_uri',
                'scopes' => ['epcs'],
                'selective' => true,
            ],
            [
                'key' => 'epc.epc_type',
                'label' => 'EPC type',
                'group' => self::GROUP_IDENTITY,
                'type' => 'enum',
                'table' => 'epcs',
                'column' => 'epc_type',
                'scopes' => ['epcs'],
                'selective' => false,
                'options' => [
                    'sgtin' => 'SGTIN',
                    'sscc' => 'SSCC',
                ],
            ],
            [
                'key' => 'ilmd.lot_number',
                'label' => 'Lot',
                'group' => self::GROUP_PRODUCT_LOT,
                'type' => 'string',
                'table' => 'epc_ilmd',
                'column' => 'lot_number',
                'scopes' => ['epcs', 'documents'],
                'selective' => true,
            ],
            [
                'key' => 'ilmd.expiry_date',
                'label' => 'Expiry',
                'group' => self::GROUP_PRODUCT_LOT,
                'type' => 'date',
                'table' => 'epc_ilmd',
                'column' => 'expiry_date',
                'scopes' => ['epcs'],
                'selective' => false,
            ],
            [
                'key' => 'doc.id',
                'label' => 'Document ID',
                'group' => self::GROUP_DOCUMENT,
                'type' => 'numeric',
                'table' => 'epcis_documents',
                'column' => 'id',
                'scopes' => ['epcs', 'documents'],
                'selective' => true,
            ],
            [
                'key' => 'doc.asn_or_po',
                'label' => 'ASN or PO',
                'group' => self::GROUP_SHIPPING_TI,
                'type' => 'string',
                'table' => 'epcis_documents',
                'column' => 'asn_number',
                'scopes' => ['epcs', 'documents'],
                'selective' => true,
            ],
            [
                'key' => 'doc.asn_number',
                'label' => 'ASN',
                'group' => self::GROUP_SHIPPING_TI,
                'type' => 'string',
                'table' => 'epcis_documents',
                'column' => 'asn_number',
                'scopes' => ['epcs', 'documents'],
                'selective' => true,
            ],
            [
                'key' => 'doc.customer_po',
                'label' => 'Customer PO',
                'group' => self::GROUP_SHIPPING_TI,
                'type' => 'string',
                'table' => 'epcis_documents',
                'column' => 'customer_po',
                'scopes' => ['epcs', 'documents'],
                'selective' => true,
            ],
            [
                'key' => 'doc.ship_from_gln',
                'label' => 'Ship-from GLN',
                'group' => self::GROUP_SHIPPING_TI,
                'type' => 'gln',
                'table' => 'epcis_documents',
                'column' => 'ship_from_gln',
                'scopes' => ['epcs', 'documents'],
                'selective' => false,
            ],
            [
                'key' => 'doc.ship_to_gln',
                'label' => 'Ship-to GLN',
                'group' => self::GROUP_SHIPPING_TI,
                'type' => 'gln',
                'table' => 'epcis_documents',
                'column' => 'ship_to_gln',
                'scopes' => ['epcs', 'documents'],
                'selective' => false,
            ],
            [
                'key' => 'doc.sender_gln',
                'label' => 'Sender GLN',
                'group' => self::GROUP_SHIPPING_TI,
                'type' => 'gln',
                'table' => 'epcis_documents',
                'column' => 'sender_gln',
                'scopes' => ['epcs', 'documents'],
                'selective' => false,
            ],
            [
                'key' => 'doc.receiver_gln',
                'label' => 'Receiver GLN',
                'group' => self::GROUP_SHIPPING_TI,
                'type' => 'gln',
                'table' => 'epcis_documents',
                'column' => 'receiver_gln',
                'scopes' => ['epcs', 'documents'],
                'selective' => false,
            ],
            [
                'key' => 'doc.trading_partner_id',
                'label' => 'Trading partner',
                'group' => self::GROUP_SHIPPING_TI,
                'type' => 'fk_partner',
                'table' => 'epcis_documents',
                'column' => 'trading_partner_id',
                'scopes' => ['epcs', 'documents'],
                'selective' => false,
            ],
            [
                'key' => 'bt.value',
                'label' => 'Biz transaction value',
                'group' => self::GROUP_SHIPPING_TI,
                'type' => 'string',
                'table' => 'event_biz_transactions',
                'column' => 'value',
                'scopes' => ['epcs', 'documents'],
                'selective' => true,
            ],
            [
                'key' => 'doc.status',
                'label' => 'Document status',
                'group' => self::GROUP_DOCUMENT,
                'type' => 'enum',
                'table' => 'epcis_documents',
                'column' => 'status',
                'scopes' => ['epcs', 'documents'],
                'selective' => false,
                'options' => [
                    'received' => 'Received',
                    'parsing' => 'Parsing',
                    'parsed' => 'Parsed',
                    'validated' => 'Validated',
                    'error' => 'Error',
                ],
            ],
            [
                'key' => 'doc.direction',
                'label' => 'Direction',
                'group' => self::GROUP_DOCUMENT,
                'type' => 'enum',
                'table' => 'epcis_documents',
                'column' => 'direction',
                'scopes' => ['epcs', 'documents'],
                'selective' => false,
                'options' => [
                    'inbound' => 'Inbound',
                    'outbound' => 'Outbound',
                ],
            ],
            [
                'key' => 'doc.dscsa_affirm',
                'label' => 'DSCSA TS affirm',
                'group' => self::GROUP_DOCUMENT,
                'type' => 'bool',
                'table' => 'epcis_documents',
                'column' => 'dscsa_affirm',
                'scopes' => ['epcs', 'documents'],
                'selective' => false,
            ],
            [
                'key' => 'doc.creation_date',
                'label' => 'Creation date',
                'group' => self::GROUP_DOCUMENT,
                'type' => 'date',
                'table' => 'epcis_documents',
                'column' => 'creation_date',
                'scopes' => ['epcs', 'documents'],
                'selective' => false,
            ],
            [
                'key' => 'doc.received_at',
                'label' => 'Received at',
                'group' => self::GROUP_DOCUMENT,
                'type' => 'date',
                'table' => 'epcis_documents',
                'column' => 'received_at',
                'scopes' => ['epcs', 'documents'],
                'selective' => false,
            ],
            [
                'key' => 'event.event_type',
                'label' => 'Event type',
                'group' => self::GROUP_EVENT,
                'type' => 'enum',
                'table' => 'epcis_events',
                'column' => 'event_type',
                'scopes' => ['epcs'],
                'selective' => false,
                'options' => [
                    'ObjectEvent' => 'ObjectEvent',
                    'AggregationEvent' => 'AggregationEvent',
                    'TransactionEvent' => 'TransactionEvent',
                    'TransformationEvent' => 'TransformationEvent',
                    'AssociationEvent' => 'AssociationEvent',
                ],
            ],
            [
                'key' => 'event.action',
                'label' => 'Event action',
                'group' => self::GROUP_EVENT,
                'type' => 'enum',
                'table' => 'epcis_events',
                'column' => 'action',
                'scopes' => ['epcs'],
                'selective' => false,
                'options' => [
                    'ADD' => 'ADD',
                    'OBSERVE' => 'OBSERVE',
                    'DELETE' => 'DELETE',
                ],
            ],
            [
                'key' => 'event.biz_step',
                'label' => 'Biz step',
                'group' => self::GROUP_EVENT,
                'type' => 'string',
                'table' => 'epcis_events',
                'column' => 'biz_step',
                'scopes' => ['epcs'],
                'selective' => false,
            ],
            [
                'key' => 'event.disposition',
                'label' => 'Disposition',
                'group' => self::GROUP_EVENT,
                'type' => 'string',
                'table' => 'epcis_events',
                'column' => 'disposition',
                'scopes' => ['epcs'],
                'selective' => false,
            ],
            [
                'key' => 'event.event_time',
                'label' => 'Event time',
                'group' => self::GROUP_EVENT,
                'type' => 'date',
                'table' => 'epcis_events',
                'column' => 'event_time',
                'scopes' => ['epcs'],
                'selective' => false,
            ],
        ];
    }

    /**
     * @param  array{type: string, key: string, ...}  $field
     * @return FieldDef
     */
    private static function withOperators(array $field): array
    {
        return array_merge($field, [
            'operators' => self::operatorsForType($field['type']),
        ]);
    }

    /**
     * @return list<FieldDef>
     */
    public function fieldsFor(string $resultType): array
    {
        return array_values(array_map(
            static fn (array $field): array => self::withOperators($field),
            array_filter(
                self::all(),
                static fn (array $field): bool => in_array($resultType, $field['scopes'], true),
            ),
        ));
    }

    /**
     * @return FieldDef|null
     */
    public function get(string $key): ?array
    {
        foreach (self::all() as $field) {
            if ($field['key'] === $key) {
                return self::withOperators($field);
            }
        }

        return null;
    }

    /**
     * Primary fields shown first in the Advanced field picker.
     *
     * @return list<string>
     */
    public function primaryFieldKeys(): array
    {
        return [
            'epc.gtin14',
            'ilmd.lot_number',
            'epc.sscc18',
            'epc.serial_number',
            'doc.asn_or_po',
            'doc.asn_number',
            'doc.customer_po',
        ];
    }

    /**
     * Optgroups for Filament/HTML Select: group label => [key => label].
     *
     * @param  list<string>  $alwaysIncludeKeys  Keys to keep when $primaryOnly is true (e.g. already selected).
     * @return array<string, array<string, string>>
     */
    public function groupedOptions(string $resultType, bool $primaryOnly = false, array $alwaysIncludeKeys = []): array
    {
        $primaryKeys = $this->primaryFieldKeys();
        $grouped = [];

        foreach ($this->fieldsFor($resultType) as $field) {
            if (
                $primaryOnly
                && ! in_array($field['key'], $primaryKeys, true)
                && ! in_array($field['key'], $alwaysIncludeKeys, true)
            ) {
                continue;
            }

            $grouped[$field['group']][$field['key']] = $field['label'];
        }

        return $grouped;
    }

    /**
     * @return list<string>
     */
    public function operatorsFor(string $key): array
    {
        $field = $this->get($key);

        if ($field === null) {
            return [];
        }

        return self::operatorsForType($field['type']);
    }

    public function isSelective(string $key): bool
    {
        $field = $this->get($key);

        return (bool) ($field['selective'] ?? false);
    }

    /**
     * @return list<string>
     */
    public function selectiveKeys(): array
    {
        return array_values(array_map(
            static fn (array $field): string => $field['key'],
            array_filter(self::all(), static fn (array $field): bool => $field['selective']),
        ));
    }
}
