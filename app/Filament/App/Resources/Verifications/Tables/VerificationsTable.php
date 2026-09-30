<?php

namespace App\Filament\App\Resources\Verifications\Tables;

use App\Models\User;
use App\Models\Verification;
use App\Support\Tables\DistinctColumnOptions;
use App\Support\Tracing\AssetTrackingUrl;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Zvizvi\FilamentColumnFilters\Filters\ColumnFilter;

class VerificationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->select([
                'id',
                'gtin14',
                'serial',
                'lot',
                'status',
                'scanned_barcode',
                'message',
                'response_payload',
                'verified_by',
                'created_at',
            ]))
            ->columns([
                TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime()
                    ->sortable()
                    ->columnFilter(ColumnFilter::date()),
                AssetTrackingUrl::linkScanColumn(
                    TextColumn::make('gtin14')
                        ->label('GTIN')
                        ->searchable()
                        ->fontFamily(FontFamily::Mono)
                        ->columnFilter(
                            ColumnFilter::select()
                                ->attribute('gtin14')
                                ->options(fn (): array => DistinctColumnOptions::of(Verification::class, 'gtin14')),
                        ),
                    function (mixed $record): ?string {
                        if (! $record instanceof Model) {
                            return null;
                        }

                        return filled($record->scanned_barcode)
                            ? (string) $record->scanned_barcode
                            : AssetTrackingUrl::scanForGtinSerial(
                                filled($record->gtin14) ? (string) $record->gtin14 : null,
                                filled($record->serial) ? (string) $record->serial : null,
                            );
                    },
                    copyable: true,
                ),
                AssetTrackingUrl::linkScanColumn(
                    TextColumn::make('scanned_barcode')
                        ->label('Barcode')
                        ->toggleable(isToggledHiddenByDefault: true)
                        ->limit(36)
                        ->tooltip(fn (?string $state): ?string => $state)
                        ->placeholder('—')
                        ->columnFilter(
                            ColumnFilter::select()
                                ->attribute('scanned_barcode')
                                ->options(fn (): array => DistinctColumnOptions::of(Verification::class, 'scanned_barcode')),
                        ),
                    fn (mixed $record): ?string => $record instanceof Model && filled($record->scanned_barcode)
                        ? (string) $record->scanned_barcode
                        : null,
                    copyable: true,
                ),
                AssetTrackingUrl::linkScanColumn(
                    TextColumn::make('serial')
                        ->label('Serial')
                        ->searchable()
                        ->copyable()
                        ->fontFamily(FontFamily::Mono)
                        ->columnFilter(
                            ColumnFilter::select()
                                ->attribute('serial')
                                ->options(fn (): array => DistinctColumnOptions::of(Verification::class, 'serial')),
                        ),
                    function (mixed $record): ?string {
                        if (! $record instanceof Model) {
                            return null;
                        }

                        return filled($record->scanned_barcode)
                            ? (string) $record->scanned_barcode
                            : AssetTrackingUrl::scanForGtinSerial(
                                filled($record->gtin14) ? (string) $record->gtin14 : null,
                                filled($record->serial) ? (string) $record->serial : null,
                            );
                    },
                ),
                TextColumn::make('status')
                    ->label('Result')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'verified' => 'Verified',
                        'failed' => 'Failed',
                        'suspect' => 'Suspect',
                        'deferred' => 'Deferred',
                        'error' => 'Error',
                        default => filled($state) ? ucfirst($state) : '—',
                    })
                    ->color(fn (?string $state): string => match ($state) {
                        'verified' => 'success',
                        'deferred', 'suspect' => 'warning',
                        'failed', 'error' => 'danger',
                        default => 'gray',
                    })
                    ->sortable()
                    ->columnFilter(ColumnFilter::select()->syncWith('status')),
                TextColumn::make('verifiedByUser.name')
                    ->label('Verified by')
                    ->placeholder('—')
                    ->toggleable()
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('verified_by')
                            ->options(fn (): array => DistinctColumnOptions::related(
                                Verification::class,
                                'verified_by',
                                User::class,
                            )),
                    ),
                TextColumn::make('disposition')
                    ->label('Disposition')
                    ->placeholder('—')
                    ->state(function ($record): ?string {
                        $payload = $record->response_payload;

                        if (! is_array($payload)) {
                            return null;
                        }

                        $disposition = $payload['disposition'] ?? null;

                        return filled($disposition) ? (string) $disposition : null;
                    }),
                TextColumn::make('message')
                    ->label('Message')
                    ->limit(48)
                    ->tooltip(fn (?string $state): ?string => $state)
                    ->placeholder('—')
                    ->toggleable()
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('message')
                            ->options(fn (): array => DistinctColumnOptions::of(Verification::class, 'message')),
                    ),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label('Result')
                    ->options([
                        'verified' => 'Verified',
                        'failed' => 'Failed',
                        'suspect' => 'Suspect',
                        'deferred' => 'Deferred',
                        'error' => 'Error',
                    ]),
                Filter::make('created_at')
                    ->label('Date')
                    ->schema([
                        DatePicker::make('from')
                            ->label('From'),
                        DatePicker::make('until')
                            ->label('Until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                filled($data['from'] ?? null),
                                fn (Builder $q): Builder => $q->whereDate('created_at', '>=', $data['from']),
                            )
                            ->when(
                                filled($data['until'] ?? null),
                                fn (Builder $q): Builder => $q->whereDate('created_at', '<=', $data['until']),
                            );
                    }),
            ])
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(25)
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([]);
    }
}
