# Master Data Page Overrides

> **PROJECT:** TracePharma  
> **Page Type:** Master Data CRUD (Filament Admin + App)  
> **Overrides:** Rules here override [`../MASTER.md`](../MASTER.md).  
> **UX pass:** 2026-07-31 — directory lists, View-first partners (Admin + App), compliance badges, empty states, pagination, GS1 copy.

---

## Intent

Master Data is a **drill-down, data-dense** workspace—not a marketing landing page.

Hierarchy:

1. **Trading Partner** (sidebar hub)
2. List → row click / eye → full **View** (profile + Sites | Products); **Create** and **Edit** → modal `5xl` only (no `/create` or `/edit` route registered), partner fields only
3. **Edit partner** header CTA on View → mutate partner fields only via modal; modal close keeps you on View
4. Site row / eye → slide-over **View** (profile + ATP readiness); footer **ATP licenses & devices** → full **View** (Devices | ATP Licenses tabs); **Edit** → modal `5xl` only (no `/edit` route registered)
5. **FDA Products** (Master Data sidebar) — central Rx `fda_products` rows already linked on tenant products (`products.fda_product_id`), read-only list + view; not the full FDA registry; not nested under a partner; distinct from Partner Products assortment and the Products directory
6. **Products** (Master Data sidebar, sort 30) — tenant assortment directory (products with at least one receive-from authorization); columns NDC (5-4-2), strength, sourcing paths, distributor SKUs, compliance badge; View shows identity + authorization list; search `Name, NDC, or GTIN`
7. Optional **Site directory** (Active-default filter) for cross-partner lookup

### Relationship model (tenant)

- **Manufacturer** — `products.trading_partner_id` mirrors the catalog labeler (tenant manufacturer partner); not the receive-from link
- **Receive-from** — `trading_partner_product` pivot: which partners you expect to receive a product from (wholesaler, 3PL, or manufacturer direct)
- **ATP** — site-scoped `atp_licenses` on tenant sites (not on trading partners); copied from central `catalog_atp_licenses` when a partner is linked to catalog and HQ site is created or matched (`CopyCatalogAtpLicensesToTenantSite` uses `updateOrCreate` to refresh expirations on re-sync)

---

## Layout Overrides

- **Max width:** Filament full content (Admin + App panels)
- **Forms:** Source (when present) → Identity → Address → Contact (partners) → Geo; **Active lives in Identity** (paired with partner type / HQ)—no standalone Status section on partner/site modals; Geo may collapse on Edit; View profiles stay cardless (no Contact invent on sites)
- **Partner / Site modal grids:** All sections `compact()`; name/DBA/GLN full-width on partners; `partner_type | is_active` or `code | gln` then `HQ | is_active`; Address max 2 columns (city|state, zip|country)—no `lg => 3/4` squeeze
- **Tabs:** Full-width, left-aligned on **`.fi-resource-view-record-page`** relation tabs
- **Density:** High (dial 8/10)—~16px section gaps, compact tables

## Typography Overrides

- **GS1 identifiers:** monospace, copyable, lead column where relevant (GLN, GTIN, NDC, SGLN)

## Color Overrides

- Brand green primary (`#51BC8F`) + white on-primary
- Status via **badge text** (+ optional icon), never color alone
- Row-action ellipsis / items: **secondary** only (`RecordActionGroup`)
- Do **not** default to dark mode for Master Data chrome

## Component Overrides

### FDA Products (App sidebar)

