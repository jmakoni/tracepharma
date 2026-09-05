<?php

declare(strict_types=1);

namespace App\Support\Auth;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

final class AccountSecuritySession
{
    public const VERSION_KEY = 'auth.session_version';

    public static function bind(Authenticatable $user): void
    {
        if (! $user instanceof Model || ! isset($user->session_version)) {
            return;
        }

        session([self::VERSION_KEY => (int) $user->session_version]);
    }

    public static function matches(Authenticatable $user): bool
    {
        if (! $user instanceof Model || ! isset($user->session_version)) {
            return true;
        }

        $stored = session(self::VERSION_KEY);
        $current = (int) $user->session_version;

        // Legacy sessions before this feature: bind only when the account has never
        // been version-bumped (still at 0). A null session key after revoke must not
        // rebind against a non-zero session_version (remember-me / new session hole).
        if ($stored === null) {
            if ($current === 0) {
                self::bind($user);

                return true;
            }

            return false;
        }

        return (int) $stored === $current;
    }

    public static function clear(): void
    {
        session()->forget(self::VERSION_KEY);
    }
}
