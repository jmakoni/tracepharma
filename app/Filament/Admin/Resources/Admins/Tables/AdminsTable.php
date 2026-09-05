<?php

namespace App\Filament\Admin\Resources\Admins\Tables;

use App\Enums\AdminRole;
use App\Filament\Support\RecordActionGroup;
use App\Models\Admin;
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

class AdminsTable
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
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->sortable(),
                TextColumn::make('security_status')
                    ->label('Security')
                    ->badge()
                    ->state(function (Admin $record): ?string {
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
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('department')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('roles.name')
                    ->label('Roles')
                    ->badge()
                    ->formatStateUsing(function (?string $state): string {
                        if (blank($state)) {
                            return '—';
                        }

                        $role = AdminRole::tryFrom($state);

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
                Action::make('unlock')
                    ->label('Unlock')
                    ->icon('heroicon-o-lock-open')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->visible(fn (Admin $record): bool => $record->isLocked())
                    ->action(fn (Admin $record) => $record->unlock()),
                DeleteAction::make()
                    ->visible(fn (Model $record): bool => ! auth('admin')->user()?->is($record)),
            ]))
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
