<?php

namespace App\Filament\App\Resources\EpcisDocuments\RelationManagers;

use App\Enums\ExceptionTypeCategory;
use App\Filament\App\Resources\Exceptions\ExceptionResource;
use App\Filament\Support\RecordActionGroup;
use App\Models\Epcis\EpcisException;
use App\Models\Exceptions\ExceptionType;
use App\Models\User;
use App\Services\Exceptions\ExceptionService;
use App\Support\Epcis\Validation\EpcisValidationCatalog;
use App\Support\Exceptions\ExceptionCorrectionProfile;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Throwable;

class ExceptionsRelationManager extends RelationManager
{
    protected static string $relationship = 'exceptions';

    protected static ?string $title = 'Exceptions';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('case_id')
                    ->label('Case')
                    ->formatStateUsing(fn (?int $state): string => $state ? '#'.$state : '—')
                    ->url(fn (EpcisException $record): ?string => $record->case_id
                        ? ExceptionResource::getUrl('view', ['record' => $record->case_id], panel: 'app')
                        : null)
                    ->color(fn (EpcisException $record): ?string => $record->case_id ? 'primary' : null)
                    ->placeholder('—'),
                TextColumn::make('exception_type')
                    ->label('Type')
                    ->badge()
                    ->searchable(),
                TextColumn::make('severity')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'critical', 'error' => 'danger',
                        'warning' => 'warning',
                        default => 'gray',
                    })
                    ->placeholder('—'),
                TextColumn::make('status')
                    ->badge()
                    ->placeholder('—'),
                TextColumn::make('description')
                    ->limit(60)
                    ->tooltip(fn (?string $state): ?string => $state)
                    ->wrap()
                    ->placeholder('—'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'open' => 'Open',
                        'resolved' => 'Resolved',
                        'closed' => 'Closed',
                    ]),
                SelectFilter::make('severity')
                    ->options([
                        'error' => 'Error',
                        'warning' => 'Warning',
                        'info' => 'Info',
                    ]),
                SelectFilter::make('exception_type')
                    ->label('Type')
                    ->searchable()
                    ->options(fn (): array => $this->exceptionTypeOptions()),
            ], FiltersLayout::Modal)
            ->filtersFormColumns(3)
            ->filtersFormWidth(Width::FourExtraLarge)
            ->deferLoading()
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(25)
            ->emptyStateHeading('No exceptions')
            ->emptyStateDescription('This document has no recorded exceptions.')
            ->headerActions([])
            ->recordActions(RecordActionGroup::make([
                Action::make('openCase')
                    ->label('Open case')
                    ->icon(Heroicon::OutlinedFolderOpen)
                    ->visible(fn (EpcisException $record): bool => $record->case_id === null)
                    ->action(function (EpcisException $record) {
                        /** @var User|null $actor */
                        $actor = auth()->user();

                        try {
                            $case = app(ExceptionService::class)->createFromSignal($record, actor: $actor);
                        } catch (Throwable $e) {
                            Notification::make()
                                ->title('Could not open case')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title('Exception case opened')
                            ->body('Case #'.$case->getKey())
                            ->success()
                            ->send();

                        return redirect(ExceptionResource::getUrl('view', ['record' => $case], panel: 'app'));
                    }),
                Action::make('viewCase')
                    ->label('View case')
                    ->icon(Heroicon::OutlinedEye)
                    ->visible(fn (EpcisException $record): bool => $record->case_id !== null)
                    ->url(fn (EpcisException $record): string => ExceptionResource::getUrl(
                        'view',
                        ['record' => $record->case_id],
                        panel: 'app',
                    )),
            ]));
    }

    /**
     * Options for the "Type" filter, grouped by category so the searchable select stays
     * scannable. Prefers the live ExceptionType catalog (active codes, human-readable
     * names, GS1/DSCSA category grouping) and falls back to the static validation catalog
     * if the model/table isn't available. A "Legacy" group is always appended so rows still
     * carrying pre-catalog lowercase exception_type values remain filterable.
     *
     * @return array<string, array<string, string>>
     */
    private function exceptionTypeOptions(): array
    {
        $grouped = [];

        try {
            $types = ExceptionType::query()
                ->where('is_active', true)
                ->orderBy('category')
                ->orderBy('name')
                ->get(['code', 'name', 'category']);
        } catch (Throwable) {
            $types = null;
        }

        if ($types !== null && $types->isNotEmpty()) {
            foreach ($types as $type) {
                $code = (string) $type->code;

                if (ExceptionCorrectionProfile::isOperatorHiddenStubCode($code)) {
                    continue;
                }

                $category = $type->category instanceof ExceptionTypeCategory
                    ? $type->category->label()
                    : 'Other';

                $grouped[$category][$code] = (string) $type->name;
            }
        }

        if ($grouped === []) {
            foreach (EpcisValidationCatalog::all() as $code) {
                if (ExceptionCorrectionProfile::isOperatorHiddenStubCode($code)) {
                    continue;
                }

                $grouped['Validation'][$code] = str_replace('_', ' ', ucfirst(strtolower($code)));
            }
        }

        $grouped['Legacy (pre-catalog)'] = [
            'atp_soft_warning' => 'ATP soft warning',
            'sbdh_source_owning_party_mismatch' => 'SBDH / source owning party mismatch',
            'ingest_failure' => 'Ingest failure',
            'missing_biz_transaction' => 'Missing ASN / PO',
            'incomplete_product_master_data' => 'Incomplete product master data',
            'dropped_epc_uris' => 'Dropped EPC URIs',
            'missing_transaction_statement' => 'Missing DSCSA transaction statement',
        ];

        return $grouped;
    }
}
