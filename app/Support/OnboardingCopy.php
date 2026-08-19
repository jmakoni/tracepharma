<?php

namespace App\Support;

use App\Enums\TenantProfile;
use App\Models\Tenant;

/**
 * Profile-aware onboarding copy shared by Getting started and Settings Hub.
 *
 * Checklist description keys mirror TenantOnboarding::pharmacyItems() /
 * wholesalerItems() ids.
 */
class OnboardingCopy
{
    public function __construct(
        protected TenantFeatures $features,
    ) {}

    public static function forTenant(?Tenant $tenant): self
    {
        return new self(TenantFeatures::forTenant($tenant));
    }

    public function subheading(): string
    {
        if ($this->usesWholesalerChecklist()) {
            return 'Set up your drug wholesaler for DSCSA receive and ship — company GLN, sites, partners, and inbound path.';
        }

        return 'Set up your pharmacy for DSCSA receiving — company GLN, receive site, partners, and inbound path.';
    }

    public function banner(): string
    {
        if ($this->usesWholesalerChecklist()) {
            return 'Drug wholesaler setup: receive from manufacturers/upstream partners and ship to pharmacies with the right GLNs and sites.';
        }

        return 'Pharmacy setup: receive from manufacturers and wholesalers with the right GLNs and sites.';
    }

    /**
     * @return array<string, string>
     */
    public function itemDescriptions(): array
    {
        return $this->usesWholesalerChecklist()
            ? $this->wholesalerItemDescriptions()
            : $this->pharmacyItemDescriptions();
    }

    /**
     * Same profile matrix as TenantOnboarding::items().
     */
    private function usesWholesalerChecklist(): bool
    {
        return match ($this->features->profile()) {
            TenantProfile::DrugWholesaler,
            TenantProfile::Prepackager,
            TenantProfile::Logistics3pl,
            TenantProfile::DentalMedicalSupply => true,
            default => false,
        };
    }

    /**
     * @return array<string, string>
     */
    private function wholesalerItemDescriptions(): array
    {
        return [
            'org_gln' => 'Your company GLN identifies you as the wholesaler on EPCIS and VRS traffic.',
            'receiving_state' => 'The state where you evaluate ATP / WDD licenses for inbound product.',
            'default_receive_site' => 'Warehouse or DC that owns inbound receiving when ASN ship-to is unclear.',
            'default_ship_from_site' => 'Default origin site when you ship to pharmacies and other trading partners.',
            'atp_ready' => 'Required for go-live: a manufacturer or wholesaler you receive from needs a site licensed for your receiving state.',
            'upstream_partner' => 'Add manufacturers or wholesalers you receive from, each with a GLN.',
            'downstream_partner' => 'Add pharmacies or other customers you ship to, each with a GLN.',
            'inbound_path' => 'Configure an inbound connection or validate at least one EPCIS document.',
            'receive_proven' => 'Complete at least one receiving session assigned to a site to prove inbound choreography.',
            'outbound_configured' => 'Configure outbound shipping when ready, or use “Defer outbound for now” if you only receive today.',
            'ship_proven' => 'Complete at least one Ship Order with a generated EPCIS document to prove outbound choreography.',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function pharmacyItemDescriptions(): array
    {
        return [
            'org_gln' => 'Your company GLN identifies your pharmacy on EPCIS and VRS traffic.',
            'receiving_state' => 'The state where you evaluate ATP licenses for inbound product.',
            'default_receive_site' => 'Store or facility that owns inbound receiving when ASN ship-to is unclear.',
            'atp_ready' => 'Required for go-live: a manufacturer or wholesaler you receive from needs a site licensed for your receiving state.',
            'upstream_partner' => 'Add manufacturers or wholesalers you receive from, each with a GLN.',
            'inbound_path' => 'Configure an inbound connection or validate at least one EPCIS document.',
            'receive_proven' => 'Complete at least one receiving session assigned to a site to prove inbound choreography.',
        ];
    }
}
