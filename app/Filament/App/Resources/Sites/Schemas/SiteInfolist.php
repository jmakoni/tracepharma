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
            View::make('filament.admin.infolists.catalog-site-profile')
                ->columnSpanFull()
                ->viewData(fn (?Site $record): array => [
                    'compact' => $compact,
                ]),
            View::make('filament.app.infolists.site-atp-readiness')
                ->columnSpanFull()
                ->viewData(fn (?Site $record): array => [
                    'linkableCounts' => true,
                ]),
        ]);
    }
}
