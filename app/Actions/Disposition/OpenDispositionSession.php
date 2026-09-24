<?php

namespace App\Actions\Disposition;

use App\Models\Disposition\DispositionSession;
use App\Models\User;
use App\Support\Auth\CurrentSite;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Auth\SiteAccess;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Open or resume an unsubmitted disposition batch session.
 */
final class OpenDispositionSession
{
    public function handle(
        string $bizStep,
        ?int $siteId = null,
        ?int $openedBy = null,
        ?int $existingSessionId = null,
    ): DispositionSession {
        if (! JobRoleAccess::allows(Permissions::NavShip)) {
            throw new DomainException('Disposition is not authorized for your job role.');
        }

        if ($existingSessionId !== null) {
            $existing = DispositionSession::query()->whereKey($existingSessionId)->first();
            if ($existing instanceof DispositionSession && $existing->isExclusive()) {
                if ($existing->biz_step !== $bizStep) {
                    throw new DomainException('Session type does not match this workstation.');
                }

                $user = auth()->user();
                if ($user instanceof User) {
                    if ($existing->site_id === null) {
                        if (! $user->can(Permissions::SitesAccessAll)) {
                            throw new AuthorizationException('You do not have access to this disposition session.');
                        }
                    } else {
                        SiteAccess::assertCanAccessSite($user, (int) $existing->site_id);
                    }
                }

                return $existing;
            }
        }

        $resolvedSiteId = $siteId ?? CurrentSite::id();
        if ($resolvedSiteId === null) {
            throw new DomainException('Select a site before starting a disposition session.');
        }

        $user = auth()->user();
        if ($user instanceof User) {
            SiteAccess::assertCanAccessSite($user, $resolvedSiteId);
        }

        return DispositionSession::query()->create([
            'biz_step' => $bizStep,
            'site_id' => $resolvedSiteId,
            'status' => 'open',
            'staged_count' => 0,
            'confirmed_count' => 0,
            'opened_by' => $openedBy ?? auth()->id(),
            'opened_at' => now(),
        ]);
    }
}
