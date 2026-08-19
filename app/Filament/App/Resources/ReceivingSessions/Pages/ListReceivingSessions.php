<?php

namespace App\Filament\App\Resources\ReceivingSessions\Pages;

use App\Filament\App\Resources\ReceivingSessions\ReceivingSessionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListReceivingSessions extends ListRecords
{
    protected static string $resource = ReceivingSessionResource::class;

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
                    ->whereIn('status', ['open', 'in_progress'])),
            'history' => Tab::make('History')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->whereIn('status', ['completed', 'cancelled'])
                    ->orderByDesc('completed_at')
                    ->orderByDesc('id')),
        ];
    }

    public function getTabsContentComponent(): Component
    {
        return parent::getTabsContentComponent()
            ->extraAttributes([
                'class' => 'fi-tabs-align-start',
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Scan-first receive'),
        ];
    }
}
