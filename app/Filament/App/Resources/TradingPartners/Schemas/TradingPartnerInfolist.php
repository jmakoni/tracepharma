<?php

namespace App\Filament\App\Resources\TradingPartners\Schemas;

use App\Models\TradingPartner;
use App\Support\MasterData\PartnerAtpSiteCoverage;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;

class TradingPartnerInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            View::make('filament.admin.infolists.catalog-trading-partner-profile')
                ->columnSpanFull(),
            Section::make('ATP verification')
                ->description('Authorization is per site. Manufacturer plants use FDA DECRS; other sites need a receiving-state WDD/3PL license.')
                ->compact()
                ->columnSpanFull()
                ->schema([
                    RepeatableEntry::make('atp_site_coverage')
                        ->label('')
                        ->getStateUsing(fn (TradingPartner $record): array => PartnerAtpSiteCoverage::rows($record)->all())
                        ->table([
                            TableColumn::make('Site'),
                            TableColumn::make('Source'),
                            TableColumn::make('Status'),
                            TableColumn::make('Note'),
                        ])
                        ->schema([
                            TextEntry::make('name')
                                ->label('Site')
                                ->formatStateUsing(function (mixed $state, mixed $record): string {
                                    $name = filled($state) ? (string) $state : 'Site';
                                    $place = is_array($record) ? trim((string) ($record['place'] ?? '')) : '';

                                    return $place !== '' ? $name.' · '.$place : $name;
                                }),
                            TextEntry::make('source_label')
                                ->label('Source')
                                ->placeholder('—'),
                            TextEntry::make('badge_label')
                                ->label('Status')
                                ->badge()
                                ->color(fn (mixed $state, mixed $record): string => is_array($record)
                                    ? (string) ($record['badge_color'] ?? 'gray')
                                    : 'gray'),
                            TextEntry::make('note')
                                ->label('Note')
                                ->placeholder('—'),
                        ])
                        ->placeholder('No sites yet.'),
                ]),
        ]);
    }
}