- Master Data nav item **FDA Products** (sort after Trading Partners) — central Rx `fda_products` rows the tenant has already added via `products.fda_product_id` (cross-DB scope), read-only list + view; unlinked FDA ids 404 on deep-link
- Columns: NDC, Name, Dosage, Strength, Net contents, Labeler; search `NDC, brand, or generic name`
- **List header Add product** (primary) and **View header Add product:** modal `3xl` — list header opens with searchable Rx **FDA product** select (NDC / brand / generic) when no row context; View keeps FDA product pre-bound; multi-select active Rx catalog packages scoped to that FDA NDC + required **receive-from** active tenant partner; find-or-create by NDC11/catalog and attach pivot (same notification pattern as Partner Products tab); packages already in the tenant directory stay selectable (labeled “in directory”) so they can be linked to another partner; action disabled on View only when no active Rx catalog packages exist for that FDA row; list row actions are View only (ellipsis)
- **Empty partners gate:** Add product stays enabled when the tenant has no active trading partners — if no catalog major wholesalers exist, modal shows empty-state title “Add a trading partner first”, body “Authorize products only after you have at least one active manufacturer or wholesaler.”, footer **Create trading partner** → Trading Partners list (submit hidden). When Top 6 catalog majors exist (see **Top 6 major wholesalers** below), the form opens normally with wholesaler sentinels and **Add wholesaler** CTA; footer **Create trading partner** still shown when zero active partners
- **Empty state (list):** “No FDA products yet”; CTA Add product
- **Missing manufacturer (receive from wholesaler / 3PL / other):** when the FDA labeler exists in catalog but is not yet an authorized tenant partner, receive-from dropdown shows disabled “{Name} (Manufacturer — not set up)” plus **Add manufacturer** CTA (`EnsureManufacturerPartnerFromCatalog`); warning placeholder + **Add manufacturer from catalog and authorize** toggle (default on) remain for wholesaler path; off → `pending_manufacturer` on pivot; on → auto-create manufacturer partner (notification mentions manufacturer added)
- **`EnsureManufacturerPartnerFromCatalog` reconcile:** after find/create, runs `ReconcilePendingManufacturerAuthorizations` — links matching products to the manufacturer FK and upgrades `pending_manufacturer` pivots to authorized; also creates HQ site and copies catalog ATP licenses
- **Wholesaler receive declaration:** helper on receive-from partner select and Partner Products modal — “Adds this product to your receivable list for this partner. It does not confirm they carry it in their catalog.”
- **Notifications:** success/warning body includes created/linked/skipped counts; warning when `manufacturer_pending` > 0; mention when manufacturer auto-added
- Distinct from Partner View Products tab (tenant assortment) and **Products** directory (tenant authorized assortment); not a browse of the full FDA registry

### Products (App sidebar)

- Master Data nav item **Products** (sort 30) — tenant products with at least one receive-from authorization (`trading_partner_product`)
- Columns: Name, NDC (5-4-2 display), Strength, Sourcing paths (partner names + Direct when manufacturer in pivot), Distributor SKUs (`partner_item_number`), Compliance badge, Active
- **Compliance badge** (product-level rollup): **Verified** / **Pending manufacturer** / **Incomplete** — derived from pivot `authorization_status` + manufacturer FK; `pending_packaging` maps to **Incomplete** for operators (internal enum hidden)
- **Product Create gated** — `ProductResource::canCreate()` is false; add assortment via **FDA Products → Add product** or Partner View **New product** only
- Search `Name, NDC, or GTIN`; View shows identity + authorization list (partner, SKU, UOM, status); row actions View then Edit
- **Empty state:** “No authorized products” — body points to FDA Products or a trading partner; primary CTA opens FDA Add product modal
- Distinct from FDA Products (central FDA rows) and Partner View Products tab (single-partner assortment)

### Trading Partners list (App)

- Standard partner directory columns (name, GLN, partner type, location, status); no ATP readiness rollup at partner level

### Partner View (see also `catalog-partner-view.md`)

