<?php

declare(strict_types=1);

namespace App\Services\Portal;

use App\Models\Epcis\EpcisDocument;
use App\Models\PortalPublication;
use App\Models\PortalUser;
use Illuminate\Database\Eloquent\Builder;

final class ClientPortalAccess
{
    /**
     * Trading partner IDs for the user's active portal organizations.
     *
     * @return list<int>
     */
    public function partnerIdsFor(PortalUser $user): array
    {
        return $user->organizations()
            ->where('portal_organizations.is_active', true)
            ->pluck('trading_partner_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    public function hasActiveOrganization(PortalUser $user): bool
    {
        return $this->partnerIdsFor($user) !== [];
    }

    /**
     * Document IDs with an active portal publication for the user's partners.
     *
     * @return list<int>
     */
    public function publishedDocumentIdsFor(PortalUser $user): array
    {
        $partnerIds = $this->partnerIdsFor($user);

        if ($partnerIds === []) {
            return [];
        }

        return PortalPublication::query()
            ->active()
            ->whereIn('trading_partner_id', $partnerIds)
            ->pluck('epcis_document_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    public function assertDocumentVisible(PortalUser $user, EpcisDocument $document): void
    {
        $partnerIds = $this->partnerIdsFor($user);

        if ($partnerIds === []) {
            abort(403, 'No active portal organization membership.');
        }

        $visible = PortalPublication::query()
            ->active()
            ->where('epcis_document_id', $document->getKey())
            ->whereIn('trading_partner_id', $partnerIds)
            ->exists();

        abort_unless($visible, 403, 'This shipment is not available in your portal.');
    }

    /**
     * @return Builder<PortalPublication>
     */
    public function publicationsQuery(PortalUser $user): Builder
    {
        $partnerIds = $this->partnerIdsFor($user);

        return PortalPublication::query()
            ->active()
            ->whereIn('portal_publications.trading_partner_id', $partnerIds === [] ? [-1] : $partnerIds);
    }
}
