<?php

namespace App\Support\Shipping;

use App\Enums\ExceptionStatus;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Exceptions\ExceptionType;
use App\Models\Shipping\OutboundShippingScanLine;
use App\Models\Shipping\OutboundShippingSession;
use App\Services\Exceptions\ExceptionService;
use Database\Seeders\ExceptionTypeSeeder;

/**
 * Open (once per ship order) a QUANTITY_MISMATCH reconciliation case so quantity
 * gaps — blocked sends, declared partials — stay visible in the exceptions queue.
 */
final class OpenShipOrderQuantityCase
{
    public const FINGERPRINT_SUFFIX = '-qty';

    public function handle(
        OutboundShippingSession $session,
        string $message,
        int $expected,
        int $confirmed,
    ): void {
        $type = ExceptionType::query()->where('code', 'QUANTITY_MISMATCH')->first();

        if ($type === null) {
            (new ExceptionTypeSeeder)->run();
            $type = ExceptionType::query()->where('code', 'QUANTITY_MISMATCH')->first();
        }

        if ($type === null) {
            return;
        }

        $fingerprint = 'ship-order-#'.$session->getKey().self::FINGERPRINT_SUFFIX;

        $alreadyOpen = ExceptionCase::query()
            ->where('exception_type_id', $type->getKey())
            ->whereNotIn('status', [
                ExceptionStatus::Resolved->value,
                ExceptionStatus::Closed->value,
                ExceptionStatus::Cancelled->value,
            ])
            ->where('description', 'like', '%'.$fingerprint.'%')
            ->exists();

        if ($alreadyOpen) {
            return;
        }

        $epcIds = OutboundShippingScanLine::query()
            ->where('outbound_shipping_session_id', $session->getKey())
            ->where('status', 'confirmed')
            ->pluck('epc_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        app(ExceptionService::class)->create([
            'exception_type_id' => $type->getKey(),
            'document_id' => null,
            'site_id' => $session->site_id,
            'trading_partner_id' => $session->trading_partner_id,
            'title' => $type->name,
            'description' => $message.' ['.$fingerprint.'; expected='.$expected.'; confirmed='.$confirmed.']',
            'status' => ExceptionStatus::New->value,
        ], $epcIds);
    }
}
