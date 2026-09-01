<?php

declare(strict_types=1);

namespace App\Services\Portal;

use App\Models\PortalOtpChallenge;
use App\Models\PortalUser;
use App\Notifications\PortalOtpNotification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

final class PortalOtpService
{
    public const CODE_LENGTH = 6;

    public const TTL_MINUTES = 10;

    public const MAX_VERIFY_ATTEMPTS = 5;

    public const ISSUE_MAX_ATTEMPTS = 5;

    public const ISSUE_DECAY_SECONDS = 600;

    /**
     * Issue a fresh 6-digit OTP for the given email (10 minute TTL).
     *
     * @throws ValidationException
     */
    public function issue(string $email): PortalOtpChallenge
    {
        $email = $this->normalizeEmail($email);
        $throttleKey = $this->issueThrottleKey($email);

        if (RateLimiter::tooManyAttempts($throttleKey, self::ISSUE_MAX_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            throw ValidationException::withMessages([
                'email' => 'Too many login codes requested. Try again in '
                    .max(1, (int) ceil($seconds / 60)).' minute(s).',
            ]);
        }

        RateLimiter::hit($throttleKey, self::ISSUE_DECAY_SECONDS);

        PortalOtpChallenge::query()
            ->where('email', $email)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        $code = $this->generateCode();

        $challenge = PortalOtpChallenge::query()->create([
            'email' => $email,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
            'consumed_at' => null,
            'attempts' => 0,
        ]);

        Notification::route('mail', $email)
            ->notify(new PortalOtpNotification($code, self::TTL_MINUTES));

        return $challenge;
    }

    /**
     * Verify an OTP code. On success, returns the portal user (created if needed).
     *
     * @throws ValidationException
     */
    public function verify(string $email, string $code): PortalUser
    {
        $email = $this->normalizeEmail($email);
        $code = trim($code);

        $challenge = PortalOtpChallenge::query()
            ->where('email', $email)
            ->whereNull('consumed_at')
            ->orderByDesc('id')
            ->first();

        if ($challenge === null || $challenge->isExpired()) {
            throw ValidationException::withMessages([
                'code' => 'This login code is invalid or has expired. Request a new one.',
            ]);
        }

        if ($challenge->attempts >= self::MAX_VERIFY_ATTEMPTS) {
            $challenge->forceFill(['consumed_at' => now()])->save();

            throw ValidationException::withMessages([
                'code' => 'Too many incorrect attempts. Request a new login code.',
            ]);
        }

        if ($code === '' || ! Hash::check($code, $challenge->code_hash)) {
            $challenge->increment('attempts');

            if ($challenge->fresh()?->attempts >= self::MAX_VERIFY_ATTEMPTS) {
                $challenge->forceFill(['consumed_at' => now()])->save();
            }

            throw ValidationException::withMessages([
                'code' => 'The login code is incorrect.',
            ]);
        }

        $challenge->forceFill(['consumed_at' => now()])->save();
        RateLimiter::clear($this->issueThrottleKey($email));

        $user = PortalUser::query()->firstOrCreate(
            ['email' => $email],
            ['is_active' => true],
        );

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'email' => 'This portal account is inactive.',
            ]);
        }

        $user->forceFill(['last_login_at' => now()])->save();

        return $user;
    }

    private function generateCode(): string
    {
        $max = (10 ** self::CODE_LENGTH) - 1;

        return str_pad((string) random_int(0, $max), self::CODE_LENGTH, '0', STR_PAD_LEFT);
    }

    private function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    private function issueThrottleKey(string $email): string
    {
        return 'portal-otp-issue:'.$email;
    }
}