- Infolist Details (read-only cardless profile); GLN mono + copyable
- Sites / Products relation managers on View (not on Edit)
- **New site** (Partner View Sites tab): modal offers **From catalog** (partner’s linked catalog sites) or **Create manually**; catalog path prefills site identity/address and copies ATP licenses via `CopyCatalogAtpLicensesToTenantSite`
- **Admin Products tab:** FDA Rx products for the labeler (NDC, name, dosage, strength, net contents) — read-only
- **Tenant Products tab (Partner Products):** assortment via `trading_partner_product`; columns include Dosage / Strength / Net contents / Manufacturer / Partner SKU / **Authorization** badge (`Authorized` / `Pending manufacturer` / `Incomplete` — `pending_packaging` shown as Incomplete)
- **Edit assortment** (row ellipsis → slide-over): `partner_item_number`, `uom_code`, `units_per_case`, **Primary receive-from** (`is_primary`); only one primary per product across all partners (`SetPrimaryReceiveFromPartner`)
- **New product:** multi-select catalog Rx packages — manufacturer partners are labeler-scoped; wholesaler / 3PL / Other search **all** Rx catalog (option label includes manufacturer); find-or-create by GTIN and attach pivot (idempotent); wholesaler / 3PL / Other show receivable-list declaration helper and optional **Add manufacturer from catalog when missing** toggle (default on); inactive partner disables New product with tooltip
- **Empty state:** “No products for this partner” — context-aware body + **New product** CTA when partner is active and catalog-linked
- Primary header: **Edit partner** (modal `5xl`, no `/edit` route)
- **Create** and **Edit** are modal-only, wired via `App\Filament\Support\TradingPartnerModalActions`; Delete lives on the list row ellipsis only
- App uses shared profile Blade + `ViewTradingPartner` (mirrors Admin)

### Sites list (App)

- Row / eye → slide-over **View** (`5xl`) — quick inspect via `SiteSlideOverInfolist` (site profile + **ATP Readiness** glance); footer **ATP licenses & devices** opens full Site View on the **ATP Licenses** tab (`?relation=1`); no row navigation to full View
- **ATP Readiness** column — count-first badge: `{relevant_total} · {tenant_state}` when tenant **receiving state** is set (e.g. `1 · IL`), color from readiness status; when receiving state is unset, badge shows total license count (gray) with optional `{expired_total} expired` description — status is **Set receiving state** (`NeedsReceivingState`); column header tooltip carries `AtpDisclosure::SOURCE`
- **ATP Readiness** filter — **Set receiving state** (only when tenant receiving state unset), **No license for state**, **Expired**, **Expiring**, **Ready** — scoped to licenses matching tenant receiving state via `TenantReceivingState::resolve()`
- Tenant **receiving state** — optional Stancl virtual attribute on `Tenant` (`data.receiving_state`); admin Tenant form select (US 2-letter); used by `SiteAtpReadiness` to evaluate partner WDD licenses for the tenant’s location (demo2 defaults to `IL`)
- **Edit** → modal `5xl` only (no `/edit` route registered)

### Site View — Devices & ATP Licenses (App)

- Full page **Devices | ATP Licenses** management workspace (reached from slide-over footer **ATP licenses & devices** with `?relation=1` or direct deep-link); subheading: “Site compliance and scan locations.”
- **Compact profile on ATP tab** — when `relation=1` (ATP Licenses tab active), profile collapses to title, GLN, partner link, and status badges; address/timezone hidden to keep the license table above the fold
- **Partner line** — App Site View shows “Partner: {name}” linking to Trading Partner View when `tradingPartner` is loaded
- **Provenance caveat** — the **ATP Readiness** panel and the ATP Licenses table description always carry `AtpDisclosure::SOURCE`: readiness comes from the self-reported FDA WDD/3PL license listing plus hand-entered licenses, is not FDA approval or proof of licensure, and new partners should be confirmed with the state board. Never label a partner “FDA verified”
- **Receiving-state CTA** — ATP Readiness shows **Set receiving state** (Admin Tenant edit link when the signed-in user can access Admin) or muted “Ask your administrator…” for App operators; ATP Licenses table adds the same guidance under the caveat when tenant receiving state is unset
- Slide-over quick inspect uses `SiteSlideOverInfolist` for the same **ATP Readiness** glance (receiving state, status badge, relevant-license counts for tenant state, total licenses on site, muted all-states expired hint when different) via `SiteAtpReadiness`
- Site View relation tab **ATP Licenses**; tenant rows copied from catalog when HQ site is linked (`CopyCatalogAtpLicensesToTenantSite` on partner create / HQ create)
- Columns: facility type, license number (mono + copyable), state, **Status** badge **Active / Expired** (text + icon, from expiration date), expiration date, reporting year
- Search placeholder: `License # or state` (license number + license state columns)
- Pagination `[10, 25, 50]` + `defaultPaginationPageOption(25)` + `extremePaginationLinks()`; filters: facility type, expiring within 90 days

