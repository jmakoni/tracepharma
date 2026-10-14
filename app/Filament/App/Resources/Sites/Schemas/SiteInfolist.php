<?php

namespace App\Filament\App\Resources\Sites\Schemas;

use App\Models\Site;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;

class SiteInfolist
{
    public static function configure(Schema $schema, bool $compact = false): Schema
    {
        return $schema->components([
            View::make('filament.app.infolists.site-details-card')
                ->columnSpanFull()
                ->viewData(fn (?Site $record): array => [
                    'compact' => $compact,
                    'linkableCounts' => true,
                ]),
        ]);
    }
}
