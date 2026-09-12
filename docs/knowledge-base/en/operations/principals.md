---
title: Principals
parent: operations
order: 45
group: Operations
---

# Principals

Filament classes:

- `App\Filament\App\Resources\Principals\PrincipalResource`
- `App\Filament\App\Resources\Principals\Pages\ListPrincipals`

## When to use

On Logistics 3PL tenants, maintain client **principals** (name, optional GLN, optional WMS `external_ref`, active flag) and optionally tag sites, receive, ship, exceptions, and related lists by principal.

**Default honesty:** Principals filter lists; serials are not isolated per client.

When optional **`principalCustodyEnforced`** is on (default **off**), ops and scans are gated per principal instead of soft labels only.

## Prerequisites

- Tenant profile is Logistics 3PL (`supportsPrincipals`).
- Master-data create/edit permissions (`nav.master_data` / Owner).
- Client names and, if needed, client ATP GLNs and WMS client codes known.

## Steps

1. Open **Principals** under Master Data. Open the page and use Help for live UI.
2. Create or edit principals: **name**, optional **GLN**, optional **external_ref** (WMS / host-system client code), **active**.
3. Optionally set a default principal on **Sites**; use soft **principal** filters on sites, ship, receive, exceptions, expiry worklist, on-hand, and HQ surfaces as offered.
4. For WMS ship-confirm, map the host client via `principal_external_ref` (or principal GLN / id) so TracePharma resolves the same registry row.
5. Treat **`principalCustodyEnforced`** as opt-in only after receive/ship principal paths and EPC backfill are verified. Enable from **Settings → Organization → Principals → Enforce principal custody (EPC isolation)** (default off).

## Related pages

- [../master-data/products.md](../master-data/products) — product catalog (not principals)
- [../master-data/sites-and-devices.md](../master-data/sites-and-devices) — site default principal
- [on-hand-and-unpacked.md](../operations/on-hand-and-unpacked) — on-hand list filters
- [../compliance/expiry-worklist.md](../compliance/expiry-worklist) — expiry filters
- [../exceptions/exceptions.md](../exceptions/exceptions) — exception principal filter
- [../integrations/connections.md](../integrations/connections) — WMS / outbound connectivity

## Notes

- Soft mode (default): list filters and optional tags only — inventory and custody stay tenant-scoped unless enforcement is on.
- Enforced mode: opening receive/ship needs a principal; cross-principal serials are refused; agent TI seller uses the principal GLN when present.
- **Hub GLNs vs principal GLNs:** site / org GLNs route hub delivery and ship-from location; principal GLNs are client ATP / title-holder tags (TI seller when enforced). Do not treat a principal GLN as a separate hub-routed tenant.
- WMS and integration detail (soft vs enforced, ship-confirm fields): see engineering doc `docs/integrations/3pl-principals.md`.
- **Non-goals:** not an Edge / Investigator product clone; no plant commissioning for 3PL; full T2 / drop-ship principal network remains deferred.
