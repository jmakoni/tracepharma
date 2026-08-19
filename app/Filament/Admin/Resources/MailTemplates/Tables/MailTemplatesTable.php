<?php

namespace App\Filament\Admin\Resources\MailTemplates\Tables;

use App\Filament\Support\RecordActionGroup;
use App\Models\MailTemplate;
use App\Support\Mail\MailTemplateCatalog;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

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
                    ->description(fn (MailTemplate $record): string => $record->key),
                TextColumn::make('subject')
                    ->searchable()
                    ->wrap()
                    ->limit(60),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('key')
            ->paginated(false)
            ->recordActions(RecordActionGroup::make([
                EditAction::make(),
            ]));
    }
}
