<?php

namespace App\Filament\App\Resources\SsccLabels\Schemas;

use App\Enums\SsccAllocationMode;
use App\Enums\SsccNumberRangeScope;
use App\Models\Epcis\EpcisDocument;
use App\Models\LabelPrinter;
use App\Models\SsccNumberRange;
use App\Models\TradingPartner;
use App\Services\Labeling\SsccBuilder;
use App\Support\Auth\CurrentSite;
use App\Support\Gs1\GlnRules;
use App\Support\Receiving\EligibleReceiveSites;
use App\Support\TenantSettings;
use App\Support\TenantSsccSettings;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get as SchemaGet;
use Filament\Schemas\Schema;

class SsccLabelForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Serial allocation')
                    ->compact()
                    ->description('Choose how pallet serial references are assigned.')
                    ->schema([
                        Placeholder::make('company_prefix_display')
                            ->label('GS1 Company Prefix')
                            ->content(fn (): string => TenantSsccSettings::resolve()['company_prefix'] ?? 'Configure in Organization settings'),
                        Placeholder::make('last_serial_reference')
                            ->label('Last generated / printed serial')
                            ->content(function (): string {
                                $settings = TenantSsccSettings::resolve();
                                $generated = $settings['last_serial_reference_int'];
                                $printed = $settings['last_printed_serial_reference_int'];

                                $generatedText = $generated !== null ? (string) $generated : 'None yet';
                                $printedText = $printed !== null ? (string) $printed : 'None yet';

                                return "Generated: {$generatedText} · Printed: {$printedText}";
                            }),
                        Select::make('allocation_mode')
                            ->label('Allocation strategy')
                            ->options(collect(SsccAllocationMode::cases())->mapWithKeys(
                                fn (SsccAllocationMode $mode): array => [$mode->value => $mode->label()]
                            ))
                            ->default(SsccAllocationMode::Sequential->value)
                            ->required()
                            ->live()
                            ->helperText(fn (?string $state): string => SsccAllocationMode::tryFrom((string) $state)?->description() ?? ''),
                        TextInput::make('label_count')
                            ->label('Number of labels')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue((int) config('sscc.max_batch_size', 50))
                            ->default(1)
                            ->required(),
                        TextInput::make('copies_per_label')
                            ->label('Copies per label')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(10)
                            ->default(1)
                            ->required()
                            ->helperText('Physical copies to print for each generated SSCC.'),
                        Toggle::make('enforce_forward_only')
                            ->label('Require serials greater than last generated')
                            ->default(true),
                    ])
                    ->columns(2),
                Section::make('Range options')
                    ->compact()
                    ->visible(fn (SchemaGet $get): bool => $get('allocation_mode') === SsccAllocationMode::Range->value)
                    ->schema([
                        TextInput::make('range_start')
                            ->label('Range start')
                            ->numeric()
                            ->minValue(0)
                            ->required(),
                        TextInput::make('range_end')
                            ->label('Range end (optional)')
                            ->numeric()
                            ->minValue(0)
                            ->helperText('Leave blank to derive end from label count.'),
                    ])
                    ->columns(2),
                Section::make('Partial random options')
                    ->compact()
                    ->visible(fn (SchemaGet $get): bool => $get('allocation_mode') === SsccAllocationMode::PartialRandom->value)
                    ->schema([
                        TextInput::make('fixed_prefix')
                            ->label('Fixed digit prefix')
                            ->numeric()
                            ->required()
                            ->helperText('Leading digits stay constant; remaining digits are randomized.'),
                    ]),
                Section::make('Fully random options')
                    ->compact()
                    ->visible(fn (SchemaGet $get): bool => $get('allocation_mode') === SsccAllocationMode::FullyRandom->value)
                    ->schema([
                        TextInput::make('random_floor')
                            ->label('Minimum serial (optional)')
                            ->numeric()
                            ->minValue(0)
                            ->helperText('Defaults to last generated serial + 1 when forward-only is enabled.'),
                        TextInput::make('random_ceiling')
                            ->label('Maximum serial (optional)')
                            ->numeric()
                            ->minValue(0)
                            ->helperText(function (): string {
                                $companyPrefix = (string) (TenantSsccSettings::resolve()['company_prefix'] ?? '');

                                if (strlen($companyPrefix) < 6) {
                                    return 'Defaults to the GS1 maximum for your prefix.';
                                }

                                $max = app(SsccBuilder::class)->maxSerialReferenceForPrefix($companyPrefix);

                                return "Defaults to {$max} for this company prefix.";
                            }),
                    ])
                    ->columns(2),
                Section::make('Shipment details')
                    ->compact()
                    ->schema([
                        TextInput::make('ship_to_name')
                            ->label('Ship to name')
                            ->maxLength(255),
                        GlnRules::input('ship_to_gln', 'Ship to GLN'),
                        Textarea::make('notes')
                            ->label('Notes')
                            ->rows(3)
                            ->maxLength(1000)
                            ->columnSpanFull(),
                        Select::make('source_epcis_document_id')
                            ->label('Source EPCIS document')
                            ->options(fn (): array => EpcisDocument::query()
                                ->orderByDesc('id')
                                ->limit(100)
                                ->get(['id', 'original_filename', 'asn_number', 'created_at'])
                                ->mapWithKeys(fn (EpcisDocument $document): array => [
                                    $document->id => '#'.$document->id.' · '.($document->asn_number ?: $document->original_filename ?: $document->created_at?->toDateTimeString() ?? 'Document'),
                                ])
                                ->all())
                            ->searchable()
                            ->nullable(),
                    ])
                    ->columns(2),
                Section::make('Aggregation (EPCIS)')
                    ->compact()
                    ->description('Generated SSCCs are commissioned automatically at the selected Commission site; print runs after commission. Optionally attach child EPCs and emit packing aggregation.')
                    ->schema([
                        Select::make('site_id')
                            ->label('Commission site')
                            ->options(fn (): array => EligibleReceiveSites::organizationOptions())
                            ->default(function (): ?int {
                                $settings = TenantSettings::forTenant(tenant());
                                $candidate = $settings->defaultShipFromSiteId()
                                    ?? $settings->defaultReceiveSiteId();

                                $fallback = null;
                                if ($candidate !== null && EligibleReceiveSites::forOrganization()->whereKey($candidate)->exists()) {
                                    $fallback = $candidate;
                                } else {
                                    $first = EligibleReceiveSites::forOrganization()->value('id');
                                    $fallback = $first !== null ? (int) $first : null;
                                }

                                return CurrentSite::preferredId(
                                    $fallback,
                                    EligibleReceiveSites::organizationOptions(),
                                );
                            })
                            ->required()
                            ->searchable()
                            ->native(false)
                            ->helperText('Used as commissioning readPoint/bizLocation. Defaults to the site chooser’s current site when valid.'),
                        Textarea::make('child_epcs')
                            ->label('Child EPC URNs')
                            ->rows(4)
                            ->placeholder("urn:epc:id:sgtin:030116.5200116.00000000413101\nurn:epc:id:sgtin:030116.5200116.00000000413104")
                            ->columnSpanFull(),
                        Toggle::make('emit_epcis')
                            ->label('Emit aggregation EPCIS after generation')
                            ->live()
                            ->helperText('Packing AggregationEvent only (commissioning already runs automatically).'),
                        Toggle::make('emit_disaggregation')
                            ->label('Emit disaggregation for source pallet')
                            ->helperText('Sends DELETE aggregation events when breaking an inbound pallet.'),
                        Select::make('trading_partner_id')
                            ->label('Trading partner')
                            ->options(fn (): array => TradingPartner::query()
                                ->where('is_active', true)
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->nullable()
                            ->helperText('Required for partner-scoped SSCC number ranges; also used when emitting EPCIS.')
                            ->visible(fn (SchemaGet $get): bool => (bool) $get('emit_epcis')
                                || SsccNumberRange::query()
                                    ->activeSelectable()
                                    ->where('scope', SsccNumberRangeScope::Partner)
                                    ->exists()),
                    ]),
                Section::make('Printing')
                    ->compact()
                    ->schema([
                        Toggle::make('send_to_printer')
                            ->label('Send to network printer after successful commission')
                            ->helperText('Print runs only after commissioning succeeds at the selected Commission site.')
                            ->live(),
                        Select::make('label_printer_id')
                            ->label('Label printer')
                            ->options(fn (): array => LabelPrinter::query()
                                ->where('enabled', true)
                                ->orderByDesc('is_default')
                                ->orderBy('name')
                                ->get()
                                ->mapWithKeys(fn (LabelPrinter $printer): array => [$printer->id => $printer->displayName()])
                                ->all())
                            ->searchable()
                            ->nullable()
                            ->visible(fn (SchemaGet $get): bool => (bool) $get('send_to_printer'))
                            ->required(fn (SchemaGet $get): bool => (bool) $get('send_to_printer')),
                    ])
                    ->columns(2),
            ]);
    }
}
