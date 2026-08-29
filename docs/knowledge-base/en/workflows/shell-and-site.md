---
title: Shell and site
parent: workflows
order: 1
group: Operations
---

# Shell and site

- **Slug / URL:** `/login`, `/`, `/operations-hub`
- **Filament:** `App\Filament\App\Pages\OperationsHub` (hub); dashboard is the App panel home
- **Who:** Any user with App panel access; Operations Hub requires `hasAnyOperations()` and one of `NavReceive`, `NavShip`, `NavExceptions`, or `NavVerify`
- **Produces:** — (navigation shell; no EPCIS authored here)

## When to use

Start every floor session: sign in, confirm tenant context, pick the working site, then route work from the dashboard or Operations Hub scan field.

## Prerequisites

- Valid demo or tenant credentials (see repo README).
- User assigned to at least one site, or `SitesAccessAll`.
- For scan routing on the hub, a site must be selected in the topbar site chooser.

## Steps (with screenshots)

1. **Sign in** at `/login` (demo: `owner@demo.test` — see repo README).

![Login](media/shell-and-site/01-login.png)

2. **Dashboard** — review CTAs, floor queue, and compliance banners after login.

![Dashboard](media/shell-and-site/02-dashboard.png)

3. **Site chooser** — pick the warehouse or pharmacy site before receive, ship, pack, or disposition desks. Most floor pages gate on the selected site.

![Site chooser](media/shell-and-site/03-site-chooser.png)

4. **Operations Hub** — single scan entry that routes to receive, ship, trace, pack, or unpack based on barcode context and open sessions.

![Operations Hub](media/shell-and-site/04-operations-hub.png)

5. **On-hand inventory** — open from Operations nav to review EPC custody at a site. Demo has many test sites; pick a site in the chooser before expecting rows. An unfiltered view may show an empty EPC table until a site is selected and filters applied.

![On-hand inventory](media/shell-and-site/07-on-hand.png)

![Site menu](media/shell-and-site/08-site-menu.png)

![On-hand with site selected](media/shell-and-site/09-on-hand-selected.png)

![Site chooser open](media/shell-and-site/10-site-chooser-open.png)

![On-hand filtered by site](media/shell-and-site/11-on-hand-filtered.png)

6. **Integration health** — review connection status for inbound/outbound EPCIS and partner integrations.

![Integration health](media/shell-and-site/12-integration-health.png)

7. **EPCIS documents** — authored events appear under **Receiving → Inbound EPCIS** (`/inbound-epcis`) and **Ship → Outbound EPCIS** (`/outbound-epcis`). Example outbound document (events include `biz_step` / disposition):

![Outbound EPCIS document example](media/shell-and-site/05-epcis-document-example.png)

![Outbound EPCIS list](media/shell-and-site/05-outbound-epcis-list.png)

![Inbound EPCIS list](media/shell-and-site/05-inbound-epcis-list.png)

## Authored EPCIS checklist

Not applicable — this workflow does not author events. Use it to reach desks that do.

## Related pages

- [receiving.md](../workflows/receiving) — Receive / Scan In
- [outbound-shipping.md](../workflows/outbound-shipping) — Ship Order / Scan Out
- [asset-tracking.md](../workflows/asset-tracking) — trace from hub scan
- [CBV biz steps & dispositions](../cbv-biz-steps)

## Notes / known quirks

- **On-hand is site-picker heavy** on demo — many test sites exist; select a site (and apply filters if needed) before concluding inventory is empty.
- **Legal documents banner** may appear on the dashboard until acknowledgements are complete.
- **Pharmacy simplified nav** hides Operations Hub and wholesale floor links for some pharmacy tenants.
- Hub scan failures (e.g. EPC not shippable at site) surface inline; use Asset Tracking for full history.
