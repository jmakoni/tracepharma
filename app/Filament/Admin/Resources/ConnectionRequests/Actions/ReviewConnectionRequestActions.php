<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ConnectionRequests\Actions;

use App\Actions\Integrations\ReviewConnectionApprovalRequest;
use App\Enums\ConnectionApprovalStatus;
use App\Filament\Notifications\Notification;
use App\Models\Admin;
use App\Models\ConnectionApprovalRequest;
use App\Support\Auth\Permissions;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Support\Exceptions\Halt;
use Illuminate\Support\Facades\Auth;
use Throwable;

final class ReviewConnectionRequestActions
{
    public static function approve(): Action
    {
        return Action::make('approve')
            ->label('Approve')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->visible(fn (ConnectionApprovalRequest $record): bool => $record->isPending()
                && Auth::guard('admin')->user()?->can(Permissions::TenantsManage) === true)
            ->requiresConfirmation()
            ->modalHeading('Approve connection')
            ->modalDescription('The connection can send and receive documents immediately after approval.')
            ->action(function (ConnectionApprovalRequest $record): void {
                $admin = Auth::guard('admin')->user();

                if (! $admin instanceof Admin) {
                    throw new Halt;
                }

                try {
                    app(ReviewConnectionApprovalRequest::class)->approve($record, $admin);
                } catch (Throwable $exception) {
                    Notification::make()
                        ->title('Approval failed')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();

                    throw new Halt;
                }

                Notification::make()
                    ->title('Connection approved')
                    ->body($record->connection_name.' can now move documents.')
                    ->success()
                    ->send();
            });
    }

    public static function suspend(): Action
    {
        return Action::make('suspend')
            ->label('Suspend')
            ->icon('heroicon-o-pause-circle')
            ->color('gray')
            ->visible(fn (ConnectionApprovalRequest $record): bool => $record->status === ConnectionApprovalStatus::Approved
                && Auth::guard('admin')->user()?->can(Permissions::TenantsManage) === true)
            ->modalHeading('Suspend connection')
            ->schema([
                Textarea::make('review_note')
                    ->label('Reason')
                    ->required()
                    ->rows(3)
                    ->helperText('Shown to the tenant. The connection cannot move documents until resumed.'),
            ])
            ->action(function (ConnectionApprovalRequest $record, array $data): void {
                $admin = Auth::guard('admin')->user();

                if (! $admin instanceof Admin) {
                    throw new Halt;
                }

                try {
                    app(ReviewConnectionApprovalRequest::class)->suspend(
                        $record,
                        $admin,
                        (string) ($data['review_note'] ?? ''),
                    );
                } catch (Throwable $exception) {
                    Notification::make()
                        ->title('Suspension failed')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();

                    throw new Halt;
                }

                Notification::make()
                    ->title('Connection suspended')
                    ->body($record->connection_name.' is blocked until resumed.')
                    ->success()
                    ->send();
            });
    }

    public static function resume(): Action
    {
        return Action::make('resume')
            ->label('Resume')
            ->icon('heroicon-o-play-circle')
            ->color('success')
            ->visible(fn (ConnectionApprovalRequest $record): bool => $record->status === ConnectionApprovalStatus::Suspended
                && Auth::guard('admin')->user()?->can(Permissions::TenantsManage) === true)
            ->requiresConfirmation()
            ->modalHeading('Resume connection')
            ->modalDescription('The connection can send and receive documents again immediately after resuming.')
            ->action(function (ConnectionApprovalRequest $record): void {
                $admin = Auth::guard('admin')->user();

                if (! $admin instanceof Admin) {
                    throw new Halt;
                }

                try {
                    app(ReviewConnectionApprovalRequest::class)->resume($record, $admin);
                } catch (Throwable $exception) {
                    Notification::make()
                        ->title('Resume failed')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();

                    throw new Halt;
                }

                Notification::make()
                    ->title('Connection resumed')
                    ->body($record->connection_name.' can move documents again.')
                    ->success()
                    ->send();
            });
    }

    public static function reject(): Action
    {
        return Action::make('reject')
            ->label('Reject')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn (ConnectionApprovalRequest $record): bool => $record->isPending()
                && Auth::guard('admin')->user()?->can(Permissions::TenantsManage) === true)
            ->modalHeading('Reject connection')
            ->schema([
                Textarea::make('review_note')
                    ->label('Reason')
                    ->required()
                    ->rows(3)
                    ->helperText('Shown to the tenant so they know what to fix.'),
            ])
            ->action(function (ConnectionApprovalRequest $record, array $data): void {
                $admin = Auth::guard('admin')->user();

                if (! $admin instanceof Admin) {
                    throw new Halt;
                }

                try {
                    app(ReviewConnectionApprovalRequest::class)->reject(
                        $record,
                        $admin,
                        (string) ($data['review_note'] ?? ''),
                    );
                } catch (Throwable $exception) {
                    Notification::make()
                        ->title('Rejection failed')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();

                    throw new Halt;
                }

                Notification::make()
                    ->title('Connection rejected')
                    ->body($record->connection_name.' stays blocked until the tenant resubmits.')
                    ->success()
                    ->send();
            });
    }
}
