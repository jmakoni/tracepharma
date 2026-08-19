<?php

namespace App\Services\Quarantine;

use App\Models\TradingPartner;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Issues, rotates and revokes the per-partner supplier exception portal link.
 *
 * The link is an unauthenticated view of that partner's open exception cases, so it is
 * a temporary signed URL (see config `tracepharma.supplier_portal.link_ttl_days`) tied
 * to a rotatable `portal_share_uuid`. The uuid is never mass assignable — it only moves
 * through this service, which keeps the activity log the single record of who shared,
 * rotated or revoked partner access.
 */
final class SupplierPortalService
{
    private const DEFAULT_TTL_DAYS = 30;

    public function ensurePartnerPortalLink(TradingPartner $partner): TradingPartner
    {
        if ($partner->portal_share_uuid === null) {
            $partner->forceFill([
                'portal_share_uuid' => (string) Str::uuid(),
            ])->save();

            $this->logPortalChange($partner, 'supplier_portal_link_issued');
        }

        return $partner->refresh();
    }

    /**
     * Replace the uuid: every previously shared link stops resolving, and the partner
     * gets a fresh one. Use when a link reached the wrong recipient.
     */
    public function rotatePartnerPortalLink(TradingPartner $partner): TradingPartner
    {
        $partner->forceFill([
            'portal_share_uuid' => (string) Str::uuid(),
        ])->save();

        $this->logPortalChange($partner, 'supplier_portal_link_rotated');

        return $partner->refresh();
    }

    /**
     * Drop the uuid entirely: outstanding links stop resolving and no new link exists
     * until someone shares the portal again.
     */
    public function revokePartnerPortalLink(TradingPartner $partner): TradingPartner
    {
        if ($partner->portal_share_uuid === null) {
            return $partner;
        }

        $partner->forceFill(['portal_share_uuid' => null])->save();

        $this->logPortalChange($partner, 'supplier_portal_link_revoked');

        return $partner->refresh();
    }

    /**
     * @throws RuntimeException when the partner is no longer an active trading partner
     */
    public function signedPartnerExceptionsUrl(TradingPartner $partner): string
    {
        if (! $partner->is_active) {
            throw new RuntimeException('Inactive trading partners cannot be granted a supplier portal link.');
        }

        $partner = $this->ensurePartnerPortalLink($partner);

        return URL::temporarySignedRoute(
            'tenant.supplier-exceptions.index',
            now()->addDays($this->linkTtlDays()),
            ['portalShareUuid' => $partner->portal_share_uuid],
        );
    }

    public function linkTtlDays(): int
    {
        return max(1, (int) config(
            'tracepharma.supplier_portal.link_ttl_days',
            self::DEFAULT_TTL_DAYS,
        ));
    }

    /**
     * The model only logs master-data attributes, and the uuid itself must never reach the
     * log, so sharing events are recorded explicitly.
     */
    private function logPortalChange(TradingPartner $partner, string $description): void
    {
        if (! function_exists('activity')) {
            return;
        }

        activity()
            ->performedOn($partner)
            ->withProperties(array_filter([
                'trading_partner_id' => $partner->getKey(),
                'user_id' => auth()->id(),
            ], static fn ($value) => $value !== null))
            ->log($description);
    }
}
