<?php

namespace App\Filament\App\Resources\Exceptions\Pages;

use App\Filament\App\Resources\Exceptions\ExceptionResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListExceptions extends ListRecords
{
    protected static string $resource = ExceptionResource::class;

    public function getDefaultActiveTab(): string|int|null
    {
        return 'my_open';
    }

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'my_open' => Tab::make('My Open')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->open()
                    ->assignedTo(auth()->id())),
            'critical' => Tab::make('Critical')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->open()
                    ->critical()),
            'waiting_partner' => Tab::make('Waiting on Partner')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->waitingPartner()),
            'quarantined' => Tab::make('Quarantined')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->withOpenQuarantine()),
            'all_open' => Tab::make('All Open')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->open()),
            'resolved_recently' => Tab::make('Resolved Recently')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->resolvedRecently()),
        ];
    }
}
