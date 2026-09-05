---
title: Pharmacy outbound
parent: workflows
order: 20
group: Operations
---

# Pharmacy outbound

- **Slug / URL:** `/pharmacy-outbound`
- **Filament:** `App\Filament\App\Pages\PharmacyOutboundDesk`
- **Who:** **Pharmacy tenant profile only** — `supportsPharmacyOutboundDesk()` and `NavShip`. **403 on Drug Wholesaler demo2** (expected profile gating).
- **Produces:** `shipping` + `in_transit` (same outbound ship events as Scan Out, lower-volume desk)

## When to use

Low-volume pharmacy TI send desk when full Ship Order / Scan Out / WMS integrations are intentionally locked. Subheading: *Low-volume TI send. Ship Order and Scan Out stay locked for this profile.*

## Prerequisites

- **Pharmacy** tenant profile (not Drug Wholesaler, Manufacturer, etc.).
- Site selected; on-hand EPCs at pharmacy site.
- Trading partner and optional ship-to site for destination identity.

## Steps (with screenshots)

1. On a **Pharmacy** tenant, open **Pharmacy outbound** from Operations nav.

![Pharmacy outbound desk](media/pharmacy-outbound/01-entry.png)

2. Start or select an open outbound session.
3. Set partner, destination GLN/SGLN, ASN/PO, DSCSA affirm as needed.
4. Scan units; confirm lines; **complete ship**.

## Authored EPCIS checklist

- [ ] ObjectEvent: `shipping` / `in_transit` (via `CompleteOutboundShippingSession`)
- [ ] Same outbound document pipeline as wholesaler ship (profile-limited UI)
- [ ] Session uses `OutboundShippingSession` model

## Related pages

- [outbound-shipping.md](../workflows/outbound-shipping) — full wholesaler ship (blocked for pharmacy profile inverse)
- [verify-product.md](../workflows/verify-product) — dispense verify before patient handoff
- [return.md](../workflows/return) — non-saleable returns

## Notes / known quirks

- Direct URL `/pharmacy-outbound` on **demo2 Drug Wholesaler** returns **403 Forbidden** — feature is profile-gated, not a product bug.
- Navigation item hidden unless `supportsPharmacyOutboundDesk()` is true.
- Does not unlock outbound integrations nav (connections, WMS) reserved for `supportsOutboundIntegrations()`.
