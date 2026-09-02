<?php

namespace App\Support\Auth;

use App\Enums\TenantRole;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantSettings;
use Illuminate\Support\Facades\Auth;

/**
 * Opt-in job-role gate on top of TenantFeatures.
 * When the tenant setting is off, nav capabilities are unrestricted (features still apply).
 */
final class JobRoleAccess
{
    public static function enabled(?Tenant $tenant = null): bool
    {
        return TenantSettings::forTenant($tenant ?? tenant())->jobRolesEnabled();
    }

    public static function allows(string $capability, ?User $user = null): bool
    {
        if (! self::enabled()) {
            return true;
        }

        $user ??= Auth::user();

        if (! $user instanceof User) {
            return false;
        }

        return $user->can($capability);
    }

    /**
     * Capability check for mutation actions that may run without an interactive user
     * (WMS webhooks, queued jobs, CLI). Pass the resolved actor explicitly:
     *
     * - When job roles are disabled, always returns true.
     * - When $user is null (machine/system — no authenticated operator), returns true
     *   so integrations are not blocked by nav.* gates meant for humans.
     * - When $user is provided, enforces job-role permissions via $user->can().
     *
     * UI routes must keep using allows() or pass Auth::user() here so missing auth
     * still denies access.
     */
    public static function allowsForActor(string $capability, ?User $user): bool
    {
        if (! self::enabled()) {
            return true;
        }

        if (! $user instanceof User) {
            return true;
        }

        return $user->can($capability);
    }

    public static function allowsAny(string ...$capabilities): bool
    {
        if ($capabilities === []) {
            return true;
        }

        if (! self::enabled()) {
            return true;
        }

        foreach ($capabilities as $capability) {
            if (self::allows($capability)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Owner escape hatch when job roles are off; otherwise enforce nav capabilities.
     */
    public static function allowsOwnerOrAny(string ...$capabilities): bool
    {
        if (! self::enabled()) {
            return self::isOwner();
        }

        return self::allowsAny(...$capabilities);
    }

    /**
     * True when flag off, or user has any nav.* / users.manage / Owner org-settings path.
     */
    public static function hasAnyAppCapability(?User $user = null): bool
    {
        if (! self::enabled()) {
            return true;
        }

        if (self::canAccessOrganizationSettings($user)) {
            return true;
        }

        foreach (Permissions::navCapabilities() as $capability) {
            if (self::allows($capability, $user)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Owner always retains an escape hatch (e.g. turn job roles off after a stale seed).
     */
    public static function isOwner(?User $user = null): bool
    {
        $user ??= Auth::user();

        return $user instanceof User && $user->hasRole(TenantRole::Owner->value);
    }

    /**
     * Who may open Organization / Settings Hub: Owner escape, users.manage when flag off,
     * or nav.master_data when flag on.
     */
    public static function canAccessOrganizationSettings(?User $user = null): bool
    {
        $user ??= Auth::user();

        if (! $user instanceof User) {
            return false;
        }

        if (self::isOwner($user) || $user->can(Permissions::UsersManage)) {
            return true;
        }

        return self::enabled() && $user->can(Permissions::NavMasterData);
    }
}
