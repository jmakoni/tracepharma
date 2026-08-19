<?php

namespace App\Filament\App\Resources\SsccLabels\Schemas;

use App\Enums\SsccAllocationMode;
use App\Enums\SsccReshipMode;
use App\Models\Epcis\EpcisDocument;
use App\Models\LabelPrinter;
use App\Models\TradingPartner;
use App\Support\Auth\CurrentSite;
use App\Support\Gs1\GlnRules;
use App\Support\Labeling\BreakPalletHierarchyOptions;
use App\Support\Receiving\EligibleReceiveSites;
use App\Support\TenantSettings;
use App\Support\TenantSsccSettings;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get as SchemaGet;

class BreakPalletForm
{
    /**
     * @return list<Component|\Filament\Forms\Components\Component>
     */
    public static function components(): array
    {
        return [
            Section::make('Source pallet')
                ->compact()
                ->description('Select an inbound shipment and pallet SSCC, then choose children to move onto new outbound labels. No shipping event is created here.')
                ->schema([
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
                        ->required()
                        ->live(),
                    Select::make('source_parent_sscc_urn')
                        ->label('Source pallet SSCC')
                        ->options(function (SchemaGet $get): array {
                            $documentId = (int) ($get('source_epcis_document_id') ?? 0);

                            return $documentId > 0
                                ? BreakPalletHierarchyOptions::parentSsccOptions($documentId)
                                : [];
                        })
                        ->searchable()
                        ->required()
                        ->live()
                        ->helperText('Open SSCC parents from this document’s aggregation links.'),
                    CheckboxList::make('selected_child_epcs')
                        ->label('Children to re-label')
                        ->options(function (SchemaGet $get): array {
                            $documentId = (int) ($get('source_epcis_document_id') ?? 0);
                            $parent = (string) ($get('source_parent_sscc_urn') ?? '');

                            return $documentId > 0 && $parent !== ''
                                ? BreakPalletHierarchyOptions::childEpcOptions($documentId, $parent)
                                : [];
                        })
                        ->columns(1)
                        ->helperText('Select from hierarchy and/or paste URNs below. At least one child is required.')
                        ->columnSpanFull(),
                    Textarea::make('selected_child_epcs_manual')
                        ->label('Or paste child EPC URNs')
                        ->rows(3)
                        ->placeholder("urn:epc:id:sgtin:…\nurn:epc:id:sscc:…")
                        ->helperText('Only for children that are already open links under the selected parent — pasting arbitrary EPCs is rejected. Use this when the hierarchy list above is empty or truncated.')
                        ->columnSpanFull(),
                ])
                ->columns(2),
            Section::make('Outbound labels')
                ->compact()
                ->schema([
                    Select::make('reship_mode')
                        ->label('Reship mode')
                        ->options(collect(SsccReshipMode::cases())->mapWithKeys(
                            fn (SsccReshipMode $mode): array => [$mode->value => $mode->label()]
                        ))
                        ->default(SsccReshipMode::PerChild->value)
                        ->required()
                        ->live()
                        ->helperText(fn (?string $state): string => (SsccReshipMode::tryFrom((string) $state)?->description() ?? '')
                            .(SsccReshipMode::tryFrom((string) $state) === SsccReshipMode::Combined
                                ? ' Combined mode always creates one label for all selected children.'
                                : '')),
                    Placeholder::make('company_prefix_display')
                        ->label('GS1 Company Prefix')
                        ->content(fn (): string => TenantSsccSettings::resolve()['company_prefix'] ?? 'Configure in Organization settings'),
                    Select::make('allocation_mode')
                        ->label('Allocation strategy')
                        ->options(collect(SsccAllocationMode::cases())->mapWithKeys(
                            fn (SsccAllocationMode $mode): array => [$mode->value => $mode->label()]
                        ))
                        ->default(SsccAllocationMode::Sequential->value)
                        ->required(),
                    TextInput::make('copies_per_label')
                        ->label('Copies per label')
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(10)
                        ->default(1)
                        ->required(),
                    Toggle::make('enforce_forward_only')
                        ->label('Require serials greater than last generated')
                        ->default(true),
                ])
                ->columns(2),
            Section::make('Label ship-to (printed on label only)')
                ->compact()
                ->description('Printed on the SSCC labels for handling. This does not create or schedule an outbound shipment.')
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
                ])
                ->columns(2),
            Section::make('Aggregation (EPCIS)')
                ->compact()
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
                        ->native(false),
                    Toggle::make('emit_disaggregation')
                        ->label('Emit disaggregation for source pallet')
                        ->default(true)
                        ->dehydrated(true)
                        ->disabled(fn (SchemaGet $get): bool => filled($get('source_parent_sscc_urn')))
                        ->helperText('Sends DELETE aggregation events when breaking an inbound pallet. Required — and locked on — once a source pallet is selected.'),
                    Toggle::make('emit_epcis')
                        ->label('Emit aggregation EPCIS after generation')
                        ->default(true)
                        ->live()
                        ->dehydrated(true)
                        ->disabled(fn (SchemaGet $get): bool => filled($get('source_parent_sscc_urn')))
                        ->helperText('Packing AggregationEvent for the new outbound SSCCs. Required — and locked on — once a source pallet is selected.'),
                    Select::make('trading_partner_id')
                        ->label('Outbound trading partner')
                        ->options(fn (): array => TradingPartner::query()
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->searchable()
                        ->nullable()
                        ->visible(fn (SchemaGet $get): bool => (bool) $get('emit_epcis') || (bool) $get('emit_disaggregation')),
                ]),
            Section::make('Printing')
                ->compact()
                ->schema([
                    Toggle::make('send_to_printer')
                        ->label('Send to network printer after successful commission')
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
        ];
    }

    /**
     * Merge checkbox + manual textarea into selected_child_epcs for BreakPalletAndReship.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalizeInput(array $data): array
    {
        $selected = array_values(array_filter(array_map(
            'trim',
            (array) ($data['selected_child_epcs'] ?? []),
        )));

        $manual = preg_split('/\R/', (string) ($data['selected_child_epcs_manual'] ?? '')) ?: [];
        $manual = array_values(array_filter(array_map('trim', $manual)));

        $data['selected_child_epcs'] = array_values(array_unique([...$selected, ...$manual]));
        unset($data['selected_child_epcs_manual']);

        if (($data['reship_mode'] ?? '') === SsccReshipMode::Combined->value) {
            $data['label_count'] = 1;
        }

        // Breaking a source pallet is only traceable when both halves of the hierarchy
        // change are authored — disabled toggles must never dehydrate as false.
        if (filled($data['source_parent_sscc_urn'] ?? null)) {
            $data['emit_disaggregation'] = true;
            $data['emit_epcis'] = true;
        }

        return $data;
    }
}