### Empty states (App directories)

- **Trading Partners** — “No trading partners”; CTA **Create trading partner** (modal)
- **Products** — “No authorized products”; CTA FDA Add product
- **FDA Products** — “No FDA products yet”; CTA Add product
- **Partner Products tab** — “No products for this partner”; CTA **New product** when eligible

### Admin FDA Registry (WDD/ATP)

- Framed throughout as a **license listing import**, never as authorizing partners — the WDD/3PL report is self-reported by registrants
- **WDD/3PL Staging** — Import + Promote header actions; promotes into catalog sites/ATP licenses and queues tenant sync; subheading states the self-reported provenance; promote copy says licenses the snapshot omits are **dropped from the FDA listing** (not revoked)
- **WDD/3PL Unmatched** — triage open unmatched facilities; **Create organization** or **Link existing organization**, which files the listing against an organization rather than authorizing it (CSV report still written on import)

### Operations Hub (App)

- Master data directories card when `supportsMasterData()`: cross-partner lookup without leaving Operations nav group
- **Product directory** copy: “Authorized assortment and receive-from products. Search GTIN, NDC, or name.”
- **FDA Products** entry with Open link — partner-first add path (“Search Rx FDA NDCs and authorize packages”)

### Top 6 major wholesalers

- Central catalog seeds Top 6 majors (`MajorWholesalers`: McKesson, Cardinal, Cencora, Anda, Morris & Dickson, Smith Drug) via `php artisan catalog:ensure-major-wholesalers`
- FDA Add product receive-from shows “{Name} (Wholesaler — not set up)” sentinels for majors not yet tenant partners; **Add wholesaler** (`EnsureWholesalerPartnerFromCatalog`) when selected; sentinels hidden once any major is an authorized tenant partner

### Tables (Admin Catalog + App)

- Active filter `->default(true)` on directory lists with `is_active`
- Status column: badge **Active / Inactive** (never `IconColumn` boolean alone)
- Search placeholders: “GLN or partner name” / “GLN, code, or site name” / “GTIN, NDC, or name” / “NDC, brand, or generic name” (FDA Products) / “Name, manufacturer, or model” / “GLN or device name”
- Pagination `[10, 25, 50]` + `defaultPaginationPageOption(25)` + `extremePaginationLinks()` on directory + nested lists
- Row actions: under vertical ellipsis (`RecordActionGroup`), **secondary** color for trigger + items only
- View first when a View page exists, then Edit; submit/header actions keep their normal colors
- Names / cities: `DisplayName::clean` where shown
- Action button labels: `Str::ucwords()` via `app/Filament/Overrides/Actions/HasLabel.php`
- Submit/save/create buttons: **primary** background + icon (`AppServiceProvider` + `App\Filament\Resources\Pages\{Create,Edit}Record`)

### Slide-overs (quick add)

- Cap ~6 fields: Name, Code, GLN, HQ, City, State, Active
- Full address + Geo on Site Edit modal / Site page via Open

### Avoid

- Landing heroes / metrics / marketing CTAs
- View as a dead end (no Sites/Products create)
- Icon-only Active column without text badge
- Emoji icons
- Replacing brand green with generic blue primary

---

## Recommendations

- Effects: row hover, filter transitions 150–300ms, async select spinners
- Motion dial 3/10; typography viteTheme (Fira) deferred
