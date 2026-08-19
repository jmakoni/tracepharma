<?php

declare(strict_types=1);

namespace App\Support\Admin;

use App\Models\Admin;
use Illuminate\Support\Facades\Session;

final class TenantImpersonation
{
    public const SESSION_KEY = 'tenant_impersonation';

    public static function isActive(): bool
    {
        return is_array(Session::get(self::SESSION_KEY));
    }

    public static function payload(): ?array
    {
        $payload = Session::get(self::SESSION_KEY);

        return is_array($payload) ? $payload : null;
    }

    public static function adminId(): ?int
    {
        $id = data_get(self::payload(), 'admin_id');

        return is_numeric($id) ? (int) $id : null;
    }

    public static function reason(): ?string
    {
        $reason = data_get(self::payload(), 'reason');

        return is_string($reason) && $reason !== '' ? $reason : null;
    }

    public static function store(array $payload): void
    {
        Session::put(self::SESSION_KEY, $payload);
    }

    public static function forget(): ?array
    {
        $payload = self::payload();
        Session::forget(self::SESSION_KEY);

        return $payload;
    }
}
