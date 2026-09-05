<?php

namespace App\Filament\App\Resources\Products\Schemas;

use App\Enums\AuthorizationStatus;
use App\Models\Fda\FdaProduct;
use App\Support\Catalog\DisplayName;
use App\Support\Fda\FdaRegistryStatus;
use App\Support\Gs1\Ndc;
use App\Support\MasterData\ProductComplianceStatus;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;

class ProductInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Identity')
                ->compact()
                ->columns(['md' => 2])
                ->schema([
                    TextEntry::make('name')
                        ->formatStateUsing(fn (?string $state): ?string => DisplayName::clean($state)),
                    TextEntry::make('package_ndc')
                        ->label('NDC')
                        ->formatStateUsing(fn ($state, $record): ?string => Ndc::formatDisplay($record->package_ndc ?? $record->ndc11 ?? $record->ndc))
                        ->copyable()
                        ->fontFamily(FontFamily::Mono),
                    TextEntry::make('gtin')
                        ->label('GTIN')
                        ->copyable()
                        ->fontFamily(FontFamily::Mono)
                        ->placeholder('—'),
                    TextEntry::make('strength')->placeholder('—'),
                    TextEntry::make('dosage_form')
                        ->label('Dosage form')
                        ->placeholder('—'),
                    TextEntry::make('tradingPartner.name')
                        ->label('Manufacturer')
                        ->formatStateUsing(fn (?string $state): ?string => DisplayName::clean($state))
                        ->placeholder('—'),
                    TextEntry::make('compliance')
                        ->label('Compliance')
                        ->badge()
                        ->state(fn ($record): string => ProductComplianceStatus::label($record))
                        ->color(fn (string $state): string => ProductComplianceStatus::color($state)),
                    TextEntry::make('dea_schedule')
                        ->label('DEA')
                        ->badge()
                        ->state(function ($record): ?string {
                            $fda = $record->fdaProduct;
                            if ($fda === null && filled($record->fda_product_id)) {
                                $fda = FdaProduct::query()->find($record->fda_product_id);
                            }

                            return FdaRegistryStatus::deaScheduleLabel($fda?->dea_schedule);
                        })
                        ->color(fn (?string $state): string => match ($state) {
                            'CII' => 'danger',
                            'CIII', 'CIV', 'CV' => 'warning',
                            default => 'gray',
                        })
                        ->placeholder('—'),
                    TextEntry::make('is_active')
                        ->label('Status')
                        ->badge()
                        ->formatStateUsing(fn (?bool $state): string => $state ? 'Active' : 'Inactive')
                        ->color(fn (?bool $state): string => $state ? 'success' : 'gray'),
                ]),
            Section::make('Authorizations')
                ->compact()
                ->schema([
                    RepeatableEntry::make('tradingPartners')
                        ->label('')
                        ->table([
                            TableColumn::make('Partner'),
                            TableColumn::make('SKU'),
                            TableColumn::make('UOM'),
                            TableColumn::make('Status'),
                        ])
                        ->schema([
                            TextEntry::make('name')
                                ->formatStateUsing(fn (?string $state): ?string => DisplayName::clean($state)),
                            TextEntry::make('pivot.partner_item_number')
                                ->label('SKU')
                                ->placeholder('—'),
                            TextEntry::make('pivot.uom_code')
                                ->label('UOM')
                                ->placeholder('—'),
                            TextEntry::make('pivot.authorization_status')
                                ->label('Status')
                                ->badge()
                                ->formatStateUsing(function (?string $state): string {
                                    if ($state === null) {
                                        return ProductComplianceStatus::Incomplete;
                                    }

                                    return AuthorizationStatus::tryFrom($state)?->operatorLabel() ?? ProductComplianceStatus::Incomplete;
                                })
                                ->color(fn (?string $state): string => AuthorizationStatus::tryFrom($state)?->badgeColor() ?? 'gray'),
                        ])
                        ->placeholder('No receive-from authorizations yet.'),
                ]),
        ]);
    }
}
