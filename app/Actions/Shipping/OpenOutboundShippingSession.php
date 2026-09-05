<?php

namespace App\Actions\Shipping;

use App\Models\Shipping\OutboundShippingSession;
use App\Models\User;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Auth\SiteAccess;
use App\Support\Shipping\ResolveShipFromSite;
use App\Support\TenantFeatures;
use DomainException;

/**
 * Open an outbound ship order session at a ship-from site.
 */
final class OpenOutboundShippingSession
{
    public function __construct(
        private readonly ResolveShipFromSite $resolveShipFromSite,
    ) {}

    /**
     * A corrective order ships stock that has already left the building, so it needs a
     * documented reason on the record itself — the authored EPCIS carries it forward.
     */
    public function handle(
        ?int $siteId = null,
        ?int $openedBy = null,
        bool $isCorrective = false,
        ?string $correctiveReason = null,
        ?int $correctsEpcisDocumentId = null,
        bool $isDropShipment = false,
        ?int $principalId = null,
        ?int $expectedCount = null,
    ): OutboundShippingSession {
        if (! TenantFeatures::forTenant(tenant())->canAuthorOutboundShipments()) {
            throw new DomainException('Outbound shipping is not available for this tenant profile.');
        }

        if (! JobRoleAccess::allowsForActor(Permissions::NavShip, auth()->user())) {
            throw new DomainException('Shipping is not authorized for your job role.');
        }

        $correctiveReason = $correctiveReason !== null ? trim($correctiveReason) : null;

        if ($isCorrective && blank($correctiveReason)) {
            throw new DomainException('A reason is required to open a corrective ship order.');
        }

        $siteId = $this->resolveShipFromSite->handle($siteId);

        $user = auth()->user();
        if ($user instanceof User) {
            SiteAccess::assertCanAccessSite($user, $siteId);
        }

        $attributes = [
            'site_id' => $siteId,
            'status' => 'open',
            'expected_count' => max(0, (int) ($expectedCount ?? 0)),
            'confirmed_count' => 0,
            'split_declared' => false,
            // TI/TS is the seller's affirmation, so the operator makes it on the send step.
            'dscsa_affirm' => false,
            'is_drop_shipment' => $isDropShipment,
            'opened_by' => $openedBy,
            'opened_at' => now(),
        ];

        if (
            $principalId !== null
            && TenantFeatures::forTenant(tenant())->supportsPrincipals()
        ) {
            $attributes['principal_id'] = $principalId;
        }

        if ($isCorrective) {
            $attributes['is_corrective'] = true;
            $attributes['corrective_reason'] = $correctiveReason;
            $attributes['corrects_epcis_document_id'] = $correctsEpcisDocumentId;
        }

        return OutboundShippingSession::query()->create($attributes);
    }
}
