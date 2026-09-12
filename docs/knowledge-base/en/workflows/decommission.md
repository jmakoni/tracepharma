---
title: Decommission
parent: workflows
order: 20
group: Operations
---

# Decommission

- **Slug / URL:** `/decommission-workstation`
- **Filament:** `App\Filament\App\Pages\DecommissionWorkstation`
- **Who:** `supportsCommissioning()` and `NavShip` (same gate as commission)
- **Produces:** `decommissioning` + **reason-mapped disposition** (ObjectEvent)

## When to use

Remove on-hand product from salable inventory — destroyed, expired, recalled, returned to destruction path, suspect/illegitimate, or QA reject. Subheading: *Scan on-hand EPCs at the selected site, choose a reason, then author decommissioning ObjectEvents.*

## Prerequisites

- Site selected; EPCs on hand and operable for decommissioning.
- Decommission reason chosen at confirm time.
- **Mass SoD:** when effective count exceeds threshold, a **second approver** with `DecommissionMassApprove` permission is required (cannot self-approve).

## Mass decommission / SoD

`AssertDecommissionMassApproval` applies:

| Setting | Default | Meaning |
|---|---|---|
| `tracepharma.decommission.mass_threshold` | 10 | Batch + recent count must stay ≤ threshold |
| `tracepharma.decommission.mass_window_hours` | 8 | Rolling window for recent decommissions at site |

**Effective count** = this batch size + distinct EPCs decommissioned at the same site within the window.

When effective count **>** threshold, the confirm modal requires **second approver email + password** (different user with mass-approve permission).

## Reason → disposition

`biz_step` is always **`decommissioning`**. Disposition follows `DecommissionReason`:

| UI reason | CBV disposition |
|---|---|
| Destroyed | `destroyed` |
| Expired | `expired` |
| Recalled | `recalled` |
| Returned | `returned` |
| Suspect / illegitimate | `inactive` |
| QA reject / never shipped | `disposed` |

Helper text in the modal shows the disposition URN when a reason is selected.

## Steps (with screenshots)

1. Open **Decommission** from Operations nav.

![Decommission workstation](media/decommission/01-entry.png)

![Decommission desk (full)](media/decommission/02-workstation.png)

2. Scan on-hand SSCC/SGTINs into the confirmed list.
3. Click **Complete decommission**; select reason (and second approver if mass threshold exceeded).
4. `EmitDecommissioningEpcis` authors ObjectEvents.

## Authored EPCIS checklist

- [ ] ObjectEvent per decommissioned EPC
- [ ] `bizStep`: `urn:epcglobal:cbv:bizstep:decommissioning`
- [ ] `disposition`: matches reason table above (`urn:epcglobal:cbv:disp:…`)
- [ ] Mass approval audit when threshold exceeded
- [ ] Custody terminal disposition updated

## Related pages

- [return.md](../workflows/return) — return to vendor (disposition `returned` via returning flow)
- [commission.md](../workflows/commission) — inverse action
- [cbv-biz-steps.md](../cbv-biz-steps) — disposition vs biz_step distinction

## Notes / known quirks

- Do not confuse **disposition** (lifecycle state) with **biz_step** (always decommissioning here).
- Blocks EPCs on open receive, ship, or transfer sessions.
- Modal shows effective count breakdown: batch + recent at site.
