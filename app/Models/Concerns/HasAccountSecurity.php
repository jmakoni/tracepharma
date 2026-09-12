<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Support\Auth\SessionRevoker;
use Illuminate\Support\Carbon;

/**
 * Disable, lockout, and session-version controls for authenticatable accounts.
 *
 * Models that support forced password change should also use
 * {@see HasForcedPasswordChange}.
 */
trait HasAccountSecurity
{
    public function initializeHasAccountSecurity(): void
    {
        $this->mergeCasts([
            'is_active' => 'boolean',
            'failed_login_count' => 'integer',
            'locked_until' => 'datetime',
            'disabled_at' => 'datetime',
            'session_version' => 'integer',
        ]);
    }

    public function isLocked(): bool
    {
        $until = $this->locked_until;

        return $until instanceof Carbon && $until->isFuture();
    }

    public function isUsable(): bool
    {
        return (bool) $this->is_active && ! $this->isLocked();
    }

    public function authenticationFailureMessage(): string
    {
        if (! $this->is_active) {
            return 'This account has been disabled. Contact your administrator.';
        }

        if ($this->isLocked()) {
            $minutes = max(1, (int) ceil(now()->diffInSeconds($this->locked_until) / 60));

            return "This account is locked due to too many failed sign-in attempts. Try again in {$minutes} minute(s), or contact your administrator.";
        }

        return 'These credentials do not match our records.';
    }

    public function recordFailedLogin(): void
    {
        $max = max(1, (int) config('tracepharma.account_security.max_failed_logins', 5));
        $minutes = max(1, (int) config('tracepharma.account_security.lockout_minutes', 15));

        $count = ((int) $this->failed_login_count) + 1;
        $attrs = ['failed_login_count' => $count];

        if ($count >= $max) {
            $attrs['locked_until'] = now()->addMinutes($minutes);
            $attrs['session_version'] = ((int) $this->session_version) + 1;
        }

        $this->forceFill($attrs)->saveQuietly();

        if (isset($attrs['locked_until'])) {
            app(SessionRevoker::class)->revoke($this);
        }
    }

    public function clearFailedLogins(): void
    {
        if ((int) $this->failed_login_count === 0 && $this->locked_until === null) {
            return;
        }

        $this->forceFill([
            'failed_login_count' => 0,
            'locked_until' => null,
        ])->saveQuietly();
    }

    public function disable(?string $reason = null): void
    {
        $this->forceFill([
            'is_active' => false,
            'disabled_at' => now(),
            'disabled_reason' => $reason,
            'session_version' => ((int) $this->session_version) + 1,
        ])->saveQuietly();

        app(SessionRevoker::class)->revoke($this);
    }

    public function enable(): void
    {
        $this->forceFill([
            'is_active' => true,
            'disabled_at' => null,
            'disabled_reason' => null,
        ])->saveQuietly();
    }

    public function unlock(): void
    {
        $this->forceFill([
            'failed_login_count' => 0,
            'locked_until' => null,
        ])->saveQuietly();
    }

    public function bumpSessionVersion(bool $revokeSessions = true): void
    {
        $this->forceFill([
            'session_version' => ((int) $this->session_version) + 1,
        ])->saveQuietly();

        if ($revokeSessions) {
            app(SessionRevoker::class)->revoke($this);
        }
    }

    protected static function bootHasAccountSecurity(): void
    {
        static::updating(function (self $model): void {
            if (! $model->isDirty('is_active')) {
                return;
            }

            if (! $model->is_active) {
                $model->disabled_at = $model->disabled_at ?? now();
                $model->session_version = ((int) $model->getOriginal('session_version')) + 1;
            } else {
                $model->disabled_at = null;
                $model->disabled_reason = null;
            }
        });

        static::updated(function (self $model): void {
            if ($model->wasChanged('is_active') && ! $model->is_active) {
                app(SessionRevoker::class)->revoke($model);
            }
        });
    }
}
