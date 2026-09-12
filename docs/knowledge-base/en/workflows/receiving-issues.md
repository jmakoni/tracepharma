---
title: Receiving issues
parent: workflows
order: 20
group: Operations
---

# Receiving issues

- **Slug / URL:** `/receiving-issues`, `/receiving-issues?session={id}`
- **Filament:** `App\Filament\App\Pages\ReceivingIssues`
- **Who:** `supportsReceiving()` and `NavReceive`
- **Produces:** — (exception cases; no receiving EPCIS re-authored)

## When to use

After receive is **complete**, report shortage, overage, or damaged product on a session. Subheading: *Report shortage, overage, or damaged product after receive is complete. Scan HUD stays claim-free.*

## Prerequisites

- Target session status **completed** (only completed sessions appear in picker).
- User site access matches session site (or all-sites).
- Receive EPCIS already authored — this flags operational exceptions.

## Steps (with screenshots)

1. Open **Receiving issues** from Receiving nav, or follow link from session view.

![Receiving issues desk](media/receiving-issues/01-entry.png)

![Receiving issues desk (full)](media/receiving-issues/02-full.png)

2. Select a completed session — shortage/overage counts populate from scan line statuses (`expected` vs `unexpected`).
3. Optionally check **damaged EPCs** from confirmed/unexpected lines.
4. Enter notes; submit → `FlagManualReceivingException` creates exception case(s).

## Authored EPCIS checklist

Not applicable — issues create **Exception** records linked to the receive session, not new ObjectEvents.

- [ ] Exception case with session, partner, site context
- [ ] Damaged EPC IDs attached when selected
- [ ] Notes captured for investigator workflow

## Related pages

- [receiving.md](../workflows/receiving) — complete receive first
- [verify-product.md](../workflows/verify-product) — dispense exceptions (different path)
- Exception resource in App panel for follow-up

## Notes / known quirks

- Claim-free by design — no scan HUD on this page.
- Session query scoped to completed + user-visible sites only.
- URL param `?session=` deep-links to a specific session (`urlForSession` helper).
