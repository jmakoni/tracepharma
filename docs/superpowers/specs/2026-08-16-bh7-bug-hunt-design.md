# BH7 Bug Hunt — Design Spec

> Approved via brainstorming (hybrid scope C). Implementation plan: `docs/superpowers/plans/2026-08-16-bh7-bug-hunt.md`.

**Goal:** Close Sanctum/kill-switch and aggregation-migration parity gaps left after BH6, then align Verification/Analytics VRS visibility for site-restricted users and fix floor mobile receive scan binding.

**Method:** Superpowers → `laravel-security-audit` / `dscsa-serialization-audit-debug` / `mobile-scanner` → `systematic-debugging` → TDD → minimal change.

**Non-goals:** Branding, Stripe, AS2 inbound MVP, legacy SFTP bulk deactivate, demo `--receive-only` polish, full Pennant.

---

## Context

- BH5/BH6 and Tenant Management P0/P1 shipped.
- BH6 closed inbound Sanctum kill and VerifyProduct scorecard; outbound Sanctum and documents list still fail-open on kill switches.
- Aggregation retirement FK drop requires tenant migration; old DBs may still cascade-delete retired links.
- Verification history scopes only via exception relation — site-restricted users lose successful verifies.

## Wave 1 — Parity & compliance

| ID | Fix |
|----|-----|
| BH7-1 | Fail-closed `outbound_epcis` on `POST /api/v1/epcis/outbound` |
| BH7-2 | Fail-closed `inbound_epcis` on `GET /api/v1/epcis/documents` |
| BH7-3 | Aggregation FK doctor: detect cascade FK still present; migrate/alert |
| BH7-4 | Bulk delete partial-failure clarity + tests |

## Wave 2 — Operator visibility + floor

| ID | Fix |
|----|-----|
| BH7-5 | Verification history SiteAccess: exception→site **or** actor ownership for site-restricted; access-all unchanged |
| BH7-6 | Analytics `vrsRates` aligned with same rules (no tenant-wide rates for site-restricted) |
| BH7-7 | Floor mobile receive: live/blur scan binding on mobile receiving session |

**Order:** BH7-1…4 → BH7-5 → BH7-6 → BH7-7.

## Success criteria

- Outbound Sanctum and documents index respect kill switches (403).
- Doctor flags (and can migrate) tenants still on aggregation cascade FK.
- Bulk delete does not leave unexplained half-pair state; tests cover partial failure.
- Site-restricted users see their own / site-linked verifications, not tenant-wide aggregates.
- Mobile floor receive scan confirms reliably with hardware scanners.
- Tests green for touched suites.

## Out of scope

AS2 inbound, SFTP legacy cleanup, branding, Stripe, Floor menu trim beyond scan binding.
