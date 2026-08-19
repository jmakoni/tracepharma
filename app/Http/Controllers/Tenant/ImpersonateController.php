<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Support\Admin\TenantImpersonation;
use App\Support\Tenancy\TenantAccess;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Stancl\Tenancy\Database\Models\ImpersonationToken;
use Stancl\Tenancy\Features\UserImpersonation;

final class ImpersonateController
{
    public function __invoke(string $token): RedirectResponse
    {
        TenantAccess::assertActive();

        $record = ImpersonationToken::query()->whereKey($token)->first();

        if ($record === null) {
            abort(404);
        }

        if (((string) $record->tenant_id) !== ((string) tenant()->getTenantKey())) {
            abort(403);
        }

        $ttl = UserImpersonation::$ttl;

        if ($record->created_at->diffInSeconds(Carbon::now()) > $ttl) {
            abort(403);
        }

        $payload = [
            'admin_id' => $record->admin_id,
            'tenant_id' => $record->tenant_id,
            'target_user_id' => $record->user_id,
            'reason' => $record->reason,
            'admin_ip' => $record->admin_ip,
            'auth_guard' => $record->auth_guard,
            'redirect_url' => $record->redirect_url,
        ];

        $consumed = ImpersonationToken::query()->whereKey($token)->delete();

        if ($consumed !== 1) {
            abort(404);
        }

        TenantImpersonation::store([
            'admin_id' => $payload['admin_id'],
            'tenant_id' => $payload['tenant_id'],
            'target_user_id' => $payload['target_user_id'],
            'reason' => $payload['reason'],
            'admin_ip' => $payload['admin_ip'],
            'started_at' => now()->toIso8601String(),
        ]);

        Auth::guard($payload['auth_guard'])->loginUsingId($payload['target_user_id']);

        return redirect($payload['redirect_url']);
    }
}
