<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\TracingRequests\Actions;

use App\Enums\TracingRequestNotificationStatus;
use App\Models\TracingRequestNotification;
use App\Services\Tracing\RecallBroadcastAckService;
use Filament\Actions\Action;
use Filament\Actions\Contracts\HasActions;
use App\Filament\Notifications\Notification;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Js;

final class RecallBroadcastAckLinkActions
{
    public static function rotateAckLinkAction(): Action
    {
        return Action::make('rotateRecallAckLink')
            ->label('Rotate ack link')
            ->icon(Heroicon::OutlinedArrowPath)
            ->visible(fn (TracingRequestNotification $record): bool => $record->ack_share_uuid !== null
                && $record->status !== TracingRequestNotificationStatus::Acknowledged)
            ->authorize(fn (TracingRequestNotification $record): bool => Gate::allows('manageAckLink', $record->tracingRequest))
            ->requiresConfirmation()
            ->modalHeading('Rotate acknowledgment link')
            ->modalDescription('Every link already shared with this partner stops working. Copy and send the new link manually if needed.')
            ->action(function (TracingRequestNotification $record, HasActions & HasSchemas $livewire): void {
                $ackService = app(RecallBroadcastAckService::class);
                $ackService->rotateAckLink($record);
                $url = $ackService->signedAckUrl($record->refresh());

                if (method_exists($livewire, 'js')) {
                    $livewire->js('window.navigator.clipboard.writeText('.Js::from($url).')');
                }

                if (method_exists($livewire, 'refreshRecord')) {
                    $livewire->refreshRecord();
                }

                Notification::make()
                    ->title('Acknowledgment link rotated')
                    ->body('Previously shared links no longer work. The new link was copied to your clipboard.')
                    ->success()
                    ->send();
            });
    }

    public static function revokeAckLinkAction(): Action
    {
        return Action::make('revokeRecallAckLink')
            ->label('Revoke ack link')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->visible(fn (TracingRequestNotification $record): bool => $record->ack_share_uuid !== null)
            ->authorize(fn (TracingRequestNotification $record): bool => Gate::allows('manageAckLink', $record->tracingRequest))
            ->requiresConfirmation()
            ->modalHeading('Revoke acknowledgment link')
            ->modalDescription('The partner can no longer open the acknowledgment page until a new recall notice is sent.')
            ->action(function (TracingRequestNotification $record, HasActions & HasSchemas $livewire): void {
                app(RecallBroadcastAckService::class)->revokeAckLink($record);

                if (method_exists($livewire, 'refreshRecord')) {
                    $livewire->refreshRecord();
                }

                Notification::make()
                    ->title('Acknowledgment link revoked')
                    ->body('The partner can no longer open the acknowledgment page.')
                    ->success()
                    ->send();
            });
    }
}
