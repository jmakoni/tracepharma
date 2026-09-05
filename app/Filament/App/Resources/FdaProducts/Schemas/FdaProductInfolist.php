<?php

namespace App\Filament\App\Resources\FdaProducts\Schemas;

use App\Support\Catalog\DisplayName;
use App\Support\Fda\FdaRegistryStatus;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;

class FdaProductInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Identifiers')
                ->compact()
                ->columns(['md' => 2])
                ->schema([
                    TextEntry::make('product_ndc')
                        ->label('NDC')
                        ->copyable()
                        ->fontFamily(FontFamily::Mono),
                    TextEntry::make('brand_name')
                        ->formatStateUsing(fn (?string $state): ?string => DisplayName::clean($state))
                        ->placeholder('—'),
                    TextEntry::make('generic_name')
                        ->formatStateUsing(fn (?string $state): ?string => DisplayName::clean($state))
                        ->placeholder('—'),
                    TextEntry::make('fdaOrganization.name')
                        ->label('Labeler')
                        ->formatStateUsing(fn (?string $state): ?string => DisplayName::clean($state))
                        ->placeholder('—'),
                ]),
            Section::make('Characteristics')
                ->compact()
                ->columns(['md' => 2])
                ->schema([
                    TextEntry::make('dea_schedule')
                        ->label('DEA')
                        ->badge()
                        ->formatStateUsing(fn (?string $state): ?string => FdaRegistryStatus::deaScheduleLabel($state))
                        ->color(fn (?string $state): string => match (FdaRegistryStatus::deaScheduleLabel($state)) {
                            'CII' => 'danger',
                            'CIII', 'CIV', 'CV' => 'warning',
                            default => 'gray',
                        })
                        ->placeholder('—'),
                    TextEntry::make('dosage_form')
                        ->placeholder('—'),
                    TextEntry::make('product_type')
                        ->placeholder('—'),
                    IconEntry::make('finished')
                        ->boolean(),
                ]),
        ]);
    }
}
