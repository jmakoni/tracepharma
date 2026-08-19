<?php

declare(strict_types=1);

namespace App\Support\Legal;

use App\Models\User;
use App\Support\Auth\JobRoleAccess;
use App\Support\Marketing\PrivacyPolicy;
use App\Support\Marketing\TermsOfService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

final class LegalAcceptance
{
    public static function graceDays(): int
    {
        return max(0, (int) config('tracepharma.legal_acceptance.grace_days', 14));
    }

    public static function isGated(?User $user = null): bool
    {
        return JobRoleAccess::canAccessOrganizationSettings($user);
    }

    public static function hasAcceptedCurrent(?User $user = null): bool
    {
        $user ??= Auth::user();

        if (! $user instanceof User) {
            return false;
        }

        return $user->terms_version === TermsOfService::version()
            && $user->privacy_version === PrivacyPolicy::version();
    }

    public static function isStale(?User $user = null): bool
    {
        $user ??= Auth::user();

        if (! $user instanceof User) {
            return false;
        }

        return ! self::hasAcceptedCurrent($user);
    }

    public static function ensureNoticeStarted(User $user): void
    {
        if (! self::isStale($user) || $user->legal_notice_started_at !== null) {
            return;
        }

        $user->forceFill([
            'legal_notice_started_at' => now(),
        ])->save();
    }

    public static function graceEndsAt(User $user): ?CarbonInterface
    {
        $started = $user->legal_notice_started_at;

        if ($started === null) {
            return null;
        }

        return $started->copy()->addDays(self::graceDays());
    }

    public static function isHardBlocked(?User $user = null): bool
    {
        $user ??= Auth::user();

        if (! $user instanceof User || ! self::isGated($user) || ! self::isStale($user)) {
            return false;
        }

        $ends = self::graceEndsAt($user);

        return $ends !== null && now()->greaterThanOrEqualTo($ends);
    }

    public static function accept(User $user, ?string $ip = null, ?string $userAgent = null): void
    {
        $termsVersion = TermsOfService::version();
        $privacyVersion = PrivacyPolicy::version();
        $acceptedAt = now();

        $user->forceFill([
            'terms_accepted_at' => $acceptedAt,
            'terms_version' => $termsVersion,
            'privacy_accepted_at' => $acceptedAt,
            'privacy_version' => $privacyVersion,
            'legal_notice_started_at' => null,
        ])->save();

        if (! function_exists('activity')) {
            return;
        }

        try {
            activity()
                ->causedBy($user)
                ->performedOn($user)
                ->withProperties(array_filter([
                    'terms_version' => $termsVersion,
                    'privacy_version' => $privacyVersion,
                    'ip' => $ip,
                    'user_agent' => $userAgent,
                ], static fn ($value) => $value !== null && $value !== ''))
                ->log('legal_terms_accepted');
        } catch (Throwable $exception) {
            Log::warning('Legal acceptance activity log failed.', [
                'user_id' => $user->getKey(),
                'exception' => $exception->getMessage(),
            ]);
        }
    }
}
