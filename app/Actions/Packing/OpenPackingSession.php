<?php

namespace App\Actions\Packing;

use App\Enums\PackingSessionKind;
use App\Models\Packing\PackingSession;
use App\Models\User;
use App\Support\Auth\CurrentSite;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Auth\SiteAccess;
use DomainException;

/**
 * Open or resume an unsubmitted packing work session.
 */
final class OpenPackingSession
{
    public function handle(
        PackingSessionKind $kind,
        ?int $siteId = null,
        ?int $openedBy = null,
        ?int $existingSessionId = null,
    ): PackingSession {
        if (! JobRoleAccess::allows(Permissions::NavShip)) {
            throw new DomainException('Packing is not authorized for your job role.');
        }

        if ($existingSessionId !== null) {
            $existing = PackingSession::query()->whereKey($existingSessionId)->first();
            if ($existing instanceof PackingSession && $existing->isExclusive()) {
                if ($existing->session_kind !== $kind) {
                    throw new DomainException('Session kind does not match this workstation.');
                }

                return $existing;
            }
        }

        $resolvedSiteId = $siteId ?? CurrentSite::id();
        if ($resolvedSiteId === null) {
            throw new DomainException('Select a site before starting a pack session.');
        }

        $user = auth()->user();
        if ($user instanceof User) {
            SiteAccess::assertCanAccessSite($user, $resolvedSiteId);
        }

        return PackingSession::query()->create([
            'session_kind' => $kind,
            'site_id' => $resolvedSiteId,
            'status' => 'open',
            'staged_count' => 0,
            'confirmed_count' => 0,
            'opened_by' => $openedBy ?? auth()->id(),
            'opened_at' => now(),
        ]);
    }
}
