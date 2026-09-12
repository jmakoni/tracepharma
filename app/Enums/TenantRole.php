<?php

namespace App\Enums;

enum TenantRole: string
{
    case Owner = 'owner';

    case SupportEngineer = 'support_engineer';

    // Manufacturer / CMO / Prepackager
    case PackagingLineOperator = 'packaging_line_operator';
    case SerializationSystemsEngineer = 'serialization_systems_engineer';
    case MasterDataAdministrator = 'master_data_administrator';
    case CmoIntegrationManager = 'cmo_integration_manager';

    // 3PL
    case InboundExceptionCoordinator = 'inbound_exception_coordinator';
    case WmsIntegrationSpecialist = 'wms_integration_specialist';
    case OutboundPickAndPackLead = 'outbound_pick_and_pack_lead';
    case QuarantineAndReturnsSpecialist = 'quarantine_and_returns_specialist';

    // Wholesaler / Dental-Medical
    case AtpVerificationManager = 'atp_verification_manager';
    case VrsAnalyst = 'vrs_analyst';
    case CorporateComplianceAuditor = 'corporate_compliance_auditor';
    case BulkExceptionsManager = 'bulk_exceptions_manager';

    // Pharmacy
    case ReceivingTechnician = 'receiving_technician';
    case DispensingPharmacist = 'dispensing_pharmacist';
    case PharmacyInventoryManager = 'pharmacy_inventory_manager';
    case PharmacySystemAdministrator = 'pharmacy_system_administrator';

    // Buying group — least-privilege SSO JIT (not Owner / Support Engineer)
    case BuyingGroupMember = 'buying_group_member';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::SupportEngineer => 'Support Engineer',
            self::PackagingLineOperator => 'Packaging Line Operator',
            self::SerializationSystemsEngineer => 'Serialization Systems Engineer',
            self::MasterDataAdministrator => 'Master Data Administrator',
            self::CmoIntegrationManager => 'CMO Integration Manager',
            self::InboundExceptionCoordinator => 'Inbound Exception Coordinator',
            self::WmsIntegrationSpecialist => 'WMS Integration Specialist',
            self::OutboundPickAndPackLead => 'Outbound Pick-and-Pack Lead',
            self::QuarantineAndReturnsSpecialist => 'Quarantine & Returns Specialist',
            self::AtpVerificationManager => 'ATP Verification Manager',
            self::VrsAnalyst => 'VRS Analyst',
            self::CorporateComplianceAuditor => 'Corporate Compliance Auditor',
            self::BulkExceptionsManager => 'Bulk Exceptions Manager',
            self::ReceivingTechnician => 'Receiving Technician',
            self::DispensingPharmacist => 'Dispensing Pharmacist',
            self::PharmacyInventoryManager => 'Pharmacy Inventory Manager',
            self::PharmacySystemAdministrator => 'Pharmacy System Administrator',
            self::BuyingGroupMember => 'Buying Group Member',
        };
    }

    /**
     * Roles that SSO JIT may assign (never Owner or Support Engineer).
     */
    public function isJitAssignable(): bool
    {
        return ! in_array($this, [self::Owner, self::SupportEngineer], true);
    }

    /**
     * @return list<self>
     */
    public static function forProfile(TenantProfile $profile): array
    {
        $personas = match ($profile) {
            TenantProfile::Manufacturer,
            TenantProfile::Prepackager => [
                self::SupportEngineer,
                self::PackagingLineOperator,
                self::SerializationSystemsEngineer,
                self::MasterDataAdministrator,
                self::CmoIntegrationManager,
            ],
            TenantProfile::Logistics3pl => [
                self::SupportEngineer,
                self::ReceivingTechnician,
                self::OutboundPickAndPackLead,
                self::InboundExceptionCoordinator,
                self::AtpVerificationManager,
                self::VrsAnalyst,
                self::WmsIntegrationSpecialist,
                self::QuarantineAndReturnsSpecialist,
            ],
            TenantProfile::DrugWholesaler,
            TenantProfile::DentalMedicalSupply => [
                self::SupportEngineer,
                self::ReceivingTechnician,
                self::OutboundPickAndPackLead,
                self::InboundExceptionCoordinator,
                self::AtpVerificationManager,
                self::VrsAnalyst,
                self::CorporateComplianceAuditor,
                self::BulkExceptionsManager,
            ],
            TenantProfile::Pharmacy => [
                self::SupportEngineer,
                self::ReceivingTechnician,
                self::DispensingPharmacist,
                self::PharmacyInventoryManager,
                self::PharmacySystemAdministrator,
            ],
            TenantProfile::BuyingGroup => [
                self::SupportEngineer,
                self::BuyingGroupMember,
            ],
        };

        return [self::Owner, ...$personas];
    }

    /**
     * @return array<string, string>
     */
    public static function optionsForProfile(TenantProfile $profile): array
    {
        return collect(self::forProfile($profile))
            ->mapWithKeys(fn (self $role): array => [$role->value => $role->label()])
            ->all();
    }

    /**
     * JIT-selectable roles for a profile (excludes Owner and Support Engineer).
     *
     * @return array<string, string>
     */
    public static function jitOptionsForProfile(TenantProfile $profile): array
    {
        return collect(self::forProfile($profile))
            ->filter(fn (self $role): bool => $role->isJitAssignable())
            ->mapWithKeys(fn (self $role): array => [$role->value => $role->label()])
            ->all();
    }

    /**
     * Least-privilege JIT default for SSO-created users (never Owner or Support Engineer).
     */
    public static function jitDefaultForProfile(TenantProfile $profile): self
    {
        $roles = array_values(array_filter(
            self::forProfile($profile),
            static fn (self $role): bool => $role->isJitAssignable(),
        ));

        foreach ([
            self::ReceivingTechnician,
            self::PackagingLineOperator,
            self::OutboundPickAndPackLead,
            self::MasterDataAdministrator,
            self::BuyingGroupMember,
        ] as $candidate) {
            if (in_array($candidate, $roles, true)) {
                return $candidate;
            }
        }

        if ($roles !== []) {
            return $roles[0];
        }

        // Profiles always include at least one JIT-assignable persona after BuyingGroupMember.
        return self::BuyingGroupMember;
    }
}
