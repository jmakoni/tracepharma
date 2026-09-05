<?php

declare(strict_types=1);

namespace App\Support\Auth;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class SessionRevoker
{
    public function revoke(Authenticatable&Model $user): void
    {
        $table = (string) config('session.table', 'sessions');

        try {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'user_id')) {
                DB::table($table)
                    ->where('user_id', $user->getAuthIdentifier())
                    ->delete();
            }
        } catch (Throwable) {
            // Session driver may not be database; session_version remains the hard gate.
        }

        if (method_exists($user, 'tokens')) {
            /** @phpstan-ignore-next-line */
            $user->tokens()->delete();
        }

        if (Schema::hasColumn($user->getTable(), 'remember_token')) {
            $user->forceFill(['remember_token' => null])->saveQuietly();
        }
    }
}
