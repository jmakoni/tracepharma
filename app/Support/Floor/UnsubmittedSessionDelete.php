<?php

namespace App\Support\Floor;

use App\Models\Receiving\ReceivingSession;
use App\Models\Shipping\OutboundShippingSession;
use App\Models\Transferring\TransferringSession;
use Filament\Forms\Components\TextInput;
use Illuminate\Validation\ValidationException;

/**
 * Shared eligibility and Filament confirm-phrase helpers for hard-deleting
 * unsubmitted floor scan sessions. Domain delete Actions stay separate.
 */
final class UnsubmittedSessionDelete
{
    public const CONFIRM_PHRASE = 'DELETE';

    public static function canHardDeleteReceiving(ReceivingSession $session): bool
    {
        if (! in_array($session->status, ['open', 'in_progress'], true)
            || $session->receiving_events_generated_at !== null
            || $session->receiving_epcis_document_id !== null) {
            return false;
        }

        if ($session->isTransferReceive() && $session->transferring_session_id !== null) {
            $receiveGeneratedAt = TransferringSession::query()
                ->whereKey($session->transferring_session_id)
                ->value('receive_events_generated_at');

            if ($receiveGeneratedAt !== null) {
                return false;
            }
        }

        return true;
    }

    public static function confirmedScanCountReceiving(ReceivingSession $session): int
    {
        return (int) $session->confirmed_parent_count + (int) $session->confirmed_child_count;
    }

    public static function canHardDeleteShipping(OutboundShippingSession $session): bool
    {
        return in_array($session->status, ['open', 'in_progress'], true)
            && $session->epcis_document_id === null;
    }

    public static function confirmedScanCountShipping(OutboundShippingSession $session): int
    {
        return (int) $session->confirmed_count;
    }

    public static function canHardDeleteTransfer(TransferringSession $session): bool
    {
        return $session->status === 'open'
            && $session->transfer_events_generated_at === null
            && $session->transfer_epcis_document_id === null;
    }

    public static function confirmedScanCountTransfer(TransferringSession $session): int
    {
        return (int) $session->confirmed_count;
    }

    /**
     * @return list<\Filament\Forms\Components\Component>
     */
    public static function confirmPhraseSchema(int $confirmedScanCount): array
    {
        if ($confirmedScanCount === 0) {
            return [];
        }

        return [
            TextInput::make('confirm_phrase')
                ->label('Type DELETE to confirm')
                ->required()
                ->autocomplete('off'),
        ];
    }

    public static function assertFilamentConfirmPhrase(int $confirmedScanCount, ?string $phrase, ?string $statePath = null): void
    {
        if ($confirmedScanCount === 0) {
            return;
        }

        if ($phrase !== self::CONFIRM_PHRASE) {
            $key = filled($statePath) ? "{$statePath}.confirm_phrase" : 'confirm_phrase';

            throw ValidationException::withMessages([
                $key => ['Type DELETE to confirm permanent deletion.'],
            ]);
        }
    }
}
