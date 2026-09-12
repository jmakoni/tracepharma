<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Support\Admin\TenantImpersonation;
use App\Support\Tenancy\TenantAccess;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Stancl\Tenancy\Database\Models\ImpersonationToken;
use Stancl\Tenancy\Features\UserImpersonation;

final class ImpersonateController
{
    private const REDEEM_TTL_SECONDS = 60;

    public function show(string $publicId): View
    {
        TenantAccess::assertActive();

        $this->findValidToken($publicId);

        return view('tenant.impersonate-redeem', ['publicId' => $publicId]);
    }

    public function redeem(string $publicId): RedirectResponse
    {
        TenantAccess::assertActive();

        $record = $this->findValidToken($publicId);

        if (filled($record->admin_ip) && $record->admin_ip !== request()->ip()) {
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

        $consumed = ImpersonationToken::query()->whereKey($record->token)->delete();

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

    private function findValidToken(string $publicId): ImpersonationToken
    {
        $record = ImpersonationToken::query()->where('public_id', $publicId)->first();

        if ($record === null) {
            abort(404);
        }

        if (((string) $record->tenant_id) !== ((string) tenant()->getTenantKey())) {
            abort(403);
        }

        $ttl = min(UserImpersonation::$ttl, self::REDEEM_TTL_SECONDS);

        if ($record->created_at->diffInSeconds(Carbon::now()) > $ttl) {
            abort(403);
        }

        return $record;
    }
}
