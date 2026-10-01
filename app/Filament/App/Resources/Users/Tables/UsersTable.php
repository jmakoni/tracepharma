<?php

namespace App\Filament\App\Resources\Users\Tables;

use App\Enums\TenantRole;
use App\Filament\Support\RecordActionGroup;
use App\Filament\Support\RegulatoryCompliance;
use App\Models\User;
use App\Support\Tables\DistinctColumnOptions;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Zvizvi\FilamentColumnFilters\Filters\ColumnFilter;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('roles'))
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('name')
                            ->options(fn (): array => DistinctColumnOptions::of(User::class, 'name')),
                    ),
                TextColumn::make('email')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('email')
                            ->options(fn (): array => DistinctColumnOptions::of(User::class, 'email')),
                    ),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->sortable()
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('is_active')
                            ->options(DistinctColumnOptions::boolean('Active', 'Inactive')),
                    ),
                TextColumn::make('security_status')
                    ->label('Security')
                    ->badge()
                    ->state(function (User $record): ?string {
                        if (! $record->is_active) {
                            return 'Disabled';
                        }
                        if ($record->isLocked()) {
                            return 'Locked';
                        }
                        if ($record->must_change_password) {
                            return 'Must change password';
                        }

                        return null;
                    })
                    ->color(fn (?string $state): string => match ($state) {
                        'Disabled' => 'danger',
                        'Locked' => 'warning',
                        'Must change password' => 'info',
                        default => 'gray',
                    })
                    ->placeholder('—'),
                TextColumn::make('user_principal_name')
                    ->label('UPN')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('user_principal_name')
                            ->options(fn (): array => DistinctColumnOptions::of(User::class, 'user_principal_name')),
                    ),
                TextColumn::make('department')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('department')
                            ->options(fn (): array => DistinctColumnOptions::of(User::class, 'department')),
                    ),
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
                    ->wrap()
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('roles.name')
                            ->options(DistinctColumnOptions::enum(TenantRole::class)),
                    ),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->columnFilter(ColumnFilter::date()),
            ])
            ->defaultSort('name')
            ->searchPlaceholder('Name or email')
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(25)
            ->extremePaginationLinks()
            ->recordActions(RecordActionGroup::make([
                EditAction::make(),
                Action::make('unlock')
                    ->label('Unlock')
                    ->icon('heroicon-o-lock-open')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->visible(fn (User $record): bool => $record->isLocked())
                    ->action(fn (User $record) => $record->unlock()),
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
