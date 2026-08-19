<?php

namespace App\Filament\Admin\Resources\Admins\Schemas;

use App\Enums\AdminRole;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class AdminForm
{
    public static function configure(Schema $schema): Schema
    {
        $roleNames = array_keys(AdminRole::options());

        return $schema->components([
            Section::make('Account')
                ->compact()
                ->columns(['md' => 2])
                ->schema([
                    TextInput::make('name')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('email')
                        ->email()
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->maxLength(255),
                    TextInput::make('password')
                        ->password()
                        ->revealable()
                        ->required(fn (string $operation): bool => $operation === 'create')
                        ->dehydrated(fn (?string $state): bool => filled($state))
                        ->maxLength(255)
                        ->helperText(fn (string $operation): ?string => $operation === 'edit'
                            ? 'Leave blank to keep the current password.'
                            : null),
                ]),
            Section::make('Roles')
                ->compact()
                ->schema([
                    CheckboxList::make('roles')
                        ->relationship(
                            name: 'roles',
                            titleAttribute: 'name',
                            modifyQueryUsing: fn (Builder $query): Builder => $query
                                ->where('guard_name', 'admin')
                                ->whereIn('name', $roleNames)
                                ->orderBy('name'),
                        )
                        ->getOptionLabelFromRecordUsing(
                            fn (Model $record): string => AdminRole::tryFrom((string) $record->getAttribute('name'))?->label()
                                ?? (string) $record->getAttribute('name')
                        )
                        ->columns(2)
                        ->bulkToggleable()
                        ->required(),
                ]),
        ]);
    }
}
