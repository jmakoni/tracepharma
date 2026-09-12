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
                'Can you forward authored commissioning EPCIS to a plant-floor L3 HTTPS endpoint without replacing L3 software?',
                'Is the L3 handoff configured in Organization settings (URL + credentials) rather than a public allocation CRUD API?',
                'Are commissioning forwards idempotent so retries do not duplicate plant intake?',
                'Which handoff methods are shipped today: commissioning forward, custom cutover, or both?',
            ],
            '3PL & principal operations' => [
                'Can sites and outbound ship orders be filtered by principal (soft principal registry + filters are GA)?',
                'Is cross-dock transfer between facilities auditable with scan verification?',
                'Can lot-level and serialized lines ship on the same outbound order?',
                'Are principal filters available on operations scorecards, expiry, and HQ dashboards (soft filters GA)?',
                'Is optional EPC custody enforcement available (tenant ops setting; default off) so serials can be gated per principal when enabled—without claiming isolation as the default product promise?',
                'Can WMS ship-confirm tag a principal (principal_id, external ref, or GLN) for multi-client 3PL tenants?',
                'When custody is enforced, does outbound use agent TI (principal as seller / owning party; 3PL site as ship-from)—without claiming a full T2 drop-ship network?',
                'Do you claim LSPedia Edge / ATP DB / per-principal database isolation, or soft filters plus optional custody only?',
                'Is plant commissioning available for 3PL, or only Manufacturer + Prepackager?',
            ],
            'Verification & dispensing' => [
                'Do you log every VRS request with GTIN, serial, lot, expiry, outcome, and timestamp?',
                'Can operators verify at a workstation and via POST /api/v1/dispense-check for automation?',
                'Is dispense blocked or flagged when verification fails or product is quarantined?',
                'Are named per-vendor PMS adapter routes GA, or is dispense-check a single Sanctum endpoint?',
                'Is an optional manufacturer verification portal available (settings-gated)—and is it distinct from requiring VRS alone?',
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
                'Are dedicated GET /api/v1/compliance/* scorecard routes GA, or should buyers plan on in-app scorecards today?',
                'How long is audit data retained, and who owns the export?',
            ],
            'Buying group control plane' => [
                'Is buying-group tenancy a network control plane (roster, health, partner matrix, APIs) without warehouse receive/ship for the group entity?',
                'Do member health and partner-matrix pages use consented hard links and daily snapshots—not live EPC fan-out?',
                'Are Sanctum /api/v1/buying-group/* member APIs available without EPC payloads?',
                'Do you claim the buying group itself is DSCSA-compliant as an ATP warehouse, or do members remain trading partners?',
                'Are full enrollment analytics / white-label channel CTAs GA, or deferred beyond affiliation + roster enrollment stub?',
            ],
            'Partners & integrations' => [
                'Are trading partner licenses validated on a schedule?',
                'Can you configure HTTPS webhooks for verification and exception events?',
                'Is there a documented REST API with tenant-scoped credentials?',
                'What is the process when a partner changes EPCIS format or GLN?',
                'Do you offer connection approval, hub GLN routing, and optional platform AS2/SFTP edges—without claiming NABP Pulse certification today?',
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
