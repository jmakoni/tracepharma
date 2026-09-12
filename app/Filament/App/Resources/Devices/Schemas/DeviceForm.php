<?php

namespace App\Filament\App\Resources\Devices\Schemas;

use App\Enums\DeviceType;
use App\Support\Auth\CurrentSite;
use App\Support\Auth\Permissions;
use App\Support\Auth\SiteAccess;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class DeviceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Identity')
                    ->compact()
                    ->columns(['md' => 2, 'lg' => 3])
                    ->schema([
                        TextInput::make('name')->required()->maxLength(255),
                        Select::make('device_type')
                            ->options(collect(DeviceType::cases())->mapWithKeys(
                                fn (DeviceType $type) => [$type->value => $type->label()]
                            ))
                            ->required()
                            ->native(false),
                        TextInput::make('manufacturer')->maxLength(255),
                        TextInput::make('model')->maxLength(255),
                        TextInput::make('serial_number')->maxLength(255),
                        Select::make('site_id')
                            ->label('Site')
                            ->relationship(
                                'site',
                                'name',
                                modifyQueryUsing: function (Builder $query): Builder {
                                    $user = auth()->user();
                                    if ($user === null) {
                                        return $query->whereRaw('0 = 1');
                                    }
                                    if ($user->can(Permissions::SitesAccessAll)) {
                                        return $query;
                                    }

                                    return $query->whereIn('id', SiteAccess::userSiteIds($user));
                                },
                            )
                            ->default(fn (): ?int => CurrentSite::id())
                            ->searchable()
                            ->preload()
                            ->searchDebounce(500)
                            ->native(false),
                        Toggle::make('is_active')->default(true),
                    ]),
            ]);
    }
}
