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

    public function supportsReceiving(): bool
    {
        return match ($this->profile) {
            TenantProfile::Pharmacy,
            TenantProfile::DrugWholesaler,
            TenantProfile::Prepackager,
            TenantProfile::Logistics3pl,
            TenantProfile::DentalMedicalSupply => true,
            default => false,
        };
    }

    public function supportsVrs(): bool
    {
        return match ($this->profile) {
            TenantProfile::Pharmacy,
            TenantProfile::DrugWholesaler,
            TenantProfile::Prepackager,
            TenantProfile::Logistics3pl,
            TenantProfile::DentalMedicalSupply => true,
            default => false,
        };
    }

    public function supportsTransferring(): bool
    {
        return match ($this->profile) {
            TenantProfile::Pharmacy,
            TenantProfile::DrugWholesaler,
            TenantProfile::Prepackager,
            TenantProfile::Logistics3pl,
            TenantProfile::DentalMedicalSupply => true,
            default => false,
        };
    }

    public function supportsUnpacking(): bool
    {
        return match ($this->profile) {
            TenantProfile::Manufacturer,
            TenantProfile::DrugWholesaler,
            TenantProfile::Prepackager,
            TenantProfile::Logistics3pl,
            TenantProfile::DentalMedicalSupply => true,
            default => false,
        };
    }

    public function supportsPacking(): bool
    {
        return match ($this->profile) {
            TenantProfile::Pharmacy,
            TenantProfile::Manufacturer,
            TenantProfile::DrugWholesaler,
            TenantProfile::Prepackager,
            TenantProfile::Logistics3pl,
            TenantProfile::DentalMedicalSupply => true,
            default => false,
        };
    }

    public function supportsCommissioning(): bool
    {
        return match ($this->profile) {
            TenantProfile::Manufacturer,
            TenantProfile::DrugWholesaler,
            TenantProfile::Prepackager,
            TenantProfile::Logistics3pl,
            TenantProfile::DentalMedicalSupply => true,
            default => false,
        };
    }

    public function supportsReturning(): bool
    {
        return match ($this->profile) {
            TenantProfile::Pharmacy,
            TenantProfile::Manufacturer,
            TenantProfile::DrugWholesaler,
            TenantProfile::Prepackager,
            TenantProfile::Logistics3pl,
            TenantProfile::DentalMedicalSupply => true,
            default => false,
        };
    }

    public function supportsMasterData(): bool
    {
        return $this->profile !== TenantProfile::BuyingGroup;
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
        return match ($this->profile) {
            TenantProfile::Manufacturer,
            TenantProfile::DrugWholesaler,
            TenantProfile::Prepackager,
            TenantProfile::Logistics3pl,
            TenantProfile::DentalMedicalSupply => true,
            default => false,
        };
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
            TenantProfile::DrugWholesaler,
            TenantProfile::Logistics3pl,
            TenantProfile::Manufacturer,
            TenantProfile::Prepackager,
            TenantProfile::DentalMedicalSupply => true,
            default => false,
        };
    }
}
