# Buying group network APIs

Sanctum JSON APIs for **BuyingGroup** tenants (Wave F4). Snapshot / roster control-plane data only — **no EPC or EPCIS document payloads**.

## Auth

1. Tenant host must be a **BuyingGroup** profile (`TenantFeatures::supportsBuyingGroupNetwork()`).
2. Create an API token in **Settings → API tokens** with ability `buying-group:network`.
3. Call with `Authorization: Bearer <token>` and `Accept: application/json`.

Pharmacy / wholesaler profiles receive **403** even if the token lists the ability.

Shared Sanctum middleware already applies `throttle:60,1` on `/api/v1/*`.

## Endpoints

| Method | Path | Notes |
|---|---|---|
| GET | `/api/v1/buying-group/members` | Paginated roster (`per_page` 1–100, default 25) |
| GET | `/api/v1/buying-group/members/{id}/readiness` | Roster member readiness snapshot (soft rows stay N/A until hard-linked + rollup) |
| GET | `/api/v1/buying-group/network/summary` | Enrollment counts + health aggregates + tenant `affiliation_code` |
| GET | `/api/v1/buying-group/partner-matrix` | Paginated partner licence facts (`license_status` optional filter) |

## Related

- Product status: [`docs/product/buying-group-network.md`](../product/buying-group-network.md)
- Postman: [`docs/integrations/postman/tracepharma-buying-group-network.json`](./postman/tracepharma-buying-group-network.json)
- Rollup job: `php artisan tracepharma:buying-group-rollup`
