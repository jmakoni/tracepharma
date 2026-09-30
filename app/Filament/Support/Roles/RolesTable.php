<?php

declare(strict_types=1);

namespace App\Filament\Support\Roles;

use App\Filament\Support\RecordActionGroup;
use App\Support\Tables\DistinctColumnOptions;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Permission\Models\Role;
use Zvizvi\FilamentColumnFilters\Filters\ColumnFilter;

final class RolesTable
{
    /**
     * @param  list<string>  $roleNames
     */
    public static function configure(Table $table, string $guard, array $roleNames): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->where('guard_name', $guard)
                ->whereIn('name', $roleNames)
                ->withCount('permissions')
                ->orderBy('name'))
            ->columns([
                TextColumn::make('name')
                    ->label('Role')
                    ->formatStateUsing(fn (string $state): string => RolePermissionEditor::roleLabel($state, $guard))
                    ->searchable()
                    ->sortable()
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('name')
                            ->options(fn (): array => DistinctColumnOptions::of(
                                Role::class,
                                'name',
                                constrain: fn (Builder $query): Builder => $query
                                    ->where('guard_name', $guard)
                                    ->whereIn('name', $roleNames),
                            )),
                    ),
                TextColumn::make('name_key')
                    ->label('Key')
                    ->state(fn (Role $record): string => (string) $record->name)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('permissions_count')
                    ->label('Permissions')
                    ->sortable()
                    ->columnFilter(ColumnFilter::range()),
            ])
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(25)
            ->recordActions(RecordActionGroup::make([
                EditAction::make(),
            ]))
            ->toolbarActions([]);
    }
}
