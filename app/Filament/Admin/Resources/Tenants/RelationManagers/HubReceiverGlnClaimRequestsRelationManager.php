<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Tenants\RelationManagers;

use App\Actions\Integrations\ReviewHubReceiverGlnClaimRequest;
use App\Enums\HubReceiverGlnClaimRequestStatus;
use App\Filament\Notifications\Notification;
use App\Filament\Support\RecordActionGroup;
use App\Models\Admin;
use App\Models\HubReceiverGlnClaimRequest;
use App\Support\Auth\Permissions;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Throwable;

class HubReceiverGlnClaimRequestsRelationManager extends RelationManager
{
    protected static string $relationship = 'hubReceiverGlnClaimRequests';

    protected static ?string $title = 'Receiver GLN claim requests';

    protected static ?string $recordTitleAttribute = 'gln';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(
                fn (Builder $query): Builder => $query->where(
                    'status',
                    HubReceiverGlnClaimRequestStatus::Pending,
                ),
            )
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('gln')
                    ->label('GLN')
                    ->copyable()
                    ->searchable(),
                TextColumn::make('provider')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'tracepharma' => 'TracePharma',
                        'systech' => 'Systech',
                        'unitrace' => 'UniTrace',
                        default => $state,
                    }),
                TextColumn::make('reason')
                    ->wrap()
                    ->limit(80),
                TextColumn::make('requested_by')
                    ->label('Requested by')
                    ->wrap(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(
                        fn (HubReceiverGlnClaimRequestStatus $state): string => $state->label(),
                    )
                    ->color(
                        fn (HubReceiverGlnClaimRequestStatus $state): string => $state->color(),
                    ),
                TextColumn::make('created_at')
                    ->label('Requested')
                    ->dateTime()
                    ->since()
                    ->tooltip(fn (HubReceiverGlnClaimRequest $record): string => $record->created_at?->toDateTimeString() ?? '—'),
            ])
            ->recordActions(RecordActionGroup::make([
                $this->approveAction(),
                $this->rejectAction(),
            ]))
            ->emptyStateHeading('No pending receiver GLN claim requests')
            ->emptyStateDescription('Tenant requests awaiting platform review will appear here.');
    }

    private function approveAction(): Action
    {
        return Action::make('approve')
            ->label('Approve')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->visible(fn (HubReceiverGlnClaimRequest $record): bool => $this->canReview($record))
            ->requiresConfirmation()
            ->modalHeading('Approve receiver GLN claim')
            ->modalDescription('Approval creates the active hub route for this tenant.')
            ->action(function (HubReceiverGlnClaimRequest $record): void {
                $admin = Auth::guard('admin')->user();

                if (! $admin instanceof Admin) {
                    throw new Halt;
                }

                try {
                    app(ReviewHubReceiverGlnClaimRequest::class)->approve($record, $admin);
                } catch (Throwable $exception) {
                    $this->reviewFailed('Approval failed', $exception);
                }

                Notification::make()
                    ->title('Receiver GLN claim approved')
                    ->body($record->gln.' is now routed to this tenant.')
                    ->success()
                    ->send();
            });
    }

    private function rejectAction(): Action
    {
        return Action::make('reject')
            ->label('Reject')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn (HubReceiverGlnClaimRequest $record): bool => $this->canReview($record))
            ->modalHeading('Reject receiver GLN claim')
            ->schema([
                Textarea::make('review_note')
                    ->label('Review note')
                    ->rows(3)
                    ->maxLength(2000)
                    ->helperText('Optional context for the requesting tenant.'),
            ])
            ->action(function (HubReceiverGlnClaimRequest $record, array $data): void {
                $admin = Auth::guard('admin')->user();

                if (! $admin instanceof Admin) {
                    throw new Halt;
                }

                try {
                    app(ReviewHubReceiverGlnClaimRequest::class)->reject(
                        $record,
                        $admin,
                        isset($data['review_note']) ? (string) $data['review_note'] : null,
                    );
                } catch (Throwable $exception) {
                    $this->reviewFailed('Rejection failed', $exception);
                }

                Notification::make()
                    ->title('Receiver GLN claim rejected')
                    ->body('No hub route was created.')
                    ->success()
                    ->send();
            });
    }

    private function canReview(HubReceiverGlnClaimRequest $request): bool
    {
        return $request->isPending()
            && Auth::guard('admin')->user()?->can(Permissions::TenantsManage) === true;
    }

    private function reviewFailed(string $title, Throwable $exception): never
    {
        Notification::make()
            ->title($title)
            ->body($exception->getMessage())
            ->danger()
            ->send();

        throw new Halt;
    }
}
