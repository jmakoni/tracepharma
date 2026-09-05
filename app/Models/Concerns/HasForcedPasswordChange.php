<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Support\Auth\AccountSecuritySession;
use App\Support\Auth\SessionRevoker;
use Illuminate\Support\Facades\Auth;

/**
 * Force password change on next local-password login (not OIDC).
 */
trait HasForcedPasswordChange
{
    public function initializeHasForcedPasswordChange(): void
    {
        $this->mergeCasts([
            'must_change_password' => 'boolean',
            'password_changed_at' => 'datetime',
        ]);
    }

    public function mustChangePassword(): bool
    {
        return (bool) $this->must_change_password;
    }

    public function markPasswordChanged(bool $keepCurrentSession = false): void
    {
        $this->forceFill([
            'must_change_password' => false,
            'password_changed_at' => now(),
            'failed_login_count' => 0,
            'locked_until' => null,
            'session_version' => ((int) $this->session_version) + 1,
        ])->saveQuietly();

        if (! $keepCurrentSession) {
            app(SessionRevoker::class)->revoke($this);
        }

        if ($this->isAuthenticatedPrincipal()) {
            AccountSecuritySession::bind($this);
        }
    }

    protected static function bootHasForcedPasswordChange(): void
    {
        static::creating(function (self $model): void {
            if (filled($model->password) && $model->password_changed_at === null) {
                $model->password_changed_at = now();
            }
        });

        static::updated(function (self $model): void {
            if (! $model->wasChanged('password')) {
                return;
            }

            // Same save set a temp password AND must_change_password=true — keep the force flag.
            if ($model->wasChanged('must_change_password') && (bool) $model->must_change_password) {
                $model->forceFill([
                    'password_changed_at' => now(),
                    'failed_login_count' => 0,
                    'locked_until' => null,
                    'session_version' => ((int) $model->session_version) + 1,
                ])->saveQuietly();

                if (! $model->isAuthenticatedPrincipal()) {
                    app(SessionRevoker::class)->revoke($model);
                } else {
                    AccountSecuritySession::bind($model);
                }

                return;
            }

            // Avoid recursion when markPasswordChanged saves quietly (no password change).
            $model->markPasswordChanged(keepCurrentSession: $model->isAuthenticatedPrincipal());
        });
    }

    private function isAuthenticatedPrincipal(): bool
    {
        foreach (['web', 'admin'] as $guard) {
            $user = Auth::guard($guard)->user();

            if ($user && $user->is($this)) {
                return true;
            }
        }

        return false;
    }
}
