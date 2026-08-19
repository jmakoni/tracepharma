<?php

declare(strict_types=1);

namespace App\Services\Vrs;

use App\Actions\Epcis\ResolveProductFromIdentifier;
use App\Actions\MasterData\EnsureManufacturerPartnerFromCatalog;
use App\Enums\PartnerType;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Fda\FdaProductPackaging;
use App\Models\TradingPartner;
use App\Models\Verification;
use App\Notifications\ManufacturerVerificationFailed;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Queues manufacturer email when an outbound VRS verify opens a failed/suspect case.
 */
final class ManufacturerVerificationNotifier
{
    public function __construct(
        private readonly ResolveProductFromIdentifier $resolveProduct,
        private readonly EnsureManufacturerPartnerFromCatalog $ensureManufacturerPartner,
    ) {}

    public function notifyIfApplicable(Verification $verification, ExceptionCase $exception): void
    {
        $email = $this->resolveManufacturerEmail($verification->gtin14);

        if ($email === null) {
            return;
        }

        Notification::route('mail', $email)
            ->notify(new ManufacturerVerificationFailed($verification, $exception));
    }

    public function resolveManufacturerEmail(string $gtin14): ?string
    {
        $partner = $this->resolveManufacturerPartner($gtin14);

        if ($partner === null) {
            return null;
        }

        if (filled($partner->vrs_notify_email)) {
            return trim((string) $partner->vrs_notify_email);
        }

        if ($partner->partner_type === PartnerType::Manufacturer && filled($partner->email)) {
            return trim((string) $partner->email);
        }

        return null;
    }

    private function resolveManufacturerPartner(string $gtin14): ?TradingPartner
    {
        $productPartner = $this->resolveProductManufacturerPartner($gtin14);

        $organizationId = $this->resolveFdaOrganizationIdFromGtin($gtin14);
        if ($organizationId !== null) {
            $fdaPartner = $this->ensureManufacturerPartner->handle($organizationId);

            if ($fdaPartner !== null && $fdaPartner->is_active) {
                if (
                    $productPartner !== null
                    && (int) $productPartner->getKey() !== (int) $fdaPartner->getKey()
                ) {
                    Log::info('VRS manufacturer notify: product trading partner differs from FDA labeler; using FDA labeler.', [
                        'gtin14' => $gtin14,
                        'product_trading_partner_id' => $productPartner->getKey(),
                        'fda_labeler_trading_partner_id' => $fdaPartner->getKey(),
                    ]);
                }

                return $fdaPartner;
            }
        }

        return $productPartner;
    }

    private function resolveProductManufacturerPartner(string $gtin14): ?TradingPartner
    {
        $product = $this->resolveProduct->handle($gtin14);

        if ($product === null) {
            return null;
        }

        $product->loadMissing('tradingPartner');
        $partner = $product->tradingPartner;

        if (
            $partner !== null
            && $partner->partner_type === PartnerType::Manufacturer
            && $partner->is_active
        ) {
            return $partner;
        }

        return null;
    }

    private function resolveFdaOrganizationIdFromGtin(string $gtin14): ?int
    {
        $normalized = str_pad(preg_replace('/\D+/', '', $gtin14) ?? '', 14, '0', STR_PAD_LEFT);

        $packaging = FdaProductPackaging::query()
            ->where(function ($query) use ($gtin14, $normalized): void {
                $query->where('gtin', $gtin14)
                    ->orWhere('gtin', $normalized);
            })
            ->with('product:id,fda_organization_id')
            ->first();

        $organizationId = $packaging?->product?->fda_organization_id;

        return $organizationId !== null ? (int) $organizationId : null;
    }
}
