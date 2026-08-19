<?php

namespace App\Support\Marketing;

class DscsaProviderChecklist
{
    /**
     * @return array<string, list<string>>
     */
    public static function sections(): array
    {
        return [
            'Receiving & EPCIS' => [
                'Which inbound channels are supported: upload, SFTP, AS2, webhooks?',
                'How are missing 3T documents or serial mismatches surfaced to receivers?',
                'Can you generate outbound EPCIS and SSCC labels from the same system?',
                'Is product trace available for a single serial across inbound and outbound events?',
            ],
            'Outbound & shipping' => [
                'Can you build outbound EPCIS with TI, TH, and TS from the same L4 workspace?',
                'Do you monitor customer or principal ACK health on outbound messages?',
                'Can WMS or ERP ship-confirm events trigger outbound EPCIS generation?',
                'Are SSCC labels generated with pool low-water alerts before serial exhaustion?',
            ],
            'L3 ↔ L4 serialization' => [
                'Can you allocate SGTIN serial ranges to plant-floor line systems without replacing L3 software?',
                'How are commissioning events reconciled against allocated ranges?',
                'Is gap detection visible on an operations scorecard before auditors ask?',
                'Which handoff methods are supported: file export, REST API, or both?',
            ],
            '3PL & principal operations' => [
                'Can inventory and outbound be scoped per principal brand owner?',
                'Is cross-dock transfer between facilities auditable with scan verification?',
                'Can lot-level and serialized lines ship on the same outbound order?',
                'Are principal filters available on operations scorecards and dashboards?',
            ],
            'Verification & dispensing' => [
                'Do you log every VRS request with GTIN, serial, lot, expiry, outcome, and timestamp?',
                'Can operators verify at a workstation and via API for automation?',
                'Is dispense blocked or flagged when verification fails or product is quarantined?',
                'Can you notify manufacturers on verification failure without a separate ticket?',
            ],
            'Exceptions & accountability' => [
                'Are exceptions assigned, resolved, and retained with reason codes?',
                'Can correction requests be sent to suppliers and tracked in-app?',
                'Is there playbook guidance for common failure scenarios?',
                'Do you score trading partner risk from verification or file failure rates?',
            ],
            'Reporting & inspections' => [
                'Can you prefill FDA Form 3911 from a verification exception?',
                'Do verification summary reports cover arbitrary date ranges?',
                'Can compliance packages bundle verification and exception evidence for export?',
                'How long is audit data retained, and who owns the export?',
            ],
            'Partners & integrations' => [
                'Are trading partner licenses validated on a schedule?',
                'Can you configure HTTPS webhooks for verification and exception events?',
                'Is there a documented REST API with tenant-scoped credentials?',
                'What is the process when a partner changes EPCIS format or GLN?',
            ],
            'Operations & onboarding' => [
                'Is each site an isolated tenant with its own audit trail?',
                'Is there an onboarding checklist with named owners and dates?',
                'Are drug shortage notices available for dashboard review?',
                'What support SLA applies when inbound files fail processing?',
            ],
        ];
    }
}
