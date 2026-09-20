<?php

namespace App\Support\Floor;

use App\Models\Disposition\DispositionSession;
use App\Models\Packing\PackingSession;
use App\Models\Receiving\ReceivingSession;
use App\Models\Shipping\OutboundShippingSession;
use App\Models\Transferring\TransferringSession;

/**
 * Sessions the caller is allowed to touch while checking serial exclusivity.
 */
final class ExclusiveSessionContext
{
    public function __construct(
        public ?ReceivingSession $receiving = null,
        public ?OutboundShippingSession $shipping = null,
        public ?TransferringSession $transferring = null,
        public ?PackingSession $packing = null,
        public ?DispositionSession $disposition = null,
    ) {}

    public static function forReceiving(ReceivingSession $session): self
    {
        $transfer = null;
        if ($session->transferring_session_id !== null) {
            $transfer = $session->relationLoaded('transferringSession')
                ? $session->transferringSession
                : TransferringSession::query()->find($session->transferring_session_id);
        }

        return new self(
            receiving: $session,
            transferring: $transfer instanceof TransferringSession ? $transfer : null,
        );
    }

    public static function forShipping(OutboundShippingSession $session): self
    {
        return new self(shipping: $session);
    }

    public static function forTransferring(TransferringSession $session): self
    {
        return new self(transferring: $session);
    }

    public static function forPacking(PackingSession $session): self
    {
        return new self(packing: $session);
    }

    public static function forOptionalPacking(?PackingSession $session): self
    {
        return $session !== null ? self::forPacking($session) : self::none();
    }

    public static function forDisposition(DispositionSession $session): self
    {
        return new self(disposition: $session);
    }

    public static function forOptionalDisposition(?DispositionSession $session): self
    {
        return $session !== null ? self::forDisposition($session) : self::none();
    }

    public static function none(): self
    {
        return new self;
    }
}
