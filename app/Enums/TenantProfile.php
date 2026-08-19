<?php

namespace App\Enums;

enum TenantProfile: string
{
    case Pharmacy = 'pharmacy';
    case Manufacturer = 'manufacturer';
    case DrugWholesaler = 'drug_wholesaler';
    case Prepackager = 'prepackager';
    case Logistics3pl = 'logistics_3pl';
    case DentalMedicalSupply = 'dental_medical_supply';
    case BuyingGroup = 'buying_group';

    public function label(): string
    {
        return match ($this) {
            self::Pharmacy => 'Pharmacy',
            self::Manufacturer => 'Manufacturer',
            self::DrugWholesaler => 'Drug Wholesaler',
            self::Prepackager => 'Prepackager',
            self::Logistics3pl => 'Logistics (3PL)',
            self::DentalMedicalSupply => 'Dental / Medical Supply',
            self::BuyingGroup => 'Buying Group',
        };
    }

    /**
     * Collapse fine-grained profile into coarse organization type for display.
     */
    public function tenantType(): TenantType
    {
        return match ($this) {
            self::Pharmacy => TenantType::Pharmacy,
            self::Logistics3pl => TenantType::ThreePl,
            self::Manufacturer,
            self::DrugWholesaler,
            self::Prepackager,
            self::DentalMedicalSupply,
            self::BuyingGroup => TenantType::Distributor,
        };
    }
}
