<?php

namespace App\Filament\Admin\Resources\Fda\FdaWdd3plUnmatcheds\Pages;

use App\Filament\Admin\Resources\Fda\FdaWdd3plUnmatcheds\FdaWdd3plUnmatchedResource;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

class ListFdaWdd3plUnmatcheds extends ListRecords
{
    protected static string $resource = FdaWdd3plUnmatchedResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        return 'Facilities in the FDA WDD/3PL license listing that matched no catalog organization. Linking one files its listing against an organization; it does not authorize the partner.';
    }
}
