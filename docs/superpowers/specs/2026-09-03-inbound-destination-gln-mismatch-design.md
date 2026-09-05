# Inbound destination GLN mismatch (ATTP-aligned)

Date: 2026-09-03  
Status: Phase 2 implemented  
Phases: 1 = warning signals; 2 = optional receive block

## Problem

Inbound EPCIS can validate and open receive even when **sold-to** (destination owning party) or **ship-to** (destination location) is not this tenant. Hub routing only binds SBDH `receiver_gln` to a tenant; there is no post-ingest “is this shipment for us?” check.

## ATTP / industry posture

SAP ATTP maps destination owning party and destination location to business partners / locations. Rule `BR_INTERN_RECEIVE` auto-receives only when the sold-to location is classified as **internal**. Misrouted or non-internal destinations surface in an exception / rules cockpit rather than silently posting as owned inventory.

TracePharma Phase 1 mirrors that with **warning** exceptions (receive still allowed), using [`TenantGlnSet`](../../../app/Support/Custody/TenantGlnSet.php) as the internal GLN set.

Phase 2 adds an **opt-in tenant setting** to treat destination mismatches as receive-blocking business rules (ATTP-style “do not auto-receive until internal destination is confirmed”).

## Identity rules

| Field | Source | Must be “us”? |
|-------|--------|----------------|
| Sold-to | Destination owning party GLN, else SBDH `receiver_gln` | Profile-dependent |
| Ship-to | Enriched `ship_to_gln` (location preferred) | Profile-dependent |

Canonical “us” = organization GLN ∪ organization facility GLNs (`TenantGlnSet`).

### Profile matrix

| Profile | Sold-to | Ship-to |
|---------|---------|---------|
| Pharmacy, DrugWholesaler, Prepackager, DentalMedicalSupply | ∈ TenantGlnSet when present | ∈ TenantGlnSet when present |
| Logistics3pl | May be external (customer principal) | ∈ TenantGlnSet when present |
| Manufacturer / no receiving | Skip | Skip |

Blank destinations: no mismatch (existing `MISSING_SOURCE_DESTINATION` / `UNKNOWN_GLN`).  
Empty `TenantGlnSet`: skip emission (onboarding).

If sold-to and ship-to normalize to the same mismatched GLN, emit only `DESTINATION_OWNING_PARTY_MISMATCH` (avoid duplicate rows).

## Exception codes

| Code | Severity | Receive impact (default) | Receive impact (Phase 2 setting on) |
|------|----------|--------------------------|--------------------------------------|
| `DESTINATION_OWNING_PARTY_MISMATCH` | warning | Warning | BusinessRule |
| `DESTINATION_LOCATION_MISMATCH` | warning | Warning | BusinessRule |

Operational hooks (not cleared by `ValidateEpcis12Document`). Soft-signal clear + re-derive on process/reprocess. Re-check on open receive.

## Phase 2 — block receive (tenant setting)

Setting key: `epcis.block_receive_on_destination_gln_mismatch` (default **false**).

When **on**:

1. `ExceptionType.receive_impact` for both DESTINATION_* codes is synced to `business_rule`.
2. [`ReceivingGate`](../../../app/Services/Receiving/ReceivingGate.php) treats open DESTINATION_* signals as blocking: promotes to an `ExceptionCase` if needed, then blocks ASN open / Start receiving (same as other BusinessRule cases).
3. Organization Settings → Inbound EPCIS toggle: **Block receive when destination GLN is not ours**.

When **off**: catalog/default Warning; signals remain visible but do not gate receive.

## Out of scope

- Hub facility-GLN routing expansion  
- Quarantine / auto-reject of EPCs on mismatch  
- Changing `UNKNOWN_GLN` soft impact  

## Success criteria

**Phase 1:** Misrouted sold-to/ship-to raises the matching warning; 3PL external sold-to does not; matching tenant/facility GLNs stay silent; receive still opens.

**Phase 2:** With the setting on, open DESTINATION_* signals block Start receiving / open ASN session until waived/resolved or destination GLNs are corrected and signals cleared; with the setting off, Phase 1 behavior is unchanged.
