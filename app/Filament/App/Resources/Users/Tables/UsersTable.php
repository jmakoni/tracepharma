<?php

namespace App\Filament\App\Resources\Users\Tables;

use App\Enums\TenantRole;
use App\Filament\Support\RecordActionGroup;
use App\Filament\Support\RegulatoryCompliance;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('roles'))
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->searchable()
                    ->sortable()
                    ->copyable(),
                TextColumn::make('roles.name')
                    ->label('Roles')
                    ->badge()
                    ->formatStateUsing(function (?string $state): string {
                        if (blank($state)) {
                            return '—';
                        }

                        $role = TenantRole::tryFrom($state);

                        return $role?->label() ?? $state;
                    })
                    ->separator(',')
                    ->wrap(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('name')
            ->searchPlaceholder('Name or email')
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(25)
            ->extremePaginationLinks()
            ->recordActions(RecordActionGroup::make([
                EditAction::make(),
                RegulatoryCompliance::apply(
                    DeleteAction::make()
                        ->visible(fn (Model $record): bool => ! auth()->user()?->is($record)),
                    'users_delete',
                    requireReason: true,
                ),
            ]))
            ->toolbarActions([
                BulkActionGroup::make([
                    RegulatoryCompliance::apply(
                        DeleteBulkAction::make(),
                        'users_bulk_delete',
                        requireReason: true,
                    ),
                ]),
            ]);
    }
}
