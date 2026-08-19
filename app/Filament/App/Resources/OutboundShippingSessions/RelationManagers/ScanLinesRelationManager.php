<?php

namespace App\Filament\App\Resources\OutboundShippingSessions\RelationManagers;

use App\Actions\Shipping\UnconfirmOutboundShippingScanLine;
use App\Models\Epcis\Epc;
use App\Models\Shipping\OutboundShippingScanLine;
use App\Models\Shipping\OutboundShippingSession;
use App\Support\Tracing\AssetTrackingUrl;
use App\Support\Tracing\EpcContextLinks;
use DomainException;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\PaginationMode;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
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
            ]))
            ->columns([
                AssetTrackingUrl::linkEpcColumn(
                    TextColumn::make('epc.sscc18')
                        ->label('SSCC')
                        ->fontFamily(FontFamily::Mono)
                        ->placeholder('—')
                        ->searchable(),
                    fn (mixed $record): ?Epc => $record instanceof Model ? $record->epc : null,
                    copyable: true,
                ),
                AssetTrackingUrl::linkEpcColumn(
                    TextColumn::make('epc.gtin14')
                        ->label('GTIN')
                        ->fontFamily(FontFamily::Mono)
                        ->placeholder('—')
                        ->toggleable(),
                    fn (mixed $record): ?Epc => $record instanceof Model ? $record->epc : null,
                    copyable: true,
                ),
                AssetTrackingUrl::linkEpcColumn(
                    TextColumn::make('epc.serial_number')
                        ->label('Serial')
                        ->limit(20)
                        ->tooltip(fn (?string $state): ?string => $state)
                        ->fontFamily(FontFamily::Mono)
                        ->placeholder('—')
                        ->toggleable(),
                    fn (mixed $record): ?Epc => $record instanceof Model ? $record->epc : null,
                    copyable: true,
                ),
                TextColumn::make('line_role')
                    ->label('Role')
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'confirmed' => 'Confirmed',
                        default => filled($state) ? ucfirst($state) : '—',
                    })
                    ->color(fn (?string $state): string => match ($state) {
                        'confirmed' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('confirmed_at')
                    ->label('Confirmed at')
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable(),
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
                    ->label('Remove')
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
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
