# Catalog Partner View — Page Overrides

> Overrides [`../MASTER.md`](../MASTER.md) and [`master-data.md`](master-data.md) for Admin catalog partner View only.

---

## Route / default

- URL: `/catalog/catalog-trading-partners/{record}` (full View page)
- List row click / eye → full **View** (profile + Sites | Products)
- **Create** and **Edit** are modal-only (`Width::FiveExtraLarge`) — no `/create` or `/edit` route registered
- Create redirect → View
- Admin + App panels use `maxContentWidth(Width::Full)` (Filament default is `7xl`)

## Header

- No page heading (“View {name}” removed — name lives in the profile)
- Browser title: partner name only
- Primary action: **Edit partner** (`EditAction`, color primary, pencil icon, opens modal `5xl`)
- No Delete on View — Delete lives on the list row ellipsis (`RecordActionGroup` → `DeleteAction`)
- **Slug:** never shown in UI; auto-set from name on create, locked (unchanged) on edit

## Body

- Partner profile always above (not a tab)
- **Cardless profile Blade** (`filament.admin.infolists.catalog-trading-partner-profile`) — shared by Admin Catalog + App Trading Partners
  - Name (`text-2xl`), optional DBA, GLN (mono + copyable), type + status badges
  - Divider
  - Asymmetric 12-col grid (`lg:grid-cols-12`): Address `lg:col-span-7` | Contact + Coordinates `lg:col-span-5`
  - Inline label/value rows with fixed `w-28` labels: Street address, City/State, Country, Timezone, Telephone, Email, Website, Latitude, Longitude, Altitude
  - Section headers: micro uppercase + bottom border accent
  - Timezone: stored value, else derived from country/state/city
- No Section cards, shadows, or contained chrome
- Empty values → muted `—`

## Relation tabs

- Below the partner profile: Sites | Products (not combined with Details)
- Sites: **CRUD enabled** on View
- Products: **FDA Rx products** for this labeler (NDC, name, dosage, strength, net contents) — read-only
- Tab bar: daisyUI **tabs-border** style, full width, **left-aligned**, white (`base-100`) background
  - Target: `.fi-sc-tabs[id$="relationManagerTabs"]` (Filament absolute id ends with `relationManagerTabs`)

## Create / Edit path

- Both **Create** and **Edit** open as a modal (`Width::FiveExtraLarge`) — no dedicated page/route
- Wired via shared helper `App\Filament\Support\TradingPartnerModalActions` (`create()` / `edit()`) for Admin Catalog + App
- Edit mutates partner fields only (no Sites | Products tabs; slug not editable/visible, locked to existing value)
- Create: slug auto-assigned from name; after create, an HQ site is created for the partner and the modal redirects to View
- After edit save, modal closes and the page stays on View (no redirect needed — it's already there)
- List page Create button and row-level Edit action both use the same helper
- App: same flow via `ViewTradingPartner` / `TradingPartnerModalActions::edit()` (`lockSlug: false`)
