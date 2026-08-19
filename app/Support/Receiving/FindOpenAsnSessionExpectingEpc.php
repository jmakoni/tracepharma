<?php

namespace App\Support\Receiving;

use App\Enums\ReceivingSessionKind;
use App\Models\Epcis\Epc;
use App\Models\Receiving\ReceivingSession;
use App\Models\User;
use App\Support\Auth\Permissions;

final class FindOpenAsnSessionExpectingEpc
{
    public function handle(
        Epc $epc,
        ?ReceivingSession $exclude = null,
        ?int $siteId = null,
        ?User $actor = null,
    ): ?ReceivingSession {
        $query = ReceivingSession::query()
            ->where('session_kind', ReceivingSessionKind::InboundAsn)
            ->whereIn('status', ['open', 'in_progress'])
            ->whereHas('scanLines', function ($q) use ($epc): void {
                $q->where('epc_id', $epc->getKey())
                    ->where('status', 'expected');
            })
            ->when($exclude !== null, fn ($q) => $q->whereKeyNot($exclude->getKey()));

        $accessAll = $actor !== null && $actor->can(Permissions::SitesAccessAll);
        // Unscoped ASN (null site_id) matches the current scan-first site for
        // system/unauthenticated confirms and SitesAccessAll. Site-restricted
        // operators must not reconcile onto a session they cannot place.
        $includeUnscopedAsn = $accessAll || $actor === null;

        if ($siteId !== null) {
            if ($includeUnscopedAsn) {
                $query->where(function ($q) use ($siteId): void {
                    $q->where('site_id', $siteId)
                        ->orWhereNull('site_id');
                })
                    ->orderByRaw('CASE WHEN site_id IS NULL THEN 1 ELSE 0 END')
                    ->orderBy('id');
            } else {
                $query->where('site_id', $siteId)
                    ->orderBy('id');
            }
        } else {
            if ($includeUnscopedAsn) {
                $query->whereNull('site_id')
                    ->orderBy('id');
            } else {
                return null;
            }
        }

        return $query->first();
    }
}
