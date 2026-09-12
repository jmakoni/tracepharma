<?php

namespace App\Filament\App\Resources\OutboundShippingSessions\RelationManagers;

use App\Actions\Shipping\UnconfirmOutboundShippingScanLine;
use App\Filament\Notifications\Notification;
use App\Models\Shipping\OutboundShippingScanLine;
use App\Models\Shipping\OutboundShippingSession;
use App\Support\Tracing\EpcContextLinks;
use App\Support\Tracing\Gs1DualDisplay;
use DomainException;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\PaginationMode;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\On;

class ScanLinesRelationManager extends RelationManager
{
    protected static string $relationship = 'scanLines';

    protected static ?string $title = 'Ship scans';

    protected static bool $isLazy = false;

    public function isReadOnly(): bool
    {
        /** @var OutboundShippingSession $session */
        $session = $this->getOwnerRecord();

        return ! $session->canUnconfirmScanLines();
    }

    #[On('outbound-shipping-scan-lines-updated')]
    public function refreshScanLines(): void
    {
        $this->resetTable();
        $this->loadTable();
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with([
                'epc:id,epc_type,sscc18,gtin14,serial_number,epc_uri,ai_00,ai_01_21',
                'epc.ilmd',
            ]))
            ->columns([
                TextColumn::make('identifier')
                    ->label('Identifier')
                    ->state(fn (OutboundShippingScanLine $record): string => $record->epc !== null
                        ? (Gs1DualDisplay::forEpc($record->epc)['gs1_barcode'] ?: '—')
                        : '—')
                    ->fontFamily(FontFamily::Mono)
                    ->placeholder('—')
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        $like = '%'.$search.'%';

                        return $query->whereHas('epc', function (Builder $epc) use ($like, $search): void {
                            $epc->where(function (Builder $inner) use ($like, $search): void {
                                $inner->where('epc_uri', 'like', $like)
                                    ->orWhere('sscc18', 'like', $like)
                                    ->orWhere('serial_number', 'like', $like)
                                    ->orWhere('ai_00', 'like', $like)
                                    ->orWhere('ai_01_21', 'like', $like)
                                    ->orWhere('epc_uri', $search)
                                    ->orWhere('sscc18', $search)
                                    ->orWhere('ai_01_21', $search);
                            });
                        });
                    }),
                TextColumn::make('confirmed_at')
                    ->label('Scan time')
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('epc.epc_uri')
                    ->label('Transcoded Value')
                    ->fontFamily(FontFamily::Mono)
                    ->placeholder('—'),
                IconColumn::make('present')
                    ->label('Present')
                    ->boolean()
                    ->state(fn (OutboundShippingScanLine $record): bool => $record->status === 'confirmed')
                    ->trueIcon(Heroicon::OutlinedCheckCircle)
                    ->falseIcon(Heroicon::OutlinedXCircle)
                    ->trueColor('success')
                    ->falseColor('danger'),
                EpcContextLinks::actionsColumn(),
            ])
            ->defaultSort('confirmed_at', 'desc')
            ->deferLoading()
            ->paginationMode(PaginationMode::Simple)
            ->paginated([10, 25])
            ->defaultPaginationPageOption(10)
            ->searchPlaceholder('SSCC or barcode')
            ->emptyStateHeading('No scans yet')
            ->emptyStateDescription('Scan an SSCC or SGTIN to confirm for shipment.')
            ->headerActions([])
            ->recordActions([
                Action::make('removeScan')
                    ->label('Delete')
                    ->icon(Heroicon::OutlinedTrash)
                    ->iconButton()
                    ->color('gray')
                    ->visible(fn (OutboundShippingScanLine $record): bool => $this->canRemoveScanLine($record))
                    ->requiresConfirmation()
                    ->modalHeading('Remove this scan?')
                    ->modalDescription('Removes this unit from the ship order.')
                    ->modalSubmitActionLabel('Remove')
                    ->action(function (OutboundShippingScanLine $record): void {
                        try {
                            app(UnconfirmOutboundShippingScanLine::class)->handle($record, auth()->id());
                        } catch (DomainException $e) {
                            Notification::make()
                                ->title('Remove blocked')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();

                            return;
                        }

                        $this->getOwnerRecord()->refresh();
                        $this->resetTable();
                        $this->loadTable();
                        $this->dispatch('outbound-shipping-scan-lines-updated');

                        Notification::make()
                            ->title('Scan removed')
                            ->success()
                            ->send();
                    }),
            ])
            ->modelLabel('Scan line')
            ->pluralModelLabel('Scan lines');
    }

    private function canRemoveScanLine(OutboundShippingScanLine $record): bool
    {
        /** @var OutboundShippingSession $session */
        $session = $this->getOwnerRecord();

        if (! $session->canUnconfirmScanLines()) {
            return false;
        }

        return $record->status === 'confirmed';
    }
}
