# Design System Master File

> **LOGIC:** When building a specific page, first check `design-system/tracepharma/pages/[page-name].md`.
> If that file exists, its rules **override** this Master file.
> If not, strictly follow the rules below.

---

**Project:** TracePharma  
**Updated:** 2026-07-30  
**Category:** Data-Dense B2B SaaS Dashboard (DSCSA / pharma supply chain)

### Design Dials

| Dial | Value | Meaning |
|------|-------|---------|
| Variance | 4/10 | Balanced / modern |
| Motion | 3/10 | Subtle micro-interactions only |
| Density | 8/10 | Dense dashboard / Master Data |

---

## Global Rules

### Color Palette (shipped brand — Coolors / daisyUI)

| Role | Hex | Notes |
|------|-----|--------|
| Primary | `#51BC8F` | Garish Green — Filament + daisyUI primary |
| On Primary | `#FCFCFD` | White label on solid primary buttons |
| Secondary | `#838589` | Heavy Grey |
| Background | `#FCFCFD` / `#E3E5EA` | Brilliance / Little Dipper surfaces |
| Foreground | `#676C73` | Wall Street body |
| Muted / Border | `#AEB2B9` | Base-300 |
| Destructive | `#EA4758` | Error |
| Warning | `#FFAB00` | Amber |

**Do not** replace primary with generic navy/blue SaaS defaults from external generators.

### Typography

- **Heading / UI (guidance):** Fira Sans — *deferred* in Filament viteTheme until structure ships
- **GS1 identifiers (GTIN, GLN, NDC, SGLN):** Fira Code / monospace, copyable
- Until fonts land, use Filament defaults + mono for IDs

### Spacing (dense)

| Token | Value | Usage |
|-------|-------|--------|
| `--space-xs` | `4px` | Tight gaps |
| `--space-sm` | `8px` | Icon / inline |
| `--space-md` | `16px` | Section padding |
| `--space-lg` | `24px` | Section-to-section max |
| `--space-xl` | `32px` | Rare large gaps |

### Style

**Data-Dense Dashboard** — tables, sectioned forms, KPI/status where needed; minimal padding; scannable.

**Motion:** 150–300ms transitions; row hover highlight; respect `prefers-reduced-motion`. No scroll-hijack or ornate GSAP on Master Data.

---

## Component Specs

### Buttons

- Solid primary: background `#51BC8F`, text/icon `#FCFCFD`
- Icons on common actions (Create, Save, Edit, Delete, View, Cancel)
- Outlined / gray secondary for Cancel

### Tables

- Debounced search (~500ms), filters (Active default where lists are directories), pagination `[10, 25, 50]`
- Status via **badge text** (Active / Inactive), not color-only icons
- GS1 columns: mono + copyable

### Forms / Infolists

- Filament `Section` groups: Identity → Address → Contact → Status → Geo
- View pages use **infolist**; Edit uses **form**
- No landing heroes, metrics strips, or marketing CTAs on Master Data

---

## Anti-Patterns (Do NOT Use)

- Landing-style Hero / How it works / CTA blocks on Master Data
- Ornate glassmorphism / purple gradients / dark-by-default
- Emoji-as-icons
- Status conveyed by color alone
- Tables with no filters on directory lists
- Replacing brand green with generic blue primary

---

## Pre-Delivery Checklist

- [ ] No emojis as icons (Heroicons)
- [ ] Primary buttons: white text on `#51BC8F`
- [ ] Hover / focus visible; 150–300ms transitions
- [ ] Light mode contrast ≥ 4.5:1
- [ ] `prefers-reduced-motion` respected
- [ ] Responsive: 375 / 768 / 1024 / 1440
- [ ] GS1 IDs mono + copyable where shown
