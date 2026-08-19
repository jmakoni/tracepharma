<?php

namespace App\Filament\App\Resources\TransferringSessions\Pages;

use App\Filament\App\Resources\TransferringSessions\TransferringSessionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListTransferringSessions extends ListRecords
{
    protected static string $resource = TransferringSessionResource::class;

    public function getDefaultActiveTab(): string|int|null
    {
        return 'active';
    }

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'active' => Tab::make('Active')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->whereIn('status', ['open', 'in_transit'])),
            'history' => Tab::make('History')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->where('status', 'completed')
                    ->orderByDesc('completed_at')
                    ->orderByDesc('id')),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('New transfer'),
        ];
    }
}
