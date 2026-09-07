<?php

declare(strict_types=1);

namespace App\Support\Admin;

use App\Models\Admin;
use App\Models\PlatformAuditEvent;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Central audit trail for admin-originated platform mutations (tenant
 * suspend/activate, entitlement edits, connection approvals, GLN claims,
 * kill switches, impersonation). Always writes to the central database,
 * safe to call from inside a tenant context.
 */
final class PlatformAudit
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public static function record(
        string $action,
        ?string $tenantId = null,
        ?string $targetType = null,
        int|string|null $targetId = null,
        array $payload = [],
        ?Admin $actor = null,
    ): void {
        try {
            $actor ??= Auth::guard('admin')->user();

            PlatformAuditEvent::query()->create([
                'admin_id' => $actor?->getKey(),
                'action' => $action,
                'target_type' => $targetType,
                'target_id' => $targetId !== null ? (string) $targetId : null,
                'tenant_id' => $tenantId,
                'payload' => $payload === [] ? null : $payload,
                'created_at' => now(),
            ]);
        } catch (Throwable) {
            // Audit must never break the mutation it records.
        }
    }
}
