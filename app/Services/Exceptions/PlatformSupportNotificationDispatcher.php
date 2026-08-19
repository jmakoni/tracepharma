<?php

declare(strict_types=1);

namespace App\Services\Exceptions;

use App\Enums\ExceptionSeverity;
use App\Models\Exceptions\ExceptionCase;
use App\Notifications\EpcisJobFailedPlatformAlert;
use App\Notifications\PlatformExceptionSupportAlert;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

class PlatformSupportNotificationDispatcher
{
    public function dispatch(ExceptionCase $exception, string $reason, int $throttleHours = 24): void
    {
        $email = config('tracepharma.platform_support_email');

        if (! is_string($email) || $email === '') {
            return;
        }

        $cacheKey = 'platform_support_alert:'.$exception->id.':'.md5($reason);

        if (Cache::has($cacheKey)) {
            return;
        }

        Notification::route('mail', $email)
            ->notify(new PlatformExceptionSupportAlert($exception, $reason));

        if ($throttleHours > 0) {
            Cache::put($cacheKey, now()->toIso8601String(), now()->addHours($throttleHours));
        }
    }

    public function dispatchForCriticalException(ExceptionCase $exception): void
    {
        if ($exception->severity === ExceptionSeverity::Critical) {
            $this->dispatch($exception, 'Critical-tier exception');
        }
    }

    public function dispatchForSupplierEmailFailure(ExceptionCase $exception, string $errorMessage): void
    {
        $this->dispatch(
            $exception,
            'Supplier correction email failed: '.$errorMessage,
            throttleHours: 6,
        );
    }

    public function dispatchForEscalation(ExceptionCase $exception): void
    {
        $this->dispatch($exception, 'Tenant escalated exception to TracePharma support', throttleHours: 0);
    }

    public function dispatchForEpcisJobFailure(string $tenantId, int $documentId, string $errorMessage): void
    {
        $email = config('tracepharma.platform_support_email');

        if (! is_string($email) || $email === '') {
            return;
        }

        $cacheKey = 'platform_support_epcis_fail:'.$tenantId.':'.$documentId;

        if (Cache::has($cacheKey)) {
            return;
        }

        Notification::route('mail', $email)
            ->notify(new EpcisJobFailedPlatformAlert($tenantId, $documentId, $errorMessage));

        Cache::put($cacheKey, now()->toIso8601String(), now()->addHours(24));
    }
}
