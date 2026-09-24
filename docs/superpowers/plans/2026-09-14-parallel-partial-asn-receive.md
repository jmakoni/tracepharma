# Parallel Partial ASN Receive Implementation Plan

> **For agentic workers:** Implement task-by-task. Spec: `docs/superpowers/specs/2026-09-14-parallel-partial-asn-receive-design.md`

**Goal:** When `receiving.allow_parallel_sessions` is on, every ASN open starts empty (claim-on-scan), and Complete Receive unlocks after ≥1 confirmed (explicit press, no auto-complete).

**Architecture:** Extend existing parallel/claim plumbing; change seed-on-open gate and Complete readiness only when the setting is on.

**Tech Stack:** Laravel, Filament HUD/floor, Pest feature tests, tenant migrations for wholesaler.

## Global Constraints

- Parallel off behavior unchanged
- Accept remaining stays session-scoped
- No new org setting
- Source-first; migrate wholesaler after code

---

## Task 1: Tests first

- [ ] Add/extend `ParallelReceivingSessionsTest`: first opener empty when parallel on; Complete after one confirm; no auto-complete; two sessions independent complete; parallel off regression

## Task 2: Open empty always when parallel on

- [ ] `OpenReceivingSessionFromDocument`: `seedOnOpen = !($allowParallel && $shipmentId !== null)`

## Task 3: Complete readiness

- [ ] Shared readiness helper (session or policy): parallel on → confirmed ≥ 1 && !openToteLock
- [ ] Wire `canCompleteManually` (HUD + Scan In) and `CompleteReceivingSession` gate
- [ ] Skip ASN auto-complete in `ConfirmReceivingScan` when parallel on

## Task 4: Verify + migrate wholesaler

- [ ] Run parallel + related receiving tests
- [ ] `tenants:migrate` (or equivalent) for wholesaler
- [ ] Confirm session 9 can Complete with current 2 confirms (or document reopen if needed)
