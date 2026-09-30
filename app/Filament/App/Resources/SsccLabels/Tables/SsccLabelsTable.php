<?php

namespace App\Filament\App\Resources\SsccLabels\Tables;

use App\Actions\Labeling\EnsureSsccLabelPdf;
use App\Enums\SsccAllocationMode;
use App\Enums\SsccLabelPrintStatus;
use App\Filament\App\Resources\SsccLabels\SsccLabelResource;
use App\Filament\Notifications\Notification;
use App\Filament\Support\RecordActionGroup;
use App\Models\SsccLabel;
use App\Support\Tables\DistinctColumnOptions;
use App\Support\Tracing\AssetTrackingUrl;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;
use Zvizvi\FilamentColumnFilters\Filters\ColumnFilter;

class SsccLabelsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                AssetTrackingUrl::linkScanColumn(
                    TextColumn::make('sscc_18')
                        ->label('SSCC-18')
                        ->searchable()
                        ->fontFamily(FontFamily::Mono)
                        ->columnFilter(
                            ColumnFilter::select()
                                ->attribute('sscc_18')
                                ->options(fn (): array => DistinctColumnOptions::of(SsccLabel::class, 'sscc_18')),
                        ),
                    fn (mixed $record): ?string => $record instanceof SsccLabel
                        ? AssetTrackingUrl::scanForSsccLabel([
                            'sscc18' => $record->sscc_18,
                            'element_string' => $record->element_string,
                        ])
                        : null,
                    copyable: true,
                ),
                TextColumn::make('serial_reference_int')
                    ->label('Serial ref')
                    ->sortable()
                    ->columnFilter(ColumnFilter::range()),
                TextColumn::make('allocation_mode')
                    ->badge()
                    ->formatStateUsing(fn (?SsccAllocationMode $state): string => $state?->label() ?? '—')
                    ->toggleable()
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('allocation_mode')
                            ->options(DistinctColumnOptions::enum(SsccAllocationMode::class)),
                    ),
                TextColumn::make('batch_id')
                    ->label('Batch')
                    ->url(fn (SsccLabel $record): ?string => $record->batch_id
                        ? SsccLabelResource::getUrl('view-batch', ['record' => $record->batch_id])
                        : null)
                    ->sortable()
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('batch_id')
                            ->options(fn (): array => DistinctColumnOptions::of(SsccLabel::class, 'batch_id')),
                    ),
                TextColumn::make('print_status')
                    ->badge()
                    ->formatStateUsing(fn (?SsccLabelPrintStatus $state): string => $state?->label() ?? '—')
                    ->color(fn (?SsccLabelPrintStatus $state): string => match ($state) {
                        SsccLabelPrintStatus::Failed => 'danger',
                        SsccLabelPrintStatus::Printed => 'success',
                        SsccLabelPrintStatus::Queued => 'info',
                        SsccLabelPrintStatus::Skipped => 'gray',
                        default => 'gray',
                    })
                    ->toggleable()
                    ->columnFilter(ColumnFilter::select()->syncWith('print_status')),
                TextColumn::make('ship_to_name')
                    ->label('Ship to')
                    ->placeholder('—')
                    ->searchable()
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('ship_to_name')
                            ->options(fn (): array => DistinctColumnOptions::of(SsccLabel::class, 'ship_to_name')),
                    ),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->columnFilter(ColumnFilter::date()),
            ])
            ->filters([
                SelectFilter::make('print_status')
                    ->options(collect(SsccLabelPrintStatus::cases())->mapWithKeys(
                        fn (SsccLabelPrintStatus $status): array => [$status->value => $status->label()]
                    )),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(25)
            ->extremePaginationLinks()
            ->recordActions(RecordActionGroup::make([
                ViewAction::make()
                    ->slideOver()
                    ->extraModalFooterActions(fn (ViewAction $action): array => [
                        Action::make('downloadPdf')
                            ->label('Download PDF')
                            ->icon('heroicon-o-arrow-down-tray')
                            ->visible(fn (): bool => filled($action->getRecord()?->label_path) && filled($action->getRecord()?->label_disk)
                                || filled($action->getRecord()?->sscc_18))
                            ->action(function () use ($action): ?StreamedResponse {
                                /** @var SsccLabel $record */
                                $record = $action->getRecord();

                                return self::downloadLabelPdf($record);
                            }),
                    ]),
                Action::make('downloadPdf')
                    ->label('Download PDF')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->visible(fn (SsccLabel $record): bool => filled($record->sscc_18))
                    ->action(fn (SsccLabel $record): ?StreamedResponse => self::downloadLabelPdf($record)),
                Action::make('viewBatch')
                    ->label('View batch')
                    ->icon('heroicon-o-rectangle-stack')
                    ->url(fn (SsccLabel $record): ?string => $record->batch_id
                        ? SsccLabelResource::getUrl('view-batch', ['record' => $record->batch_id])
                        : null)
                    ->visible(fn (SsccLabel $record): bool => $record->batch_id !== null),
                Action::make('reprintFromBatch')
                    ->label('Reprint')
                    ->icon('heroicon-o-printer')
                    ->url(fn (SsccLabel $record): ?string => $record->batch_id
                        ? SsccLabelResource::getUrl('view-batch', ['record' => $record->batch_id]).'#reprint'
                        : null)
                    ->visible(fn (SsccLabel $record): bool => $record->batch_id !== null
                        && in_array($record->print_status, [
                            SsccLabelPrintStatus::Failed,
                            SsccLabelPrintStatus::Printed,
                            SsccLabelPrintStatus::Queued,
                            SsccLabelPrintStatus::Pending,
                        ], true)),
            ]));
    }

    private static function downloadLabelPdf(SsccLabel $record): ?StreamedResponse
    {
        try {
            $record = app(EnsureSsccLabelPdf::class)->handle($record);
        } catch (Throwable $exception) {
            Notification::make()
                ->title('PDF unavailable')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return null;
        }

        return Storage::disk($record->label_disk)->download(
            $record->label_path,
            "sscc-{$record->sscc_18}.pdf",
            ['Content-Type' => 'application/pdf'],
        );
    }
}
