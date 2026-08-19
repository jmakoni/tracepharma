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
use App\Services\Quarantine\SupplierPortalService;
use Illuminate\Support\Facades\Notification;
use RuntimeException;

class SendDscsaExceptionEmail
{
    public function __construct(
        private readonly SupplierPortalService $supplierPortal,
        private readonly PlatformSupportNotificationDispatcher $platformDispatcher,
    ) {}

    /**
     * @return array{sent: bool, portal_url?: string, error?: string}
     */
    public function execute(ExceptionCase $case, User $actor): array
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

        $case->logActivity(
            ExceptionActivityKind::System,
            $actor,
            'DSCSA exception email sent to '.$partner->email.'.',
            ExceptionActivityVisibility::Partner,
            ['portal_url' => $portalUrl, 'recipient' => $partner->email],
        );

        return ['sent' => true, 'portal_url' => $portalUrl];
    }
}
