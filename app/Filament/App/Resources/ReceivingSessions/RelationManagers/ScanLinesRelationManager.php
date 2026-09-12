<?php

namespace App\Filament\App\Resources\ReceivingSessions\RelationManagers;

use App\Actions\Receiving\UnconfirmReceivingScanLine;
use App\Filament\Notifications\Notification;
use App\Models\Receiving\ReceivingScanLine;
use App\Models\Receiving\ReceivingSession;
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
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
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
                    'epc.ilmd',
                ])
                // Parents + unexpected always; scan-first orphan units (no parent_epc_id).
                // ASN auto-confirmed children under a parent stay hidden.
                ->where(function (Builder $q): void {
                    $q->where('line_role', 'parent')
                        ->orWhere('status', 'unexpected')
                        ->orWhere(function (Builder $child): void {
                            $child->where('line_role', 'child')
                                ->whereNull('parent_epc_id');
                        });
                }))
            ->columns([
                TextColumn::make('identifier')
                    ->label('Identifier')
                    ->state(fn (ReceivingScanLine $record): string => $record->epc !== null
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
                    ->state(fn (ReceivingScanLine $record): bool => $record->status === 'confirmed')
                    ->trueIcon(Heroicon::OutlinedCheckCircle)
                    ->falseIcon(Heroicon::OutlinedXCircle)
                    ->trueColor('success')
                    ->falseColor('danger'),
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
            ->emptyStateDescription('Scan a pallet or unit barcode to start.')
            ->headerActions([])
            ->recordActions([
                Action::make('removeScan')
                    ->label('Delete')
                    ->icon(Heroicon::OutlinedTrash)
                    ->iconButton()
                    ->color('gray')
                    ->visible(fn (ReceivingScanLine $record): bool => $this->canRemoveScanLine($record))
                    ->requiresConfirmation()
                    ->modalHeading(fn (ReceivingScanLine $record): string => match (true) {
                        $record->status === 'unexpected' => 'Remove unexpected scan?',
                        $record->line_role === 'child' && $record->parent_epc_id === null => 'Unconfirm this unit?',
                        default => 'Unconfirm this pallet/case?',
                    })
                    ->modalDescription(fn (ReceivingScanLine $record): string => match (true) {
                        $record->status === 'unexpected' => 'Removes this unexpected scan from the session.',
                        $record->line_role === 'child' && $record->parent_epc_id === null => 'Removes this unit from the session.',
                        default => 'Unconfirm this pallet/case and remove its units from this session.',
                    })
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
