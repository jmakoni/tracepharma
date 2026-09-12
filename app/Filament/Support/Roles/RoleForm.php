<?php

declare(strict_types=1);

namespace App\Filament\Support\Roles;

use App\Support\Auth\JobRoleAccess;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class RoleForm
{
    public static function configure(Schema $schema, string $guard): Schema
    {
        return $schema->components([
            Section::make('Role')
                ->compact()
                ->schema([
                    TextInput::make('display_name')
                        ->label('Name')
                        ->disabled()
                        ->dehydrated(false),
                    TextInput::make('name')
                        ->label('Key')
                        ->disabled()
                        ->dehydrated(false)
                        ->helperText('Catalog role keys cannot be renamed.'),
                    Placeholder::make('job_roles_banner')
                        ->hiddenLabel()
                        ->content('“Limit access by job role” is off in Organization Settings — capability checks are unrestricted until it is enabled. You can still edit role bundles here.')
                        ->visible(fn (): bool => $guard === 'web' && ! JobRoleAccess::enabled()),
                ]),
            Section::make('Permissions')
                ->compact()
                ->description('Overrides the seeded capability bundle for this role. Use Reset to defaults to restore the catalog map.')
                ->schema([
                    CheckboxList::make('permission_names')
                        ->label('Capabilities')
                        ->options(RolePermissionEditor::catalogOptions($guard))
                        ->columns(2)
                        ->bulkToggleable(),
                ]),
        ]);
    }
}
