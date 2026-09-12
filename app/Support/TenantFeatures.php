<?php

namespace App\Support;

use App\Enums\TenantProfile;
use App\Models\Tenant;

class TenantFeatures
{
    public function __construct(
        protected TenantProfile $profile,
    ) {}

    public static function forTenant(?Tenant $tenant): self
    {
        if ($tenant === null) {
            return new self(TenantProfile::Pharmacy);
        }

        return new self($tenant->profile ?? TenantProfile::Pharmacy);
    }

    public function profile(): TenantProfile
    {
        return $this->profile;
    }

    /**
     * Drug wholesaler / prepackager / 3PL / dental — distributor floor ops (receive/ship/VRS/pack).
     * DentalMedicalSupply is wholesaler-lite (included here; no plant commission).
     */
    private function isDistributionOpsProfile(): bool
    {
        return match ($this->profile) {
            TenantProfile::DrugWholesaler,
            TenantProfile::Prepackager,
            TenantProfile::Logistics3pl,
            TenantProfile::DentalMedicalSupply => true,
            default => false,
        };
    }

    /**
     * Full wholesaler family only — excludes DentalMedicalSupply (wholesaler-lite).
     * Use this for any NEW wholesaler-only capability so dental does not inherit by copy-paste.
     * Intentionally unused by current flags; covered via TenantFeaturesTest reflection.
     *
     * @phpstan-ignore method.unused
     */
    private function isFullWholesalerFamilyProfile(): bool
    {
        return match ($this->profile) {
            TenantProfile::DrugWholesaler,
            TenantProfile::Prepackager,
            TenantProfile::Logistics3pl => true,
            default => false,
        };
    }

    public function supportsReceiving(): bool
    {
        // Pharmacy + distribution-ops + Manufacturer (CMO/partner ASN inbound → on-hand).
        // Not Buying Group. Does not imply VRS requestor or Pharmacy outbound desk.
        return $this->profile === TenantProfile::Pharmacy
            || $this->profile === TenantProfile::Manufacturer
            || $this->isDistributionOpsProfile();
    }

    /**
     * VRS requestor UI (Verify Product, verification history, VRS directory).
     * Not the inbound VRS responder webhook — use supportsVrsResponder().
     * Not the manufacturer verification portal — use supportsManufacturerVerificationPortal().
     * Manufacturer is off by default; opt in via TenantSettings::manufacturerVrsRequestorEnabled().
     */
    public function supportsVrs(): bool
    {
        if ($this->profile === TenantProfile::Pharmacy || $this->isDistributionOpsProfile()) {
            return true;
        }

        if ($this->profile === TenantProfile::Manufacturer) {
            $tenant = tenant();
            if ($tenant === null) {
                return false;
            }

            return TenantSettings::forTenant($tenant)->manufacturerVrsRequestorEnabled();
        }

        return false;
    }

    /**
     * VRS responder path (inbound webhook / PI answer).
     * Manufacturer must respond; requestor UI stays on supportsVrs() only.
     * Prepackager is included via supportsVrs() (distribution-ops).
     */
    public function supportsVrsResponder(): bool
    {
        return $this->profile === TenantProfile::Manufacturer
            || $this->supportsVrs();
    }

    public function supportsTransferring(): bool
    {
        // Intracompany multi-site moves — Pharmacy, Manufacturer plants, distribution-ops.
        // Not wholesale ASN receive / Scan In (supportsReceiving) and not Pharmacy outbound desk.
        return $this->profile === TenantProfile::Pharmacy
            || $this->profile === TenantProfile::Manufacturer
            || $this->isDistributionOpsProfile();
    }

    public function supportsUnpacking(): bool
    {
        return $this->profile === TenantProfile::Manufacturer
            || $this->isDistributionOpsProfile();
    }

    public function supportsPacking(): bool
    {
        return $this->profile === TenantProfile::Pharmacy
            || $this->profile === TenantProfile::Manufacturer
            || $this->isDistributionOpsProfile();
    }

    /**
     * Greenfield commission ObjectEvents only (Manufacturer, Prepackager).
     * Not packing, SSCC labeling, break-pack, return, or disposition destroy —
     * use supportsDispositionDecommission() for destroy/retire ObjectEvents.
     */
    public function supportsCommissioning(): bool
    {
        return match ($this->profile) {
            TenantProfile::Manufacturer,
            TenantProfile::Prepackager => true,
            default => false,
        };
    }

    /**
     * Floor disposition destroy / decommission ObjectEvents (DELETE + decommissioning).
     * Not plant commission-all; not Return workstation / 3911·quarantine.
     */
    public function supportsDispositionDecommission(): bool
    {
        return $this->profile === TenantProfile::Pharmacy
            || $this->profile === TenantProfile::Manufacturer
            || $this->isDistributionOpsProfile();
    }

    public function supportsReturning(): bool
    {
        return $this->profile === TenantProfile::Pharmacy
            || $this->profile === TenantProfile::Manufacturer
            || $this->isDistributionOpsProfile();
    }

    public function supportsMasterData(): bool
    {
        return $this->profile !== TenantProfile::BuyingGroup;
    }

    /**
     * Buying-group member roster (network control plane).
     * Floor ops, master data, and member compliance APIs stay off.
     */
    public function supportsBuyingGroupNetwork(): bool
    {
        return $this->profile === TenantProfile::BuyingGroup;
    }

    /**
     * Soft principal registry + optional FK filters on sites / ship orders.
     * Not EPC-level multi-client custody isolation.
     */
    public function supportsPrincipals(): bool
    {
        return $this->profile === TenantProfile::Logistics3pl;
    }

