---
title: FDA WDD
parent: registry
order: 35
group: Registry
---

# FDA WDD

Filament classes:

- `App\Filament\Admin\Resources\Fda\FdaWddFacilities\FdaWddFacilityResource`
- `App\Filament\Admin\Resources\Fda\FdaWddLicenses\FdaWddLicenseResource`

## When to use

Maintain Wholesale Distribution / 3PL facility registry rows and their state licenses for ATP reference.

## Prerequisites

- Admin registry access.
- WDD import or staging data available.

## Steps

1. Open **FDA WDD facilities**; search and open a facility. Open the page and use Help for live UI.
2. Review **Licenses** on the facility or via the licenses resource.
3. Correct obvious data issues; prefer re-import for systemic problems.
4. Cross-check tenant ATP readiness after major updates.

## Related pages

- [fda-organizations.md](../registry/fda-organizations) — owning organizations
- [../operations/wdd-3pl-staging.md](../operations/wdd-3pl-staging) — staging / unmatched
- [../operations/fda-imports.md](../operations/fda-imports) — import runs
- [../../app/compliance/atp-readiness.md](../../app/compliance/atp-readiness.md) — tenant ATP view

## Notes

- License expiry in registry data drives tenant ATP gap reports — keep imports current.
- Unmatched staging rows should be resolved before forcing facility creates.
