<?php

namespace App\Filament\App\Support;

use App\Models\TradingPartner;
use App\Support\Epcis\EpcisQueryFieldRegistry;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

/**
 * Shared Find / Recall query-builder form for EPCIS schema search.
 */
final class EpcisSchemaSearchForm
{
    /**
     * @return array<int, mixed>
     */
    public static function schema(): array
    {
        return [
            Toggle::make('advanced')
                ->label('Advanced search')
                ->helperText('Simple mode: GTIN + lot and/or ASN/PO. Advanced unlocks the full condition builder.')
                ->default(false)
                ->live()
                ->afterStateUpdated(function (bool $state, Get $get, Set $set): void {
                    if (! $state) {
                        return;
                    }

                    // Advanced on the inbound list defaults to document results (filter the file list).
                    if (blank($get('result_type')) || $get('result_type') === 'epcs') {
                        $set('result_type', 'documents');
                        $set('rules', self::defaultRules('documents'));
                        $set('preset', null);
                    }
                }),

            Group::make([
                TextInput::make('gtin14')
                    ->label('GTIN')
                    ->helperText('14 digits; dashes OK. Shipment IDs with letters belong in ASN or PO.')
                    ->maxLength(32),
                TextInput::make('lot_number')
                    ->label('Lot')
                    ->maxLength(128),
                TextInput::make('asn_or_po')
                    ->label('ASN or PO')
                    ->helperText('Shipment / purchase-order reference (e.g. C7174125NLC)')
                    ->maxLength(128)
                    ->columnSpanFull(),
            ])
                ->columns(2)
                ->visible(fn (Get $get): bool => ! (bool) $get('advanced')),

            Group::make([
                Placeholder::make('preset_hint')
                    ->label('')
                    ->content('Start with one condition. Add more rows joined with AND or OR.'),

                Select::make('preset')
                    ->label('Preset')
                    ->placeholder('Choose a starter…')
                    ->options([
                        'recall' => 'Recall (GTIN + lot)',
                        'sscc' => 'By SSCC',
                        'asn' => 'By ASN or PO',
                        'po' => 'By PO only',
                    ])
                    ->native(false)
                    ->live()
                    ->dehydrated(false)
                    ->afterStateUpdated(function (?string $state, Set $set): void {
                        if (blank($state)) {
                            return;
                        }

                        $preset = self::rulesForPreset($state);
                        $set('result_type', $preset['result_type']);
                        $set('rules', $preset['rules']);
                    }),

                Select::make('result_type')
                    ->label('Result type')
                    ->options([
                        'epcs' => 'Serialized units (EPCs)',
                        'documents' => 'EPCIS documents (shipments / TI)',
                    ])
                    ->default('documents')
                    ->required()
                    ->native(false)
                    ->live()
                    ->afterStateUpdated(function (?string $state, Set $set): void {
                        $resultType = filled($state) ? $state : 'documents';
                        $set('rules', self::defaultRules($resultType));
                        $set('preset', null);
                    }),

                Toggle::make('more_fields')
                    ->label('More fields')
                    ->helperText('Show all searchable fields (GLNs, events, document status, etc.).')
                    ->default(false)
                    ->live(),

                Repeater::make('rules')
                    ->label('Conditions')
                    ->helperText('Join extra rows with AND or OR. OR groups consecutive lots/values: GTIN AND lotA OR lotB.')
                    ->minItems(1)
                    ->maxItems(8)
                    ->defaultItems(1)
                    ->default(self::defaultRules('documents'))
                    ->addActionLabel('Add condition')
                    ->reorderable(false)
                    ->columns(12)
                    ->schema([
                        Select::make('boolean')
                            ->label('Join')
                            ->options([
                                'and' => 'AND',
                                'or' => 'OR',
                            ])
                            ->default('and')
                            ->native(false)
                            ->selectablePlaceholder(false)
                            ->columnSpan(2)
                            ->visible(fn (Get $get, Component $component): bool => self::isNotFirstRule($get, $component))
                            ->dehydrated(fn (Get $get, Component $component): bool => self::isNotFirstRule($get, $component)),

                        Select::make('field')
                            ->label('Field')
                            ->options(function (Get $get): array {
                                $resultType = (string) ($get('../../result_type') ?: 'epcs');
                                $moreFields = (bool) $get('../../more_fields');
                                $selected = $get('field');
                                $alwaysInclude = filled($selected) ? [(string) $selected] : [];

                                return self::registry()->groupedOptions(
                                    $resultType,
                                    primaryOnly: ! $moreFields,
                                    alwaysIncludeKeys: $alwaysInclude,
                                );
                            })
                            ->required()
                            ->searchable()
                            ->native(false)
                            ->live()
                            ->columnSpan(fn (Get $get, Component $component): int => self::isNotFirstRule($get, $component) ? 3 : 4)
                            ->afterStateUpdated(function (Set $set): void {
                                $set('operator', null);
                                $set('value', null);
                                $set('value_to', null);
                            }),

                        Select::make('operator')
                            ->label('Condition')
                            ->options(function (Get $get): array {
                                $field = $get('field');
                                if (blank($field)) {
                                    return [];
                                }

                                $ops = self::registry()->operatorsFor((string) $field);

                                return collect($ops)
                                    ->mapWithKeys(fn (string $op): array => [$op => self::humanOperator($op)])
                                    ->all();
                            })
                            ->required()
                            ->native(false)
                            ->live()
                            ->columnSpan(3)
                            ->afterStateUpdated(function (?string $state, Set $set): void {
                                if (! in_array($state, ['between', 'not_between'], true)) {
                                    $set('value_to', null);
                                }
                            }),

                        TextInput::make('value')
                            ->label('Value')
                            ->columnSpan(fn (Get $get): int => self::isBetween($get) ? 2 : 5)
                            ->visible(fn (Get $get): bool => self::valueUsesText($get))
                            ->dehydrated(fn (Get $get): bool => self::valueUsesText($get))
                            ->required(fn (Get $get): bool => self::valueUsesText($get)),

                        TextInput::make('value')
                            ->label(fn (Get $get): string => self::isBetween($get) ? 'From' : 'Value')
                            ->numeric()
                            ->columnSpan(fn (Get $get): int => self::isBetween($get) ? 2 : 5)
                            ->visible(fn (Get $get): bool => self::valueUsesNumeric($get))
                            ->dehydrated(fn (Get $get): bool => self::valueUsesNumeric($get))
                            ->required(fn (Get $get): bool => self::valueUsesNumeric($get)),

                        TextInput::make('value_to')
                            ->label('To')
                            ->numeric()
                            ->columnSpan(2)
                            ->visible(fn (Get $get): bool => self::valueUsesNumeric($get) && self::isBetween($get))
                            ->dehydrated(fn (Get $get): bool => self::valueUsesNumeric($get) && self::isBetween($get))
                            ->required(fn (Get $get): bool => self::valueUsesNumeric($get) && self::isBetween($get)),

                        DatePicker::make('value')
                            ->label(fn (Get $get): string => self::isBetween($get) ? 'From' : 'Value')
                            ->native(false)
                            ->format('Y-m-d')
                            ->displayFormat('M j, Y')
                            ->columnSpan(fn (Get $get): int => self::isBetween($get) ? 2 : 5)
                            ->visible(fn (Get $get): bool => self::valueUsesDate($get))
                            ->dehydrated(fn (Get $get): bool => self::valueUsesDate($get))
                            ->required(fn (Get $get): bool => self::valueUsesDate($get)),

                        DatePicker::make('value_to')
                            ->label('To')
                            ->native(false)
                            ->format('Y-m-d')
                            ->displayFormat('M j, Y')
                            ->columnSpan(2)
                            ->visible(fn (Get $get): bool => self::valueUsesDate($get) && self::isBetween($get))
                            ->dehydrated(fn (Get $get): bool => self::valueUsesDate($get) && self::isBetween($get))
                            ->required(fn (Get $get): bool => self::valueUsesDate($get) && self::isBetween($get)),

                        Select::make('value')
                            ->label('Value')
                            ->options(fn (Get $get): array => self::enumOptionsForField($get('field')))
                            ->native(false)
                            ->searchable()
                            ->columnSpan(5)
                            ->visible(fn (Get $get): bool => self::valueUsesEnumSingle($get))
                            ->dehydrated(fn (Get $get): bool => self::valueUsesEnumSingle($get))
                            ->required(fn (Get $get): bool => self::valueUsesEnumSingle($get)),

                        Select::make('value')
                            ->label('Value')
                            ->options(fn (Get $get): array => self::enumOptionsForField($get('field')))
                            ->native(false)
                            ->searchable()
                            ->multiple()
                            ->columnSpan(5)
                            ->visible(fn (Get $get): bool => self::valueUsesEnumMulti($get))
                            ->dehydrated(fn (Get $get): bool => self::valueUsesEnumMulti($get))
                            ->required(fn (Get $get): bool => self::valueUsesEnumMulti($get)),

                        Select::make('value')
                            ->label('Partner')
                            ->options(fn (): array => TradingPartner::query()
                                ->where('is_active', true)
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->native(false)
                            ->searchable()
                            ->columnSpan(5)
                            ->visible(fn (Get $get): bool => self::valueUsesPartnerSingle($get))
                            ->dehydrated(fn (Get $get): bool => self::valueUsesPartnerSingle($get))
                            ->required(fn (Get $get): bool => self::valueUsesPartnerSingle($get)),

                        Select::make('value')
                            ->label('Partner')
                            ->options(fn (): array => TradingPartner::query()
                                ->where('is_active', true)
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->native(false)
                            ->searchable()
                            ->multiple()
                            ->columnSpan(5)
                            ->visible(fn (Get $get): bool => self::valueUsesPartnerMulti($get))
                            ->dehydrated(fn (Get $get): bool => self::valueUsesPartnerMulti($get))
                            ->required(fn (Get $get): bool => self::valueUsesPartnerMulti($get)),
                    ]),
            ])
                ->visible(fn (Get $get): bool => (bool) $get('advanced')),
        ];
    }

    /**
     * @return list<array{field: string, operator: string, value: mixed, value_to?: mixed, boolean?: string}>
     */
    public static function defaultRules(string $resultType = 'epcs'): array
    {
        return match ($resultType) {
            'documents' => [
                [
                    'field' => 'doc.asn_number',
                    'operator' => 'eq',
                    'value' => null,
                ],
            ],
            default => [
                [
                    'field' => 'epc.gtin14',
                    'operator' => 'eq',
                    'value' => null,
                ],
            ],
        };
    }

    /**
     * @return array{result_type: string, rules: list<array{field: string, operator: string, value: mixed, value_to?: mixed, boolean?: string}>}
     */
    public static function rulesForPreset(string $preset): array
    {
        return match ($preset) {
            'sscc' => [
                'result_type' => 'epcs',
                'rules' => [
                    [
                        'field' => 'epc.sscc18',
                        'operator' => 'eq',
                        'value' => null,
                    ],
                ],
            ],
            'asn' => [
                'result_type' => 'documents',
                'rules' => [
                    [
                        'field' => 'doc.asn_or_po',
                        'operator' => 'eq',
                        'value' => null,
                    ],
                ],
            ],
            'po' => [
                'result_type' => 'documents',
                'rules' => [
                    [
                        'field' => 'doc.customer_po',
                        'operator' => 'eq',
                        'value' => null,
                    ],
                ],
            ],
            default => [
                'result_type' => 'epcs',
                'rules' => [
                    [
                        'field' => 'epc.gtin14',
                        'operator' => 'eq',
                        'value' => null,
                    ],
                    [
                        'boolean' => 'and',
                        'field' => 'ilmd.lot_number',
                        'operator' => 'eq',
                        'value' => null,
                    ],
                ],
            ],
        };
    }

    /**
     * Build SearchEpcisSchema rules from dehydrated form state (simple or advanced).
     *
     * @param  array<string, mixed>  $data
     * @return array{result_type: string, rules: list<array{field: string, operator: string, value: mixed, value_to?: mixed, boolean?: string}>}
     */
    public static function searchPayloadFromForm(array $data): array
    {
        if (! (bool) ($data['advanced'] ?? false)) {
            return self::simpleSearchPayload($data);
        }

        $rules = is_array($data['rules'] ?? null) ? $data['rules'] : [];

        // Legacy "By ASN" sessions searched asn_number only; PO refs like C7174125NLC missed.
        if (count($rules) === 1 && ($rules[0]['field'] ?? null) === 'doc.asn_number') {
            $rules[0]['field'] = 'doc.asn_or_po';
        }

        $resultType = (string) ($data['result_type'] ?? 'documents');
        if ($resultType === 'epcs' && self::shouldCoerceEpcSearchToDocuments($rules)) {
            $resultType = 'documents';
        }

        return [
            'result_type' => $resultType,
            'rules' => $rules,
        ];
    }

    /**
     * Document-only advanced filters (e.g. receiver GLN) must not hit the EPC selective gate.
     *
     * @param  list<array{field?: string}>  $rules
     */
    private static function shouldCoerceEpcSearchToDocuments(array $rules): bool
    {
        if ($rules === []) {
            return false;
        }

        $registry = new EpcisQueryFieldRegistry;
        $hasSelective = false;
        $allDocumentScoped = true;

        foreach ($rules as $rule) {
            if (! is_array($rule)) {
                continue;
            }

            $fieldKey = (string) ($rule['field'] ?? '');
            if ($fieldKey === '') {
                continue;
            }

            $def = $registry->get($fieldKey);
            if ($def === null || ! in_array('documents', $def['scopes'], true)) {
                $allDocumentScoped = false;
            }

            if ($registry->isSelective($fieldKey)) {
                $hasSelective = true;
            }
        }

        return $allDocumentScoped && ! $hasSelective;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{result_type: string, rules: list<array{field: string, operator: string, value: mixed, value_to?: mixed, boolean?: string}>}
     */
    private static function simpleSearchPayload(array $data): array
    {
        $rules = [];
        $gtin = trim((string) ($data['gtin14'] ?? ''));
        $lot = trim((string) ($data['lot_number'] ?? ''));
        $asnOrPo = trim((string) ($data['asn_or_po'] ?? ''));

        // Pasting a shipment ref into GTIN (letters) must not digit-strip into a bogus GTIN search.
        if ($asnOrPo === '' && $gtin !== '' && self::looksLikeShipmentReference($gtin)) {
            $asnOrPo = $gtin;
            $gtin = '';
        }

        if ($asnOrPo !== '') {
            $rules[] = [
                'field' => 'doc.asn_or_po',
                'operator' => 'eq',
                'value' => $asnOrPo,
            ];
        }

        if ($gtin !== '') {
            $rules[] = [
                'field' => 'epc.gtin14',
                'operator' => 'eq',
                'value' => $gtin,
                'boolean' => 'and',
            ];
        }

        if ($lot !== '') {
            $rules[] = [
                'field' => 'ilmd.lot_number',
                'operator' => 'eq',
                'value' => $lot,
                'boolean' => 'and',
            ];
        }

        // Simple Find / Recall returns inbound EPCIS files. Use View units for serials.
        return [
            'result_type' => 'documents',
            'rules' => $rules,
        ];
    }

    private static function looksLikeShipmentReference(string $value): bool
    {
        return (bool) preg_match('/[A-Za-z]/', $value);
    }

    public static function humanOperator(string $op): string
    {
        return match ($op) {
            'eq' => 'Equals',
            'neq' => 'Does Not Equal',
            'contains' => 'Contains',
            'not_contains' => 'Does Not Contain',
            'starts_with' => 'Starts With',
            'ends_with' => 'Ends With',
            'is_empty' => 'Is Empty',
            'is_not_empty' => 'Is Not Empty',
            'gt' => 'Greater Than',
            'gte' => 'Greater Than or Equal To',
            'lt' => 'Less Than',
            'lte' => 'Less Than or Equal To',
            'between' => 'Between',
            'not_between' => 'Not Between',
            'before' => 'Before',
            'before_or_equal' => 'Before or Equal To',
            'after' => 'After',
            'after_or_equal' => 'After or Equal To',
            'is_today' => 'Is Today',
            'is_yesterday' => 'Is Yesterday',
            'is_this_week' => 'Is This Week',
            'is_this_month' => 'Is This Month',
            'is_any_of' => 'Is Any Of',
            'is_not_any_of' => 'Is Not Any Of',
            'is_true' => 'Is True',
            'is_false' => 'Is False',
            default => str($op)->replace('_', ' ')->title()->toString(),
        };
    }

    private static function registry(): EpcisQueryFieldRegistry
    {
        return app(EpcisQueryFieldRegistry::class);
    }

    private static function isNotFirstRule(Get $get, Component $component): bool
    {
        $rules = $get('../../rules');
        if (! is_array($rules) || count($rules) < 2) {
            return false;
        }

        $itemKey = (string) str($component->getContainer()->getStatePath())->afterLast('.');

        return array_key_first($rules) !== $itemKey;
    }

    private static function fieldType(mixed $field): ?string
    {
        if (blank($field)) {
            return null;
        }

        return self::registry()->get((string) $field)['type'] ?? null;
    }

    /**
     * @return array<string, string>
     */
    private static function enumOptionsForField(mixed $field): array
    {
        if (blank($field)) {
            return [];
        }

        $meta = self::registry()->get((string) $field) ?? [];

        /** @var array<string, string> */
        return $meta['options'] ?? [];
    }

    private static function operatorNeedsNoValue(Get $get): bool
    {
        return in_array($get('operator'), [
            'is_empty',
            'is_not_empty',
            'is_today',
            'is_yesterday',
            'is_this_week',
            'is_this_month',
            'is_true',
            'is_false',
        ], true);
    }

    private static function isBetween(Get $get): bool
    {
        return in_array($get('operator'), ['between', 'not_between'], true);
    }

    private static function isMultiValue(Get $get): bool
    {
        return in_array($get('operator'), ['is_any_of', 'is_not_any_of'], true);
    }

    private static function valueUsesText(Get $get): bool
    {
        return in_array(self::fieldType($get('field')), ['string', 'gln'], true)
            && ! self::operatorNeedsNoValue($get)
            && ! self::isMultiValue($get);
    }

    private static function valueUsesDate(Get $get): bool
    {
        return self::fieldType($get('field')) === 'date'
            && ! self::operatorNeedsNoValue($get);
    }

    private static function valueUsesNumeric(Get $get): bool
    {
        return self::fieldType($get('field')) === 'numeric'
            && ! self::operatorNeedsNoValue($get);
    }

    private static function valueUsesEnumSingle(Get $get): bool
    {
        return self::fieldType($get('field')) === 'enum'
            && in_array($get('operator'), ['eq', 'neq'], true);
    }

    private static function valueUsesEnumMulti(Get $get): bool
    {
        return self::fieldType($get('field')) === 'enum'
            && self::isMultiValue($get);
    }

    private static function valueUsesPartnerSingle(Get $get): bool
    {
        return self::fieldType($get('field')) === 'fk_partner'
            && in_array($get('operator'), ['eq', 'neq'], true);
    }

    private static function valueUsesPartnerMulti(Get $get): bool
    {
        return self::fieldType($get('field')) === 'fk_partner'
            && self::isMultiValue($get);
    }
}