    /**
     * Prepackager-only TransformationEvent authoring (Repack transform).
     * Pack / BreakPack stay aggregation tools for all packing profiles.
     */
    public function supportsRepackTransform(): bool
    {
        return $this->profile === TenantProfile::Prepackager;
    }

    public function supportsInboundIntegrations(): bool
    {
        return $this->profile !== TenantProfile::BuyingGroup;
    }

    /**
     * Outbound shippers only — pharmacy and buying group stay inbound-focused.
     */
    public function supportsOutboundIntegrations(): bool
    {
        return $this->profile === TenantProfile::Manufacturer
            || $this->isDistributionOpsProfile();
    }

    /**
     * Low-volume pharmacy TI desk. Does not unlock Ship Order / Scan Out / WMS.
     */
    public function supportsPharmacyOutboundDesk(): bool
    {
        return $this->profile === TenantProfile::Pharmacy;
    }

    /**
     * Pharmacy opt-in Scan Out + Outbound EPCIS when warehouse tools are shown.
     * Does not unlock Ship Order list or SSCC labeling.
     */
    public function supportsPharmacyFullOutbound(): bool
    {
        if ($this->profile !== TenantProfile::Pharmacy) {
            return false;
        }

        return TenantSettings::forTenant(tenant())->pharmacyFullOutboundEnabled()
            && $this->showsWholesaleOperationsNav();
    }

    /**
     * When true (default for Pharmacy), hide wholesaler floor and ship-order nav.
     */
    public function showsWholesaleOperationsNav(): bool
    {
        if ($this->profile !== TenantProfile::Pharmacy) {
            return true;
        }

        return ! TenantSettings::forTenant(tenant())->pharmacySimplifiedNavEnabled();
    }

    /**
     * Author ship sessions from Scan Out / Ship Order / pharmacy desk.
     * WMS ship-confirm stays on supportsOutboundIntegrations() only.
     */
    public function canAuthorOutboundShipments(): bool
    {
        return $this->supportsOutboundIntegrations() || $this->supportsPharmacyOutboundDesk();
    }

    /**
     * SSCC pallet labeling — same outbound shipper profiles as outbound integrations
     * (Manufacturer already included).
     */
    public function supportsSsccLabeling(): bool
    {
        return $this->supportsOutboundIntegrations();
    }

    public function hasAnyOperations(): bool
    {
        return $this->supportsReceiving()
            || $this->supportsTransferring()
            || $this->supportsUnpacking()
            || $this->supportsPacking()
            || $this->supportsCommissioning()
            || $this->supportsReturning();
    }

    /**
     * DSCSA tracing requests — pharmacies/distributors respond to regulator/supplier
     * trace requests; buying groups stay out of operational compliance workflows.
     */
    public function supportsTracingRequests(): bool
    {
        return match ($this->profile) {
            TenantProfile::BuyingGroup => false,
            default => $this->supportsInboundIntegrations(),
        };
    }

    /**
     * Partner ATP readiness / network control-plane ATP views.
     * Buying groups need partner licence visibility without full master-data CRUD.
     */
    public function supportsPartnerReadiness(): bool
    {
        return $this->supportsMasterData()
            || $this->profile === TenantProfile::BuyingGroup;
    }

    /**
     * Compliance alert center — floor tenants via compliance cases; buying groups
     * get the control-plane shell (integration/ATP signals) without quarantine/3911.
     */
    public function supportsComplianceAlertCenter(): bool
    {
        return $this->supportsComplianceCases()
            || $this->profile === TenantProfile::BuyingGroup;
    }

    /**
     * Document-scoped DSCSA compliance / transaction report hub.
     */
    public function supportsComplianceReports(): bool
    {
        return $this->supportsInboundIntegrations();
    }

    /**
     * FDA 3911 / quarantine workstation — pharmacies, wholesalers, 3PLs, and other
     * trading partners that investigate suspect product (not buying groups).
     */
    public function supportsComplianceCases(): bool
    {
        return match ($this->profile) {
            TenantProfile::BuyingGroup => false,
            TenantProfile::Pharmacy,
            TenantProfile::Manufacturer => true,
            default => $this->isDistributionOpsProfile(),
        };
    }

    /**
     * Opt-in client portal v2 (OTP auth + org membership). Default off.
     */
    public function supportsClientPortalV2(): bool
    {
        $tenant = tenant();
        if ($tenant === null) {
            return false;
        }

        return TenantSettings::forTenant($tenant)->clientPortalV2Enabled();
    }

    /**
     * Manufacturer verification fallback portal (settings-gated).
     * When enabled: any supportsVrsResponder() profile (Manufacturer or VRS requestor profiles).
     * Do not require supportsVrs() alone — that blocked Manufacturer (G-P0-01).
     */
    public function supportsManufacturerVerificationPortal(): bool
    {
        $tenant = tenant();
        if ($tenant === null) {
            return false;
        }

        if (! TenantSettings::forTenant($tenant)->manufacturerVerificationPortalEnabled()) {
            return false;
        }

        return $this->supportsVrsResponder();
    }

    /**
     * Async Serialized Track & Trace export API (DSCSA compliance PDF).
     */
    public function supportsTrackAndTraceExport(): bool
    {
        return $this->profile === TenantProfile::Pharmacy
            || $this->profile === TenantProfile::Manufacturer
            || $this->isDistributionOpsProfile();
    }
}
