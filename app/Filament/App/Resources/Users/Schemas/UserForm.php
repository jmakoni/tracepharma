<?php

namespace App\Filament\App\Resources\Users\Schemas;

use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\Receiving\EligibleReceiveSites;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get as SchemaGet;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        $profile = self::profile();
        $roleNames = array_keys(TenantRole::optionsForProfile($profile));

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
                        ->disabled(fn (?User $record): bool => $record !== null && auth()->user()?->is($record))
                        ->helperText(fn (?User $record): ?string => $record !== null && auth()->user()?->is($record)
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
                        ->content(function (?User $record): string {
                            $groups = $record?->directory_groups;

                            if (! is_array($groups) || $groups === []) {
                                return '—';
                            }

                            return implode(', ', array_map(strval(...), $groups));
                        }),
                    Placeholder::make('directory_synced_at_display')
                        ->label('Directory synced at')
                        ->content(fn (?User $record): string => $record?->directory_synced_at?->toDateTimeString() ?? '—'),
                ]),
            Section::make('Roles')
                ->compact()
                ->description(fn (): ?string => JobRoleAccess::enabled()
                    ? 'When “Limit access by job role” is on, each role limits menus — permissions sync automatically when an owner enables that setting in Organization → Access.'
                    : null)
                ->schema([
                    CheckboxList::make('roles')
                        ->relationship(
                            name: 'roles',
                            titleAttribute: 'name',
                            modifyQueryUsing: function (Builder $query) use ($roleNames): Builder {
                                $query = $query
                                    ->where('guard_name', 'web')
                                    ->whereIn('name', $roleNames)
                                    ->orderBy('name');

                                if (! JobRoleAccess::isOwner()) {
                                    $livewire = Livewire::current();
                                    $record = is_object($livewire) && method_exists($livewire, 'getRecord')
                                        ? $livewire->getRecord()
                                        : null;
                                    $keepOwner = $record instanceof User
                                        && $record->hasRole(TenantRole::Owner->value);

                                    if (! $keepOwner) {
                                        $query->where('name', '!=', TenantRole::Owner->value);
                                    }
                                }

                                return $query;
                            },
                        )
                        ->disableOptionWhen(
                            fn (?Model $record): bool => $record instanceof Model
                                && ! JobRoleAccess::isOwner()
                                && (string) $record->getAttribute('name') === TenantRole::Owner->value,
                        )
                        ->getOptionLabelFromRecordUsing(
                            fn (Model $record): string => TenantRole::tryFrom((string) $record->getAttribute('name'))?->label()
                                ?? (string) $record->getAttribute('name')
                        )
                        ->columns(2)
                        ->bulkToggleable()
                        ->live()
                        ->required(),
                    Placeholder::make('job_role_capabilities')
                        ->hiddenLabel()
                        ->visible(fn (): bool => JobRoleAccess::enabled())
                        ->content(function (SchemaGet $get): string {
                            $roles = self::selectedTenantRoles($get('roles'));
                            if ($roles === []) {
                                return 'Select a job role to see which menus it unlocks when “Limit access by job role” is on.';
                            }

                            $labels = collect($roles)
                                ->flatMap(static fn (TenantRole $role): array => TenantRoleSeeder::capabilityLabelsFor($role))
                                ->unique()
                                ->values()
                                ->all();

                            if ($labels === []) {
                                return 'Selected roles do not unlock any menus.';
                            }

                            $prefix = count($roles) === 1 ? 'This role can' : 'These roles can';

                            return $prefix.': '.implode(', ', $labels).'.';
                        }),
                ]),
            Section::make('Site access')
                ->compact()
                ->description('Organization facility sites this user can access.')
                ->visible(fn (SchemaGet $get): bool => ! self::formHasUnrestrictedSiteAccess($get('roles')))
                ->schema([
                    CheckboxList::make('site_ids')
                        ->label('Sites')
                        ->options(fn (): array => EligibleReceiveSites::forOrganization()
                            ->pluck('name', 'id')
                            ->all())
                        ->columns(2)
                        ->bulkToggleable()
                        ->live()
                        ->required(fn (SchemaGet $get): bool => ! self::formHasUnrestrictedSiteAccess($get('roles'))),
                    Select::make('default_site_id')
                        ->label('Default site')
                        ->options(fn (SchemaGet $get): array => EligibleReceiveSites::forOrganization()
                            ->whereIn('id', $get('site_ids') ?? [])
                            ->pluck('name', 'id')
                            ->all())
                        ->searchable()
                        ->native(false)
                        ->required(fn (SchemaGet $get): bool => filled($get('site_ids')))
                        ->disabled(fn (SchemaGet $get): bool => blank($get('site_ids'))),
                ]),
            Section::make('Site access')
                ->compact()
                ->visible(fn (SchemaGet $get): bool => self::formHasUnrestrictedSiteAccess($get('roles')))
                ->schema([
                    Placeholder::make('owner_site_access')
                        ->hiddenLabel()
                        ->content(fn (SchemaGet $get): string => self::formHasSupportEngineerRole($get('roles'))
                            && ! self::formHasOwnerRole($get('roles'))
                            ? 'Support Engineers have access to all organization facility sites.'
                            : 'Owners have access to all organization facility sites.'),
                ]),
        ]);
    }

    private static function formHasUnrestrictedSiteAccess(mixed $roles): bool
    {
        return self::formHasOwnerRole($roles) || self::formHasSupportEngineerRole($roles);
    }

    private static function formHasOwnerRole(mixed $roles): bool
    {
        return in_array(TenantRole::Owner, self::selectedTenantRoles($roles), true);
    }

    private static function formHasSupportEngineerRole(mixed $roles): bool
    {
        return in_array(TenantRole::SupportEngineer, self::selectedTenantRoles($roles), true);
    }

    /**
     * @return list<TenantRole>
     */
    private static function selectedTenantRoles(mixed $roles): array
    {
        if (! is_array($roles) || $roles === []) {
            return [];
        }

        $first = reset($roles);

        if (is_numeric($first)) {
            $names = Role::query()
                ->where('guard_name', 'web')
                ->whereIn('id', array_map(intval(...), $roles))
                ->pluck('name');

            return $names
                ->map(static fn (mixed $name): ?TenantRole => TenantRole::tryFrom((string) $name))
                ->filter()
                ->values()
                ->all();
        }

        return collect($roles)
            ->map(static function (mixed $role): ?TenantRole {
                if ($role instanceof TenantRole) {
                    return $role;
                }

                return TenantRole::tryFrom((string) $role);
            })
            ->filter()
            ->values()
            ->all();
    }

    private static function profile(): TenantProfile
    {
        $tenant = tenant();

        if ($tenant instanceof Tenant && $tenant->profile instanceof TenantProfile) {
            return $tenant->profile;
        }

        return TenantProfile::Pharmacy;
    }
}
