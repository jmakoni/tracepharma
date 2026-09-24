<?php

namespace App\Filament\App\Resources\Exceptions\Pages;

use App\Enums\ExceptionStatus;
use App\Filament\App\Resources\Exceptions\ExceptionResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListExceptions extends ListRecords
{
    protected static string $resource = ExceptionResource::class;

    public function getDefaultActiveTab(): string|int|null
    {
        return 'all_open';
    }

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'all_open' => Tab::make('Open')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->open()),
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
            'cleared_resolved' => Tab::make('Cleared / resolved')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn('status', [
                    ExceptionStatus::Cleared->value,
                    ExceptionStatus::Resolved->value,
                    ExceptionStatus::Overridden->value,
                    ExceptionStatus::Closed->value,
                ])),
            'resolved_recently' => Tab::make('Resolved Recently')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->resolvedRecently()),
        ];
    }
}
