<?php

namespace App\Filament\Admin\Resources\MailTemplates\Tables;

use App\Filament\Support\RecordActionGroup;
use App\Models\MailTemplate;
use App\Support\Mail\MailTemplateCatalog;
use App\Support\Tables\DistinctColumnOptions;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Zvizvi\FilamentColumnFilters\Filters\ColumnFilter;

class MailTemplatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('key')
                    ->label('Template')
                    ->searchable()
                    ->sortable()
                    ->formatStateUsing(fn (string $state): string => MailTemplateCatalog::get($state)->label)
                    ->description(fn (MailTemplate $record): string => $record->key)
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('key')
                            ->options(fn (): array => DistinctColumnOptions::of(MailTemplate::class, 'key')),
                    ),
                TextColumn::make('subject')
                    ->searchable()
                    ->wrap()
                    ->limit(60)
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('subject')
                            ->options(fn (): array => DistinctColumnOptions::of(MailTemplate::class, 'subject')),
                    ),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('is_active')
                            ->options(DistinctColumnOptions::boolean('Active', 'Inactive')),
                    ),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->columnFilter(ColumnFilter::date()),
            ])
            ->defaultSort('key')
            ->paginated(false)
            ->recordActions(RecordActionGroup::make([
                EditAction::make(),
            ]));
    }
}
