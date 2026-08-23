<?php

namespace App\Filament\Support\Floor;

use App\Models\Receiving\ReceivingSession;
use App\Models\Shipping\OutboundShippingSession;
use App\Models\Transferring\TransferringSession;
use App\Support\Floor\UnsubmittedSessionDelete;
use DomainException;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Throwable;

final class UnsubmittedSessionDeleteAction
{
    /**
     * @param  callable(ReceivingSession): void  $delete
     */
    public static function forReceiving(callable $delete, string $redirectUrl): Action
    {
        return Action::make('deleteReceiving')
            ->label('Delete')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->visible(fn (ReceivingSession $record): bool => $record->canHardDelete())
            ->requiresConfirmation()
            ->modalHeading('Delete this receive permanently?')
            ->modalDescription(fn (ReceivingSession $record): string => self::modalDescription(
                UnsubmittedSessionDelete::confirmedScanCountReceiving($record),
                'receive session and all scan lines',
            ))
            ->modalSubmitActionLabel('Delete permanently')
            ->schema(fn (ReceivingSession $record): array => UnsubmittedSessionDelete::confirmPhraseSchema(
                UnsubmittedSessionDelete::confirmedScanCountReceiving($record),
            ))
            ->action(function (ReceivingSession $record, array $data, ?Schema $schema = null) use ($delete, $redirectUrl): mixed {
                UnsubmittedSessionDelete::assertFilamentConfirmPhrase(
                    UnsubmittedSessionDelete::confirmedScanCountReceiving($record),
                    $data['confirm_phrase'] ?? null,
                    $schema?->getStatePath(),
                );

                return self::runDelete(
                    fn () => $delete($record),
                    'Receive deleted',
                    'Receive permanently removed.',
                    $redirectUrl,
                );
            });
    }

    /**
     * @param  callable(): bool  $visible
     * @param  callable(): int  $confirmedCount
     * @param  callable(array<string, mixed>): mixed  $delete
     */
    public static function forReceivingHud(
        callable $visible,
        callable $confirmedCount,
        callable $delete,
        string $redirectUrl,
    ): Action {
        return Action::make('deleteReceiving')
            ->label('Delete receive')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->visible($visible)
            ->requiresConfirmation()
            ->modalHeading('Delete this receive permanently?')
            ->modalDescription(fn (): string => self::modalDescription(
                $confirmedCount(),
                'receive session and all scan lines',
            ))
            ->modalSubmitActionLabel('Delete permanently')
            ->schema(fn (): array => UnsubmittedSessionDelete::confirmPhraseSchema($confirmedCount()))
            ->action(function (array $data, ?Schema $schema = null) use ($confirmedCount, $delete, $redirectUrl): mixed {
                UnsubmittedSessionDelete::assertFilamentConfirmPhrase(
                    $confirmedCount(),
                    $data['confirm_phrase'] ?? null,
                    $schema?->getStatePath(),
                );

                return self::runDelete(
                    fn () => $delete($data),
                    'Receive deleted',
                    'Receive permanently removed.',
                    $redirectUrl,
                );
            });
    }

    /**
     * @param  callable(OutboundShippingSession): void  $delete
     */
    public static function forShipping(callable $delete, string $redirectUrl): Action
    {
        return Action::make('deleteShipOrder')
            ->label('Delete')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->visible(fn (OutboundShippingSession $record): bool => $record->canHardDelete())
            ->requiresConfirmation()
            ->modalHeading('Delete this ship order permanently?')
            ->modalDescription(fn (OutboundShippingSession $record): string => self::modalDescription(
                UnsubmittedSessionDelete::confirmedScanCountShipping($record),
                'ship order and all scan lines',
            ))
            ->modalSubmitActionLabel('Delete permanently')
            ->schema(fn (OutboundShippingSession $record): array => UnsubmittedSessionDelete::confirmPhraseSchema(
                UnsubmittedSessionDelete::confirmedScanCountShipping($record),
            ))
            ->action(function (OutboundShippingSession $record, array $data, ?Schema $schema = null) use ($delete, $redirectUrl): mixed {
                UnsubmittedSessionDelete::assertFilamentConfirmPhrase(
                    UnsubmittedSessionDelete::confirmedScanCountShipping($record),
                    $data['confirm_phrase'] ?? null,
                    $schema?->getStatePath(),
                );

                return self::runDelete(
                    fn () => $delete($record),
                    'Ship order deleted',
                    'Ship order permanently removed.',
                    $redirectUrl,
                );
            });
    }

    /**
     * @param  callable(TransferringSession): void  $delete
     */
    public static function forTransfer(callable $delete, string $redirectUrl): Action
    {
        return Action::make('deleteTransfer')
            ->label('Delete')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->visible(fn (TransferringSession $record): bool => $record->canHardDelete()
                && (auth()->user()?->can('delete', $record) ?? false))
            ->requiresConfirmation()
            ->modalHeading('Delete this transfer permanently?')
            ->modalDescription(fn (TransferringSession $record): string => self::modalDescription(
                UnsubmittedSessionDelete::confirmedScanCountTransfer($record),
                'transfer session and all scan lines',
            ))
            ->modalSubmitActionLabel('Delete permanently')
            ->schema(fn (TransferringSession $record): array => UnsubmittedSessionDelete::confirmPhraseSchema(
                UnsubmittedSessionDelete::confirmedScanCountTransfer($record),
            ))
            ->action(function (TransferringSession $record, array $data, ?Schema $schema = null) use ($delete, $redirectUrl): mixed {
                UnsubmittedSessionDelete::assertFilamentConfirmPhrase(
                    UnsubmittedSessionDelete::confirmedScanCountTransfer($record),
                    $data['confirm_phrase'] ?? null,
                    $schema?->getStatePath(),
                );

                return self::runDelete(
                    fn () => $delete($record),
                    'Transfer deleted',
                    'Transfer permanently removed.',
                    $redirectUrl,
                );
            });
    }

    private static function modalDescription(int $confirmedScanCount, string $subject): string
    {
        if ($confirmedScanCount === 0) {
            return "Permanently removes this {$subject}. This cannot be undone.";
        }

        return "Permanently removes this {$subject} ({$confirmedScanCount} confirmed scan(s)). Type DELETE to confirm. This cannot be undone.";
    }

    /**
     * @param  callable(): void  $delete
     */
    private static function runDelete(
        callable $delete,
        string $title,
        string $body,
        ?string $redirectUrl,
    ): mixed {
        try {
            $delete();
        } catch (DomainException $e) {
            Notification::make()
                ->title('Delete blocked')
                ->body($e->getMessage())
                ->danger()
                ->send();

            return null;
        } catch (Throwable $e) {
            if ($e instanceof \Illuminate\Auth\Access\AuthorizationException) {
                throw $e;
            }

            Notification::make()
                ->title('Delete blocked')
                ->body($e->getMessage())
                ->danger()
                ->send();

            return null;
        }

        Notification::make()
            ->title($title)
            ->body($body)
            ->success()
            ->send();

        if ($redirectUrl !== '') {
            return redirect($redirectUrl);
        }

        return null;
    }
}
