<?php

namespace App\Filament\App\Resources\Fda3911Reports\Schemas;

use App\Enums\Fda3911Classification;
use App\Enums\Fda3911ReportStatus;
use App\Filament\App\Resources\Exceptions\ExceptionResource;
use App\Models\Fda3911Report;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class Fda3911ReportInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Status')
                    ->schema([
                        TextEntry::make('status')
                            ->badge()
                            ->formatStateUsing(fn (Fda3911ReportStatus $state): string => $state->label())
                            ->color(fn (Fda3911ReportStatus $state): string => $state->color()),
                        TextEntry::make('classification')
                            ->formatStateUsing(fn (Fda3911Classification $state): string => $state->label()),
                        TextEntry::make('due_at')
                            ->dateTime()
                            ->placeholder('—')
                            ->color(fn (Fda3911Report $record): ?string => $record->isOverdue() ? 'danger' : null),
                        TextEntry::make('incident_number')->placeholder('Not received'),
                        TextEntry::make('submitted_at')->dateTime()->placeholder('Not submitted'),
                        TextEntry::make('exceptionCase.title')
                            ->label('Linked exception')
                            ->placeholder('—')
                            ->url(fn (Fda3911Report $record): ?string => $record->exception_id
                                ? ExceptionResource::getUrl('view', ['record' => $record->exception_id], panel: 'app')
                                : null),
                    ])
                    ->columns(2),
                Section::make('Product')
                    ->schema([
                        TextEntry::make('product_name'),
                        TextEntry::make('product_ndc')->label('NDC'),
                        TextEntry::make('product_gtin')->label('GTIN'),
                        TextEntry::make('lot'),
                        TextEntry::make('serial'),
                        TextEntry::make('strength'),
                        TextEntry::make('dosage_form'),
                    ])
                    ->columns(2),
                Section::make('Circumstances')
                    ->schema([
                        TextEntry::make('circumstances')->columnSpanFull(),
                    ]),
            ]);
    }
}
