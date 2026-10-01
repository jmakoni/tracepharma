<?php

namespace App\Filament\App\Resources\Exceptions\Pages;

use App\Enums\ExceptionStatus;
use App\Filament\App\Resources\Exceptions\ExceptionResource;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Tracepharma\FilamentTableViews\Concerns\HasTableViews;
use Tracepharma\FilamentTableViews\Support\PresetView;
use Zvizvi\FilamentColumnFilters\Concerns\HasColumnFilters;

class ListExceptions extends ListRecords
{
    use HasColumnFilters;
    use HasTableViews;

    protected static string $resource = ExceptionResource::class;

    /**
     * @return array<string, PresetView>
     */
    public function getPresetViews(): array
    {
        return [
            'all_open' => PresetView::make('Open')
                ->icon('heroicon-o-inbox')
                ->color('primary')
                ->default()
                ->query(fn (Builder $query): Builder => $query->open()),
            'my_open' => PresetView::make('My Open')
                ->icon('heroicon-o-user')
                ->color('info')
                ->query(fn (Builder $query): Builder => $query
                    ->open()
                    ->assignedTo(auth()->id())),
            'critical' => PresetView::make('Critical')
                ->icon('heroicon-o-exclamation-triangle')
                ->color('danger')
                ->query(fn (Builder $query): Builder => $query
                    ->open()
                    ->critical()),
            'waiting_partner' => PresetView::make('Waiting on Partner')
                ->icon('heroicon-o-clock')
                ->color('warning')
                ->query(fn (Builder $query): Builder => $query->waitingPartner()),
            'quarantined' => PresetView::make('Quarantined')
                ->icon('heroicon-o-lock-closed')
                ->color('warning')
                ->query(fn (Builder $query): Builder => $query->withOpenQuarantine()),
            'cleared_resolved' => PresetView::make('Cleared / resolved')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->query(fn (Builder $query): Builder => $query->whereIn('status', [
                    ExceptionStatus::Cleared->value,
                    ExceptionStatus::Resolved->value,
                    ExceptionStatus::Overridden->value,
                    ExceptionStatus::Closed->value,
                ])),
            'resolved_recently' => PresetView::make('Resolved Recently')
                ->icon('heroicon-o-clock')
                ->color('gray')
                ->query(fn (Builder $query): Builder => $query->resolvedRecently()),
        ];
    }
}
