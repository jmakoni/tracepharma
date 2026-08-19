# BH8 Bug Hunt — Design Spec

> Approved via brainstorming (hybrid scope C). Implementation plan: `docs/superpowers/plans/2026-08-16-bh8-bug-hunt.md`.

**Goal:** Close floor ship/transfer scan parity and kill-switch operator truth gaps after BH7, then clear deferred SFTP legacy + aggregation FK doctor ops. Stretch AS2 inbound only if Wave 1–2 finish early.

**Method:** Superpowers → `laravel-security-audit` / `mobile-scanner` / `dscsa-serialization-audit-debug` → `systematic-debugging` → TDD → minimal change.

**Non-goals:** Branding, Stripe, full AS2 inbound product, partner MIME quirks, demo `--receive-only`, full Pennant.

---

## Context

- BH7 closed Sanctum kill parity, Verification history SiteAccess, floor **receive** scan binding.
- Ship/transfer mobile still use deferred `wire:model="scan"`; camera JS calls `stageScan` while pages use `confirmScan`.
- Outbound kill can fail transmission while UI still shows “Shipment sent.”
- AS2 MDN webhook incorrectly gated by `inbound_epcis`.

## Wave 1 — Parity & floor

| ID | Fix |
|----|-----|
| BH8-1 | Ship + transfer mobile: live.blur + Enter DOM; camera → `confirmScan` |
| BH8-2 | Outbound kill / failed transmit: honest floor+desktop ship state (not “sent” success) |
| BH8-3 | AS2 MDN webhook → `OUTBOUND_EPCIS` kill |
| BH8-4 | VerifyProduct `todaysVerifications()` → `constrainVerifications` |
| BH8-5 | Analytics `vrsRates` site filter keeps actor-owned unlinked rows for site-restricted |

## Wave 2 — Integration ops

| ID | Fix |
|----|-----|
| BH8-6 | Legacy SFTP outbound: bulk deactivate + Integration Health badge |
| BH8-7 | Doctor aggregation FK: schedule detect and/or Admin surfacing; optional `--fix` |
| BH8-8 | Stretch: minimal AS2 inbound spike only if capacity remains |

**Order:** BH8-1…5 → BH8-6 → BH8-7 → BH8-8 stretch.

## Success criteria

- Hardware/camera scans work on ship and transfer floor like receive.
- Operators never see “Shipment sent” when outbound was killed or transmission failed.
- MDN webhook respects outbound kill, not inbound.
- Verify Product today list and Analytics site filter match Verification history SiteAccess rules.
- Legacy active SFTP rows deactivated or clearly flagged; FK drift visible/fixable without manual-only tribal knowledge.
- Tests green for touched suites.

## Out of scope

Full AS2 inbound product, branding, Stripe, self-service sandbox.
