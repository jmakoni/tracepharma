<?php

namespace App\Filament\Admin\Resources\Admins\Schemas;

use App\Enums\AdminRole;
use App\Models\Admin;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get as SchemaGet;
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
                    Toggle::make('is_active')
                        ->label('Account active')
                        ->default(true)
                        ->disabled(fn (?Admin $record): bool => $record !== null && auth('admin')->user()?->is($record))
                        ->helperText(fn (?Admin $record): ?string => $record !== null && auth('admin')->user()?->is($record)
                            ? 'You cannot disable your own account.'
                            : 'Inactive accounts cannot sign in (password or SSO).'),
                    Toggle::make('must_change_password')
                        ->label('Must change password at next login')
                        ->helperText('Applies to password sign-in only. SSO users manage passwords in the identity provider.')
                        ->default(false),
                    TextInput::make('disabled_reason')
                        ->label('Disable reason')
                        ->maxLength(255)
                        ->visible(fn (SchemaGet $get): bool => ! (bool) $get('is_active')),
                ]),
            Section::make('Directory / SSO')
                ->compact()
                ->columns(['md' => 2])
                ->description('Synced from the identity provider on SSO sign-in. Read-only.')
                ->collapsed()
                ->schema([
                    TextInput::make('oidc_issuer')
                        ->label('OIDC issuer')
                        ->disabled()
                        ->dehydrated(false),
                    TextInput::make('oidc_subject')
                        ->label('OIDC subject')
                        ->disabled()
                        ->dehydrated(false),
                    TextInput::make('directory_object_id')
                        ->label('Directory object ID')
                        ->disabled()
                        ->dehydrated(false),
                    TextInput::make('user_principal_name')
                        ->label('User principal name')
                        ->disabled()
                        ->dehydrated(false),
                    TextInput::make('employee_id')
                        ->label('Employee ID')
                        ->disabled()
                        ->dehydrated(false),
                    TextInput::make('given_name')
                        ->disabled()
                        ->dehydrated(false),
                    TextInput::make('surname')
                        ->disabled()
                        ->dehydrated(false),
                    TextInput::make('job_title')
                        ->disabled()
                        ->dehydrated(false),
                    TextInput::make('department')
                        ->disabled()
                        ->dehydrated(false),
                    TextInput::make('company_name')
                        ->disabled()
                        ->dehydrated(false),
                    TextInput::make('office_location')
                        ->disabled()
                        ->dehydrated(false),
                    TextInput::make('mobile_phone')
                        ->disabled()
                        ->dehydrated(false),
                    TextInput::make('business_phone')
                        ->disabled()
                        ->dehydrated(false),
                    Placeholder::make('directory_groups_display')
                        ->label('Directory groups')
                        ->content(function (?Admin $record): string {
                            $groups = $record?->directory_groups;

                            if (! is_array($groups) || $groups === []) {
                                return '—';
                            }

                            return implode(', ', array_map(strval(...), $groups));
                        }),
                    Placeholder::make('directory_synced_at_display')
                        ->label('Directory synced at')
                        ->content(fn (?Admin $record): string => $record?->directory_synced_at?->toDateTimeString() ?? '—'),
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
