<?php

namespace App\Filament\Admin\Resources\Fda\FdaImportRuns\Schemas;

use App\Filament\Admin\Support\FdaRegistryBadges;
use App\Models\Fda\FdaImportRun;
use App\Support\Fda\FdaRegistryStatus;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class FdaImportRunInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Run')
                ->columns(2)
                ->schema([
                    TextEntry::make('source'),
                    TextEntry::make('outcome')
                        ->label('Status')
                        ->badge()
                        ->state(fn (FdaImportRun $record): string => FdaRegistryStatus::importRun($record))
                        ->formatStateUsing(fn (string $state): string => match ($state) {
                            FdaRegistryStatus::IMPORT_SUCCESS => 'Success',
                            FdaRegistryStatus::IMPORT_PARTIAL => 'Partial',
                            FdaRegistryStatus::IMPORT_FAILED => 'Failed',
                            default => $state,
                        }),
                    TextEntry::make('started_at')->dateTime(),
                    TextEntry::make('completed_at')->dateTime()->placeholder('—'),
                    TextEntry::make('duration_ms')
                        ->label('Duration')
                        ->formatStateUsing(fn (?int $state): string => $state === null
                            ? '—'
                            : number_format($state / 1000, 1).'s'),
                    FdaRegistryBadges::identifierEntry('sha256', 'SHA-256'),
                    TextEntry::make('source_path')->placeholder('—')->columnSpanFull(),
                ]),
            Section::make('Counts')
                ->columns(3)
                ->schema([
                    TextEntry::make('rows_read')->label('Read'),
                    TextEntry::make('rows_inserted')->label('Inserted'),
                    TextEntry::make('rows_updated')->label('Updated'),
                    TextEntry::make('rows_skipped')->label('Skipped'),
                    TextEntry::make('rows_sent_to_review')->label('Sent to review'),
                ]),
        ]);
    }
}
