# Catalog Site View — Page Overrides

> Overrides [`../MASTER.md`](../MASTER.md) and [`master-data.md`](master-data.md) for Admin catalog site View only. Mirrors [`catalog-partner-view.md`](catalog-partner-view.md).

---

## Route / default

- URL: `/catalog/catalog-sites/{record}` (full View page)
- Partner Sites tab / list row click / eye → full **View** (profile + Devices | ATP Licenses)
- **Edit site** → modal (`5xl`) with site form only — no `/edit` route is registered on `CatalogSiteResource` (Admin) or `SiteResource` (App); Edit is modal-only everywhere (View header, list row action, Partner Sites relation manager row action)

## Header

- No page heading (title lives in the profile)
- Browser title: **Company - City** (e.g. `Xttrium Laboratories, Inc. - Glenview`)
- Primary action: **Edit site** (`EditAction` → modal, color primary, pencil icon)
- No Delete on View

## Body

- Site profile always above (not a tab)
- **Cardless profile Blade** (`filament.admin.infolists.catalog-site-profile`)
  - Heading (`text-2xl`): partner/company name + ` - ` + city via `SiteDisplayTitle`
  - Optional site name line when it differs from the company heading; code; GLN (mono); HQ + status badges
  - Divider
  - Address stack (shared App/Admin): street/city/state lines + timezone; coordinates grid optional/deferred
  - Empty values → muted `—`

## Relation tabs

- Below the site profile: Devices | ATP Licenses (not combined with Details)
- CRUD enabled on View
- Tab bar: same daisyUI tabs-border styles as partner View (`.fi-sc-tabs[id$="relationManagerTabs"]`)
