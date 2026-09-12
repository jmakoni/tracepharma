<?php

namespace App\Filament\App\Resources\ReadPoints\Schemas;

use App\Support\Auth\CurrentSite;
use App\Support\Auth\Permissions;
use App\Support\Auth\SiteAccess;
use App\Support\Gs1\SglnRules;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class ReadPointForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Identity')
                    ->compact()
                    ->columns(['md' => 2, 'lg' => 3])
                    ->schema([
                        Select::make('site_id')
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
                            ->required()
                            ->native(false),
                        TextInput::make('name')->required()->maxLength(255),
                        TextInput::make('code')->maxLength(255),
                        // A read point has no GLN column of its own, so the SGLN is the
                        // only thing that says where an EPCIS event was read: it has to
                        // parse, or the event cannot be tied back to this location.
                        SglnRules::input()
                            ->helperText('The GS1 Pure Identity URN for this read point, e.g. a sub-location of the site GLN.'),
                        Toggle::make('is_active')->default(true),
                    ]),
            ]);
    }
}
