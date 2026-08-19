<?php

namespace App\Filament\App\Resources\ReceivingSessions\RelationManagers;

use App\Actions\Receiving\UnconfirmReceivingScanLine;
use App\Models\Epcis\Epc;
use App\Models\Receiving\ReceivingScanLine;
use App\Models\Receiving\ReceivingSession;
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
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\On;

class ScanLinesRelationManager extends RelationManager
{
    protected static string $relationship = 'scanLines';

    protected static ?string $title = 'Confirmed so far';

    /** Floor scan UX: must receive parent refresh events immediately (Filament default is lazy). */
    protected static bool $isLazy = false;

    public function isReadOnly(): bool
    {
        return false;
    }

    #[On('receiving-scan-lines-updated')]
    public function refreshScanLines(): void
    {
        $this->resetTable();
        $this->loadTable();
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with([
                    'epc:id,epc_type,sscc18,gtin14,serial_number,epc_uri,ai_00,ai_01_21',
                ])
                ->where(function (Builder $q): void {
                    $q->where('line_role', 'parent')
                        ->orWhere('status', 'unexpected');
                }))
            ->columns([
                TextColumn::make('line_role')
                    ->label('Role')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'parent' => 'Pallet',
                        'child' => 'Unit',
                        default => '—',
                    })
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'confirmed' => 'Confirmed',
                        'expected' => 'Expected',
                        'unexpected' => 'Unexpected',
                        default => '—',
                    })
                    ->color(fn (?string $state): string => match ($state) {
                        'confirmed' => 'success',
                        'expected' => 'warning',
                        'unexpected' => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),
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
                TextColumn::make('confirmed_at')
                    ->label('Scanned at')
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('epc.epc_type')
                    ->label('Type')
                    ->badge()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                AssetTrackingUrl::linkEpcColumn(
                    TextColumn::make('epc.serial_number')
                        ->label('Serial')
                        ->limit(16)
                        ->tooltip(fn (?string $state): ?string => $state)
                        ->fontFamily(FontFamily::Mono)
                        ->placeholder('—')
                        ->toggleable(isToggledHiddenByDefault: true),
                    fn (mixed $record): ?Epc => $record instanceof Model ? $record->epc : null,
                    copyable: true,
                ),
                AssetTrackingUrl::linkEpcColumn(
                    TextColumn::make('epc.epc_uri')
                        ->label('URI')
                        ->limit(36)
                        ->tooltip(fn (?string $state): ?string => $state)
                        ->fontFamily(FontFamily::Mono)
                        ->searchable()
                        ->toggleable(isToggledHiddenByDefault: true),
                    fn (mixed $record): ?Epc => $record instanceof Model ? $record->epc : null,
                    copyable: true,
                ),
                EpcContextLinks::actionsColumn(),
            ])
            ->defaultSort(fn (Builder $query): Builder => $query->orderByDesc('confirmed_at')->orderBy('status'))
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'expected' => 'Expected',
                        'confirmed' => 'Confirmed',
                        'unexpected' => 'Unexpected',
                    ]),
            ])
            ->deferLoading()
            ->paginationMode(PaginationMode::Simple)
            ->paginated([10, 25])
            ->defaultPaginationPageOption(10)
            ->searchPlaceholder('SSCC or barcode')
            ->emptyStateHeading('No scans yet')
            ->emptyStateDescription('Scan a pallet barcode to start.')
            ->headerActions([])
            ->recordActions([
                Action::make('removeScan')
                    ->label('Remove')
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->visible(fn (ReceivingScanLine $record): bool => $this->canRemoveScanLine($record))
                    ->requiresConfirmation()
                    ->modalHeading(fn (ReceivingScanLine $record): string => $record->status === 'unexpected'
                        ? 'Remove unexpected scan?'
                        : 'Unconfirm this pallet/case?')
                    ->modalDescription(fn (ReceivingScanLine $record): string => $record->status === 'unexpected'
                        ? 'Removes this unexpected scan from the session.'
                        : 'Unconfirm this pallet/case and remove its units from this session.')
                    ->modalSubmitActionLabel('Remove')
                    ->action(function (ReceivingScanLine $record): void {
                        try {
                            app(UnconfirmReceivingScanLine::class)->handle($record, auth()->id());
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
                        $this->dispatch('receiving-session-hud-refresh');

                        Notification::make()
                            ->title('Scan removed')
                            ->success()
                            ->send();
                    }),
            ])
            ->modelLabel('Scan line')
            ->pluralModelLabel('Scan lines');
    }

    private function canRemoveScanLine(ReceivingScanLine $record): bool
    {
        /** @var ReceivingSession $session */
        $session = $this->getOwnerRecord();

        if ($session->status === 'completed' || $session->receiving_events_generated_at !== null) {
            return false;
        }

        if (! in_array($session->status, ['open', 'in_progress'], true)) {
            return false;
        }

        return in_array($record->status, ['confirmed', 'unexpected'], true);
    }
}
