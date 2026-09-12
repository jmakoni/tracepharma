<?php

declare(strict_types=1);

namespace App\Actions\Exceptions;

use App\Enums\ExceptionActivityKind;
use App\Enums\ExceptionActivityVisibility;
use App\Models\Exceptions\ExceptionCase;
use App\Models\TradingPartner;
use App\Models\User;
use App\Notifications\DscsaExceptionSupplierMail;
use App\Services\Exceptions\PlatformSupportNotificationDispatcher;
use App\Services\Quarantine\QuarantineService;
use App\Services\Quarantine\SupplierPortalService;
use Illuminate\Support\Facades\Notification;
use RuntimeException;

class SendDscsaExceptionEmail
{
    public function __construct(
        private readonly SupplierPortalService $supplierPortal,
        private readonly QuarantineService $quarantine,
        private readonly PlatformSupportNotificationDispatcher $platformDispatcher,
    ) {}

    /**
     * Email the trading partner a supplier-portal link and ensure the case is
     * listed on that portal (share_uuid). Actor may be null for scheduled aging.
     *
     * @return array{sent: bool, portal_url?: string, error?: string}
     */
    public function execute(ExceptionCase $case, ?User $actor = null): array
    {
        $case->loadMissing('tradingPartner');
        $partner = $case->tradingPartner;

        if (! $partner instanceof TradingPartner) {
            return ['sent' => false, 'error' => 'This case has no trading partner.'];
        }

        if (blank($partner->email)) {
            return ['sent' => false, 'error' => 'Supplier contact email is required.'];
        }

        try {
            $this->quarantine->ensureShareLink($case);
            $portalUrl = $this->supplierPortal->signedPartnerExceptionsUrl($partner);
        } catch (RuntimeException $exception) {
            return ['sent' => false, 'error' => $exception->getMessage()];
        }

        try {
            Notification::route('mail', $partner->email)
                ->notifyNow(new DscsaExceptionSupplierMail($case, $portalUrl));
        } catch (\Throwable $throwable) {
            $this->platformDispatcher->dispatchForSupplierEmailFailure($case, $throwable->getMessage());

            return ['sent' => false, 'error' => $throwable->getMessage()];
        }

        $body = 'DSCSA exception email sent to '.$partner->email.'.';
        if ($actor === null) {
            $body .= ' Automated aging reminder.';
        }

        $case->logActivity(
            ExceptionActivityKind::System,
            $actor,
            $body,
            ExceptionActivityVisibility::Partner,
            array_filter([
                'portal_url' => $portalUrl,
                'recipient' => $partner->email,
                'source' => $actor === null ? 'aging_command' : null,
            ], static fn ($value) => $value !== null),
        );

        return ['sent' => true, 'portal_url' => $portalUrl];
    }
}
