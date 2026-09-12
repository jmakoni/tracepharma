<?php

declare(strict_types=1);

namespace App\Support\Shipping;

use App\Actions\Shipping\ValidateOutboundShippingSend;
use App\Models\Shipping\OutboundShippingSession;
use App\Models\TradingPartner;
use App\Support\Gs1\Sgln;
use App\Support\MasterData\AtpLicenseRelevance;
use App\Support\Shipping\AtpGateBypass;
use App\Support\TenantFeatures;
use App\Support\TenantSettings;

/**
 * Pre-send readiness chips for ship desks (TI/TS, ATP, destination, portal path).
 *
 * @phpstan-type Badge array{key: string, label: string, status: 'ok'|'warn'|'block', detail: string}
 */
final class OutboundShipReadiness
{
    /**
     * @return list<Badge>
     */
    public function badges(OutboundShippingSession $session): array
    {
        $session->loadMissing(['tradingPartner', 'shipToSite', 'site']);

        return [
            $this->partnerBadge($session->tradingPartner),
            $this->destinationBadge($session),
            $this->tiTsBadge($session),
            $this->atpBadge($session),
            $this->outboundPathBadge($session->tradingPartner),
        ];
    }

    /**
     * @return Badge
     */
    private function partnerBadge(?TradingPartner $partner): array
    {
        if ($partner === null) {
            return [
                'key' => 'partner',
                'label' => 'Customer',
                'status' => 'block',
                'detail' => 'Select a trading partner',
            ];
        }

        if (! $partner->is_active) {
            return [
                'key' => 'partner',
                'label' => 'Customer',
                'status' => 'block',
                'detail' => 'Partner is inactive',
            ];
        }

        return [
            'key' => 'partner',
            'label' => 'Customer',
            'status' => 'ok',
            'detail' => (string) $partner->name,
        ];
    }

    /**
     * @return Badge
     */
    private function destinationBadge(OutboundShippingSession $session): array
    {
        $gln = null;

        if (filled($session->ship_to_gln)) {
            $gln = Sgln::normalizeGln((string) $session->ship_to_gln);
        } elseif (filled($session->shipToSite?->gln)) {
            $gln = Sgln::normalizeGln((string) $session->shipToSite->gln);
        } elseif (filled($session->tradingPartner?->gln)) {
            $gln = Sgln::normalizeGln((string) $session->tradingPartner->gln);
        }

        if ($gln === null) {
            return [
                'key' => 'destination',
                'label' => 'Destination',
                'status' => 'block',
                'detail' => 'Ship-to GLN or site required',
            ];
        }

        return [
            'key' => 'destination',
            'label' => 'Destination',
            'status' => 'ok',
            'detail' => 'GLN '.$gln,
        ];
    }

    /**
     * @return Badge
     */
    private function tiTsBadge(OutboundShippingSession $session): array
    {
        $missing = [];

        if (blank($session->asn_number)) {
            $missing[] = 'ASN';
        }

        if (blank($session->customer_po) && blank($session->invoice_number)) {
            $missing[] = 'PO/invoice';
        }

        if (! $session->dscsa_affirm) {
            $missing[] = 'TI/TS affirm';
        }

        if ($missing !== []) {
            return [
                'key' => 'ti_ts',
                'label' => 'TI/TS',
                'status' => 'block',
                'detail' => 'Missing: '.implode(', ', $missing),
            ];
        }

        return [
            'key' => 'ti_ts',
            'label' => 'TI/TS',
            'status' => 'ok',
            'detail' => 'Affirmed · ASN '.$session->asn_number,
        ];
    }

    /**
     * @return Badge
     */
    private function atpBadge(OutboundShippingSession $session): array
    {
        if (AtpGateBypass::isBypassed()) {
            return [
                'key' => 'atp',
                'label' => 'ATP',
                'status' => 'warn',
                'detail' => 'Outbound ATP gate is disabled (config bypass)',
            ];
        }

        if (AtpLicenseRelevance::evaluationJurisdictionKeys() === []) {
            $hard = TenantSettings::forTenant(tenant())->blockSendOnAtpGap();

            return [
                'key' => 'atp',
                'label' => 'ATP',
                'status' => $hard ? 'block' : 'warn',
                'detail' => 'Add org site jurisdictions or set preferred receiving state',
            ];
        }

        $issue = app(ValidateOutboundShippingSend::class)->atpIssue($session);

        if (is_string($issue)) {
            $hard = TenantSettings::forTenant(tenant())->blockSendOnAtpGap();

            return [
                'key' => 'atp',
                'label' => 'ATP',
                'status' => $hard ? 'block' : 'warn',
                'detail' => $issue,
            ];
        }

        return [
            'key' => 'atp',
            'label' => 'ATP',
            'status' => 'ok',
            'detail' => 'Destination license check passed',
        ];
    }

    /**
     * @return Badge
     */
    private function outboundPathBadge(?TradingPartner $partner): array
    {
        $features = TenantFeatures::forTenant(tenant());

        if ($features->supportsPharmacyOutboundDesk() || ! $features->supportsOutboundIntegrations()) {
            if ($partner === null) {
                return [
                    'key' => 'path',
                    'label' => 'Delivery path',
                    'status' => 'warn',
                    'detail' => 'Customer portal after send',
                ];
            }

            if (filled($partner->email) || filled($partner->customer_portal_uuid)) {
                return [
                    'key' => 'path',
                    'label' => 'Delivery path',
                    'status' => 'ok',
                    'detail' => filled($partner->email)
                        ? 'Portal + email on ship'
                        : 'Customer portal link ready',
                ];
            }

            return [
                'key' => 'path',
                'label' => 'Delivery path',
                'status' => 'warn',
                'detail' => 'Add partner email for portal notify',
            ];
        }

        return [
            'key' => 'path',
            'label' => 'Delivery path',
            'status' => 'ok',
            'detail' => 'Outbound connection / hub / portal',
        ];
    }
}
