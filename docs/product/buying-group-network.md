# Buying group network

Honest product status for pharmacy buying-group tenancy in TracePharma.

## GA today

| Surface | Notes |
|---|---|
| Buying group profile | Floor receive/ship and master-data CRUD stay **off** |
| Partner ATP readiness | Control-plane licence visibility |
| Compliance alert center | Integration/ATP signals without quarantine/3911 workstations |
| **Member roster** | CRUD roster of member pharmacies (`name`, optional `external_ref`, optional `member_tenant_id`, `status`, `contact_email`) under Compliance → Member roster |

Gate: `TenantFeatures::supportsBuyingGroupNetwork()` (BuyingGroup only) + `JobRoleAccess::allows(NavCompliance)`.

## Deferred (not GA)

- Member health scorecards / at-risk dashboards
- Authorized partner matrix (member ↔ wholesaler licences)
- Cross-tenant member compliance APIs (`/api/v1/compliance/*`)

Do not market those as shipped. Use Partner ATP readiness and Alert center for control-plane visibility meanwhile.

## Related

- Profile navigation: [`docs/product/profile-navigation.md`](./profile-navigation.md)
- Design: Wave 3 slice 1 in `docs/superpowers/specs/2026-08-28-wave3-role-expansion-design.md`
