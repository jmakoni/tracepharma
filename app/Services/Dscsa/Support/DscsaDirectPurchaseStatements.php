<?php

declare(strict_types=1);

namespace App\Services\Dscsa\Support;

use App\Actions\Epcis\ResolveGlnToMasterData;
use App\Enums\PartnerType;
use App\Enums\TenantProfile;
use App\Models\Epcis\EpcisDocument;
use App\Models\Tenant;

final class DscsaDirectPurchaseStatements
{
    public const WHOLESALER_DIRECT_PURCHASE_TEMPLATE = '%s affirms that indicated product(s) were purchased directly from the manufacturer, exclusive distributor of the manufacturer, or a repackager who purchased directly unless noted as being indirectly sourced.';

    public const MANUFACTURER_TEMPLATE = '%s affirms that indicated product(s) in this shipment were manufactured by %s.';

    public const REPACKAGER_TEMPLATE = '%s affirms that indicated product(s) in this shipment were repackaged by %s and were purchased directly from the manufacturer, exclusive distributor of the manufacturer, or a repackager that purchased directly from the manufacturer.';

    public const RECEIVED_PREV_WHOLESALER_DEFAULT = 'Seller affirms receipt of directly purchased statement from previous wholesaler distributor for the indicated product(s).';

    public function __construct(
        private readonly ResolveGlnToMasterData $resolveGln,
    ) {}

    public function statementForSeller(PartnerType $partnerType, string $sellerName): ?string
    {
        $name = $this->displaySellerName($sellerName);

        return match ($partnerType) {
            PartnerType::Manufacturer => sprintf(self::MANUFACTURER_TEMPLATE, $name, $name),
            PartnerType::Wholesaler => sprintf(self::WHOLESALER_DIRECT_PURCHASE_TEMPLATE, $name),
            PartnerType::Logistics3pl => null,
            PartnerType::Pharmacy, PartnerType::Other => sprintf(self::WHOLESALER_DIRECT_PURCHASE_TEMPLATE, $name),
        };
    }

    public function generatedStatement(EpcisDocument $document, PartnerType $partnerType, string $sellerName): ?string
    {
        if ($document->direction === 'outbound' && tenant()?->profile === TenantProfile::Prepackager) {
            $name = $this->displaySellerName($sellerName);

            return sprintf(self::REPACKAGER_TEMPLATE, $name, $name);
        }

        return $this->statementForSeller($partnerType, $sellerName);
    }

    public function resolveSellerPartnerType(
        EpcisDocument $document,
        ?string $sellerGln,
        string $sellerName,
    ): PartnerType {
        if ($sellerGln !== null) {
            $master = $this->resolveGln->handle($sellerGln);
            $partner = $master['trading_partner'] ?? null;
            if ($partner?->partner_type instanceof PartnerType) {
                return $partner->partner_type;
            }
        }

        if ($this->sellerMatchesTenant($sellerName)) {
            return $this->tenantProfileToPartnerType(tenant());
        }

        if ($document->direction === 'outbound') {
            return $this->tenantProfileToPartnerType(tenant());
        }

        return PartnerType::Wholesaler;
    }

    public function tenantProfileToPartnerType(?Tenant $tenant): PartnerType
    {
        $profile = $tenant?->profile ?? TenantProfile::Pharmacy;

        return match ($profile) {
            TenantProfile::Manufacturer => PartnerType::Manufacturer,
            TenantProfile::Prepackager => PartnerType::Other,
            TenantProfile::DrugWholesaler => PartnerType::Wholesaler,
            TenantProfile::Logistics3pl => PartnerType::Logistics3pl,
            TenantProfile::Pharmacy => PartnerType::Pharmacy,
            TenantProfile::DentalMedicalSupply,
            TenantProfile::BuyingGroup => PartnerType::Other,
        };
    }

    public function shouldOmitGeneratedDirectPurchase(?string $qualifier): bool
    {
        return $qualifier !== null && strtoupper($qualifier) === 'ENTIRELY_INDIRECT';
    }

    private function sellerMatchesTenant(string $sellerName): bool
    {
        if (! function_exists('tenancy') || ! tenancy()->initialized) {
            return false;
        }

        $tenantName = tenant()?->name;
        if (filled($tenantName) && filled($sellerName) && $sellerName !== '—') {
            if (strcasecmp(trim($sellerName), trim((string) $tenantName)) === 0) {
                return true;
            }
        }

        return false;
    }

    private function displaySellerName(string $sellerName): string
    {
        return $sellerName !== '—' && $sellerName !== '' ? $sellerName : 'The seller';
    }
}
