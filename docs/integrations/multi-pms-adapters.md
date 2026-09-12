# Multi-PMS certified adapters (conditional)

Peers sometimes market **30+ native PMS connectors**. TracePharma ships a **single Sanctum dispense-check API** plus checklist, Postman collection, sample webhook adapter, and **named vendor runbooks** that map PMS middleware onto that API.

## TracePharma today

- `POST /api/v1/dispense-check` with `vrs:dispense-check` — **no** `/api/v1/pms/{vendor}/dispense` product routes
- [pms.md](pms.md) + Postman + in-app PMS integration checklist
- Vendor runbooks (mapping + cutover; same unified API):
  - [pms/pioneerrx.md](pms/pioneerrx.md)
  - [pms/bestrx.md](pms/bestrx.md)
  - [pms/primerx.md](pms/primerx.md)
  - [pms/liberty-rx30.md](pms/liberty-rx30.md)
  - [pms/qs1.md](pms/qs1.md)
- Verify Product workstation as staff fallback

## What is deferred

**Per-vendor certified adapters** (PioneerRx, BestRx, PrimeRx, Liberty/Rx30, QS/1, Computer-Rx, etc.) are **not** in the general release. Runbooks document how to call the unified API; they are not certified connector products.

Ship additional adapters only when a **named pharmacy pilot** commits to a specific PMS and the dispense-check API is already proven for that tenant.

Until then:

1. Use the Sanctum API + sample webhook adapter + the vendor runbook for your PMS.
2. Keep the PMS checklist honest: one reference integration, not 30 connectors.

## Related

- [pms.md](pms.md)
- [outbound-transports.md](outbound-transports.md)
