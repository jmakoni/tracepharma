<?php

namespace App\Filament\Admin\Resources\Tenants\Tables;

use App\Actions\Tenants\ActivateTenant;
use App\Actions\Tenants\DeleteTenantPair;
use App\Actions\Tenants\SuspendTenant;
use App\Enums\TenantProfile;
use App\Filament\Notifications\Notification;
use App\Filament\Support\RecordActionGroup;
use App\Models\Tenant;
use App\Support\Tables\DistinctColumnOptions;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Throwable;
use Zvizvi\FilamentColumnFilters\Filters\ColumnFilter;

class TenantsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('name')
                            ->options(fn (): array => DistinctColumnOptions::of(Tenant::class, 'name')),
                    ),
                TextColumn::make('domains.domain')
                    ->label('Host')
                    ->searchable()
                    ->copyable()
                    ->placeholder('—'),
                TextColumn::make('tenant_pair_environment')
                    ->label('Access')
                    ->badge()
                    ->placeholder('—'),
                TextColumn::make('profile')
                    ->badge()
                    ->sortable()
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('profile')
                            ->options(DistinctColumnOptions::enum(TenantProfile::class)),
                    ),
                TextColumn::make('status')
                    ->badge()
                    ->sortable()
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('status')
                            ->options(fn (): array => DistinctColumnOptions::of(Tenant::class, 'status')),
                    ),
                TextColumn::make('inbound_environment')
                    ->label('Inbound env')
                    ->badge()
                    ->toggleable()
                    ->columnFilter(ColumnFilter::select()->syncWith('inbound_environment')),
                TextColumn::make('hub_providers')
                    ->label('Hub providers')
                    ->formatStateUsing(function (mixed $state): string {
                        if (! is_array($state) || $state === []) {
                            return '—';
                        }

                        return implode(', ', $state);
                    })
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('gln')
                    ->label('GLN')
                    ->copyable()
                    ->toggleable()
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('gln')
                            ->options(fn (): array => DistinctColumnOptions::of(Tenant::class, 'gln')),
                    ),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->columnFilter(ColumnFilter::date()),
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
                Action::make('suspendTenant')
                    ->label('Suspend')
                    ->icon('heroicon-o-pause-circle')
                    ->color('danger')
                    ->visible(fn (Tenant $record): bool => $record->status !== 'suspended')
                    ->schema([
                        Textarea::make('reason')
                            ->label('Suspension reason')
                            ->required()
                            ->rows(3)
                            ->maxLength(500)
                            ->helperText('Recorded in the platform audit trail. Cascades to the pair sibling.'),
                    ])
                    ->action(function (Tenant $record, array $data): void {
                        try {
                            app(SuspendTenant::class)->handle(
                                $record,
                                (string) ($data['reason'] ?? ''),
                                Auth::guard('admin')->user(),
                            );
                        } catch (Throwable $exception) {
                            Notification::make()
                                ->title('Suspend failed')
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();

                            throw new Halt;
                        }

                        Notification::make()
                            ->title('Tenant suspended')
                            ->body($record->name.' and its pair sibling are suspended.')
                            ->success()
                            ->send();
                    }),
                Action::make('activateTenant')
                    ->label('Activate')
                    ->icon('heroicon-o-play-circle')
                    ->color('success')
                    ->visible(fn (Tenant $record): bool => $record->status === 'suspended')
                    ->requiresConfirmation()
                    ->modalDescription('Reactivates this tenant and its pair sibling. The suspension reason is cleared.')
                    ->action(function (Tenant $record): void {
                        try {
                            app(ActivateTenant::class)->handle($record, Auth::guard('admin')->user());
                        } catch (Throwable $exception) {
                            Notification::make()
                                ->title('Activate failed')
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();

                            throw new Halt;
                        }

                        Notification::make()
                            ->title('Tenant activated')
                            ->success()
                            ->send();
                    }),
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
                        ->using(function (DeleteBulkAction $action, EloquentCollection|Collection $records): void {
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
