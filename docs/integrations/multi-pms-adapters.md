# Multi-PMS certified adapters (conditional)

Peers sometimes market **30+ native PMS connectors**. TracePharma ships a **single Sanctum dispense-check API** plus checklist, Postman collection, and a sample webhook adapter.

## TracePharma today

- `POST /api/v1/dispense-check` with `vrs:dispense-check`
- [pms.md](pms.md) + Postman + in-app PMS integration checklist
- Verify Product workstation as staff fallback

## What is deferred

**Per-vendor certified adapters** (PioneerRx, Computer-Rx, QS/1, etc.) are **not** in the general release.

Ship additional adapters only when a **named pharmacy pilot** commits to a specific PMS and the dispense-check API is already proven for that tenant.

Until then:

1. Use the Sanctum API + sample webhook adapter.
2. Keep the PMS checklist honest: one reference integration, not 30 connectors.

## Related

- [pms.md](pms.md)
- [outbound-transports.md](outbound-transports.md)
