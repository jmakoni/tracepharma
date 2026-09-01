<?php

namespace App\Filament\Admin\Resources\Tenants\Tables;

use App\Actions\Tenants\DeleteTenantPair;
use App\Filament\Support\RecordActionGroup;
use App\Models\Tenant;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use App\Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Throwable;

class TenantsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('domains.domain')
                    ->label('Host')
                    ->searchable()
                    ->copyable()
                    ->placeholder('—'),
                TextColumn::make('tenant_pair_environment')
                    ->label('Access')
                    ->badge()
                    ->placeholder('—'),
                TextColumn::make('profile')->badge()->sortable(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('inbound_environment')
                    ->label('Inbound env')
                    ->badge()
                    ->toggleable(),
                TextColumn::make('hub_providers')
                    ->label('Hub providers')
                    ->formatStateUsing(function (mixed $state): string {
                        if (! is_array($state) || $state === []) {
                            return '—';
                        }

                        return implode(', ', $state);
                    })
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('gln')->label('GLN')->copyable()->toggleable(),
                TextColumn::make('created_at')->dateTime()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('inbound_environment')
                    ->label('Inbound environment')
                    ->options([
                        'demo' => 'Demo',
                        'stage' => 'Stage',
                        'prod' => 'Prod',
                    ]),
            ])
            ->recordActions(RecordActionGroup::make([
                EditAction::make(),
            ]))
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->modalDescription(fn (Collection $selectedRecords): string => app(DeleteTenantPair::class)->bulkDeleteModalDescription($selectedRecords))
                        ->schema(fn (Collection $selectedRecords): array => app(DeleteTenantPair::class)->bulkDeleteModalSchema($selectedRecords))
                        ->before(function (Collection $selectedRecords, array $data): void {
                            try {
                                app(DeleteTenantPair::class)->assertBulkDeleteAllowed($selectedRecords, $data);
                            } catch (\DomainException $exception) {
                                Notification::make()
                                    ->title('Delete blocked')
                                    ->body($exception->getMessage())
                                    ->danger()
                                    ->send();

                                throw new Halt;
                            }
                        })
                        ->using(function (DeleteBulkAction $action, EloquentCollection | Collection $records): void {
                            $deletePair = app(DeleteTenantPair::class);
                            $selectedIds = $records
                                ->map(fn (mixed $record): ?string => $record instanceof Tenant ? (string) $record->id : null)
                                ->filter()
                                ->values()
                                ->all();

                            /** @var list<string> $processedIds */
                            $processedIds = [];
                            $isFirstException = true;

                            foreach ($records as $record) {
                                if (! $record instanceof Tenant || in_array($record->id, $processedIds, true)) {
                                    continue;
                                }

                                try {
                                    $processedIds = array_merge(
                                        $processedIds,
                                        $deletePair->deleteWithSibling($record, $selectedIds),
                                    );
                                } catch (Throwable $exception) {
                                    $tenantLabel = trim($record->name) !== ''
                                        ? "{$record->name} ({$record->id})"
                                        : (string) $record->id;

                                    $action->reportBulkProcessingFailure(
                                        (string) $record->id,
                                        "{$tenantLabel}: {$exception->getMessage()}",
                                    );

                                    if ($isFirstException) {
                                        report($exception);
                                        $isFirstException = false;
                                    }
                                }
                            }
                        })
                        ->failureNotificationBody(function (DeleteBulkAction $action, int $successCount, int $totalCount): ?string {
                            $messages = $action->getBulkProcessingFailureMessages();

                            if ($messages === []) {
                                return null;
                            }

                            $lines = [];

                            if ($successCount > 0) {
                                $lines[] = "Deleted {$successCount} of {$totalCount} selected tenant(s).";
                            }

                            foreach ($messages as $message) {
                                $lines[] = $message;
                            }

                            return implode('', array_map(
                                static fn (string $line): string => "<p>{$line}</p>",
                                $lines,
                            ));
                        }),
                ]),
            ]);
    }
}
