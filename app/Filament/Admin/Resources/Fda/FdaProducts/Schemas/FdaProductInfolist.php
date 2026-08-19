<?php

namespace App\Filament\Admin\Resources\Fda\FdaProducts\Schemas;

use App\Filament\Admin\Resources\Fda\FdaOrganizations\FdaOrganizationResource;
use App\Filament\Admin\Support\FdaRegistryBadges;
use App\Models\Fda\FdaProduct;
use App\Support\Fda\FdaRegistryStatus;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class FdaProductInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Identity')
                ->columns(2)
                ->schema([
                    FdaRegistryBadges::identifierEntry('product_ndc', 'NDC'),
                    FdaRegistryBadges::identifierEntry('product_id', 'Product ID'),
                    TextEntry::make('name')->placeholder('—'),
                    TextEntry::make('brand_name')->placeholder('—'),
                    TextEntry::make('generic_name')->placeholder('—'),
                    TextEntry::make('fdaOrganization.name')
                        ->label('Organization')
                        ->url(fn (FdaProduct $record): ?string => $record->fda_organization_id
                            ? FdaOrganizationResource::getUrl('view', ['record' => $record->fda_organization_id])
                            : null),
                    FdaRegistryBadges::activeEntry(),
                ]),
            Section::make('Regulatory')
                ->columns(2)
                ->schema([
                    TextEntry::make('product_kind')
                        ->label('Rx / OTC')
                        ->badge()
                        ->state(fn (FdaProduct $record): ?string => FdaRegistryStatus::productKind($record))
                        ->formatStateUsing(fn (?string $state): string => match ($state) {
                            FdaRegistryStatus::PRODUCT_RX => 'Rx',
                            FdaRegistryStatus::PRODUCT_OTC => 'OTC',
                            default => '—',
                        }),
                    TextEntry::make('dea_schedule')
                        ->label('DEA')
                        ->badge()
                        ->formatStateUsing(fn (?string $state): string => FdaRegistryStatus::deaScheduleLabel($state) ?? '—'),
                    TextEntry::make('dosage_form')->placeholder('—'),
                    TextEntry::make('strength')->placeholder('—'),
                    TextEntry::make('marketing_category')->placeholder('—'),
                    TextEntry::make('application_number')->placeholder('—'),
                    TextEntry::make('product_type')->placeholder('—'),
                    TextEntry::make('marketing_start_date')->date()->placeholder('—'),
                    TextEntry::make('listing_expiration_date')->date()->placeholder('—'),
                    TextEntry::make('pharm_classes')
                        ->label('Pharm classes')
                        ->state(fn (FdaProduct $record): string => $record->pharmClasses
                            ->pluck('class_name')
                            ->filter()
                            ->implode(', ') ?: '—')
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
