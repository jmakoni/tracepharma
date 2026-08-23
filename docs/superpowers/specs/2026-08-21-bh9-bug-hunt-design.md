# BH9 Bug Hunt — Design Spec

> Approved via brainstorming (hybrid scope). Implementation plan: `docs/superpowers/plans/2026-08-21-bh9-bug-hunt.md`.

**Goal:** Close post-BH8 TraceLink commission/Reserved integrity gaps and remaining floor/ops debt.

**Method:** Superpowers → `dscsa-serialization-audit-debug` / `laravel-security-audit` / `mobile-scanner` → TDD → minimal change.

**Non-goals:** Branding, Stripe, full AS2 inbound product, unbounded finding caps, TraceLink park/retry protocol.

---

## Context

- BH8: mobile scan parity, honest outbound-kill UX, MDN outbound kill, SiteAccess edges, SFTP legacy deactivate, aggregation FK doctor.
- Follow-on: packing-before-commission (`COMMISSION_AFTER_SHIP`); TraceLink Reserved (`MISSING_COMMISSIONING`).
- Gaps: silent 50/type truncation; Soft vs HardBlocking; receiving-before-commission; desktop scan lag; Hub doctor cold-start; outbound shipping test fixtures.

## Wave 1 — TraceLink / commission integrity

| ID | Fix |
|----|-----|
| BH9-1 | Truncation overflow aggregated finding when `max_findings_per_type` drops hits |
| BH9-2 | `MISSING_COMMISSIONING` → HardBlocking receive impact |
| BH9-3 | Keep `MISSING_COMMISSIONING` critical (no demo → warning demote) |
| BH9-4 | Same-doc usable-commission gate includes receiving ObjectEvents |
| BH9-5 | Dedupe: no `COMMISSION_AFTER_SHIP` if `MISSING_COMMISSIONING` already fired for same EPC |

**Order:** BH9-1 → BH9-5 → BH9-4 → BH9-2 → BH9-3.

## Wave 2 — Floor / ops / hygiene

| ID | Fix |
|----|-----|
| BH9-6 | Desktop ship/transfer `live.blur` + Enter → `stageScan` |
| BH9-7 | Hub aggregation FK doctor never-checked / detect surfacing |
| BH9-8 | Repair `OutboundShippingSessionTest` demo fixtures |
| BH9-9 | Document `SHIP_BEFORE_COMMISSION` as superseded orphan |

**Stretch:** AS2 inbound — skip unless capacity remains.

## Success criteria

- Large inbound docs surface truncation when commission/Reserved findings exceed the cap.
- Reserved/uncommissioned receive/pack/ship HardBlock; demo does not warn-only.
- Receiving-before-usable-commission flagged; no double CAS+MISSING on same EPC.
- Desktop scan parity; Hub FK-check status; outbound shipping suite trustworthy.
- Focused Pest green; roadmap Bug-hunt #9 lines.

## Out of scope

Branding, Stripe, full AS2 inbound, self-service sandbox.
