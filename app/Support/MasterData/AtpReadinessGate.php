<?php

namespace App\Support\MasterData;

use App\Enums\SiteAtpReadinessStatus;
use App\Models\Site;
use App\Support\Gs1\Sgln;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Shared decision surface for the ATP licence gates — the outbound send block and the
 * ingest soft warning — so the destination that stops a shipment is the same one that
 * raises a warning when a partner ships to us.
 */
final class AtpReadinessGate
{
    /**
     * Readiness states that do not evidence a licence in force for the tenant's
     * evaluation jurisdictions (org footprint, or preferred receiving state fallback).
     *
     * Expiring is absent on purpose: a licence with weeks left still authorizes today's
     * delivery, and the expiry dashboards already chase it. A licence with no expiration
     * date on file is here because it cannot be shown to be in force at all.
     * NeedsReceivingState blocks when neither footprint nor receiving state is available.
     *
     * @var list<SiteAtpReadinessStatus>
     */
    public const BLOCKING_STATUSES = [
        SiteAtpReadinessStatus::Expired,
        SiteAtpReadinessStatus::NoLicenses,
        SiteAtpReadinessStatus::UnknownExpiry,
        SiteAtpReadinessStatus::NeedsReceivingState,
    ];

    public static function blocks(SiteAtpReadinessStatus $status): bool
    {
        return in_array($status, self::BLOCKING_STATUSES, true);
    }

    public static function blocksSite(Site $site): bool
    {
        return self::blocks(SiteAtpReadiness::summarize($site)['status']);
    }

    /**
     * The partner facility a destination GLN names, or null when the partner has no such
     * address on record.
     */
    public static function siteForGln(int $tradingPartnerId, ?string $gln): ?Site
    {
        $normalized = Sgln::normalizeGln($gln);

        if ($normalized === null) {
            return null;
        }

        return Site::query()
            ->where('trading_partner_id', $tradingPartnerId)
            ->where('gln', $normalized)
            ->where('is_active', true)
            ->first();
    }

    /**
     * The facilities to judge for a party: the one the document or session names when it
     * is known and still belongs to that party, otherwise every address the party has on
     * record. A licence held by a different address of the same company does not
     * authorize a delivery to this one, so a known facility is never widened.
     *
     * @return EloquentCollection<int, Site>
     */
    public static function candidateSites(int $tradingPartnerId, ?int $knownSiteId = null): EloquentCollection
    {
        if ($knownSiteId !== null) {
            $known = self::activeSitesFor($tradingPartnerId)->whereKey($knownSiteId)->get();

            if ($known->isNotEmpty()) {
                return $known;
            }
        }

        return self::activeSitesFor($tradingPartnerId)->get();
    }

    /**
     * Whether a party's ATP evidence fails for every facility in scope.
     *
     * One licensed address is enough: when the document names no facility, any site of the
     * party that is ready means the delivery can lawfully land somewhere, so the party is
     * not faulted — the same "any ready site allows" rule the outbound send gate applies in
     * ValidateOutboundShippingSend::atpBlocker(). When a facility is named it is the only
     * candidate, so both rules collapse to judging it alone.
     *
     * A party with no active address on record has nothing to judge and is not faulted;
     * that gap is surfaced elsewhere (the outbound gate refuses an unresolvable ship-to
     * GLN outright).
     */
    public static function blocksParty(int $tradingPartnerId, ?int $knownSiteId = null): bool
    {
        $sites = self::candidateSites($tradingPartnerId, $knownSiteId);

        if ($sites->isEmpty()) {
            return false;
        }

        return $sites->every(fn (Site $site): bool => self::blocksSite($site));
    }

    /**
     * @return Builder<Site>
     */
    private static function activeSitesFor(int $tradingPartnerId): Builder
    {
        return Site::query()
            ->with('atpLicenses')
            ->where('trading_partner_id', $tradingPartnerId)
            ->where('is_active', true);
    }
}
