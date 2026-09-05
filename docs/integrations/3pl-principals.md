# 3PL principals (soft label)

Logistics 3PL tenants can maintain a **principal registry** and optionally tag sites and outbound ship orders with a principal.

## What this is

- Master Data → **Principals** CRUD (`name`, optional `gln`, `is_active`)
- Optional `principal_id` on `sites` and `outbound_shipping_sessions`
- List filters on Sites (and Ship Orders) by principal when the tenant profile is `Logistics3pl`

Gate: `TenantFeatures::supportsPrincipals()` (true only for `TenantProfile::Logistics3pl`) plus `JobRoleAccess` / `nav.master_data`.

## What this is not

- **Not** EPC-level multi-client custody isolation. Inventory, verification, and EPCIS custody remain tenant-scoped (see `EpcCustodyGate`).
- **Not** principal-scoped GA scorecards, exception reporting, or warehouse partition walls between clients.
- Deleting a principal nulls FKs (`nullOnDelete`); it does not rewrite historical EPCIS.

Use principals as **soft labels / filters** for ops visibility. True multi-principal custody isolation remains on the product roadmap.
