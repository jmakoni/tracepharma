---
title: Quarantine
parent: compliance
order: 55
group: Compliance
---

# Quarantine

Filament classes:

- `App\Filament\App\Pages\Quarantine`

## When to use

Hold suspect, recalled, or investigation-bound inventory so it cannot ship or transfer while investigators resolve the issue.

## Prerequisites

- Site context selected.
- EPCs on hand (or already on a quarantine hold) at that site.
- Permission to open and release quarantine holds.

## Steps

1. Open **Quarantine** from Compliance (or Operations) nav. Open the page and use Help for live UI.
2. Scan or enter EPCs to place on hold; record reason and notes.
3. Confirm the hold — inventory is blocked from salable outbound flows.
4. When cleared, release or escalate per your SOP (link to exceptions / recall pages as needed).

## Related pages

- [recall-and-inspection.md](../compliance/recall-and-inspection) — recall closure and site reconciliation
- [compliance-alerts.md](../compliance/compliance-alerts) — open compliance signals
- [../exceptions/exceptions.md](../exceptions/exceptions) — exception investigation queue

## Notes

- Quarantine is a custody control, not a decommission; disposition changes follow separate workflows.
- Mass holds may require second-person review depending on tenant policy.
