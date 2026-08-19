# BH6 Bug Hunt — Design Spec

> Approved via brainstorming (hybrid scope C). Implementation plan: `docs/superpowers/plans/2026-08-16-bh6-bug-hunt.md`.

**Goal:** Close high-severity authz/lifecycle bugs in recent Tenant Management work, then fix EPCIS aggregation reprocess retirement and VerifyProduct scorecard SiteAccess alignment.

**Method:** Superpowers process → `laravel-security-audit` / `dscsa-serialization-audit-debug` → `systematic-debugging` → TDD (`laravel-tdd`) → minimal change.

**Non-goals:** Branding, Stripe, AS2 inbound, Floor UX, Trial/Archived status enum, full Pennant.

---

## Context

- BH5 Waves 1–2 shipped (Admin Support gates, pair rollback, SFTP outbound fail-closed, JobRoleAccess, SLA jobs, onboarding slug release).
- Tenant Management P0/P1 shipped (suspend all surfaces, impersonation, kill switches, compliance export).
- Risk scan found P1/P2 holes in TM surfaces plus a failing aggregation retirement test and ungated VerifyProduct scorecard.

## Wave 1 — TM hardening

| ID | Finding | Fix |
|----|---------|-----|
| BH6-1 | Impersonation token logged plaintext in central activity | Log token id / hash prefix only — never raw token |
| BH6-2 | `inbound_epcis` kill switch skips Sanctum `POST /api/v1/epcis/inbound` | Assert kill switch in `EpcisInboundController` |
| BH6-3 | Bulk tenant delete bypasses compliance export ack | Wire `assertDeleteAllowed` + ack into `DeleteBulkAction` |
| BH6-4 | Kill switches do not cascade to pair sibling | Cascade kill switches on Admin save (mirror status cascade) |
| BH6-5 | Impersonation redeem TOCTOU (login before delete) | Atomically consume token before establishing session |
| BH6-6 | Suspended App/web throws DomainException → 500 | Structured 403 / logout redirect |

## Wave 2 — Custody + VRS visibility

| ID | Finding | Fix |
|----|---------|-----|
| BH6-7 | Reprocess deletes prior aggregation links via prune FK cascade instead of retiring with `valid_to` | Retire-then-preserve audit; reorder or null FK / soft-retire before prune |
| BH6-8 | VerifyProduct scorecard tenant-wide for site-restricted users | Gate behind `SitesAccessAll` like Today Activity |
| BH6-9 | Tests | Aggregation retirement green; scorecard hidden for site-restricted |

**Order:** BH6-1…6 → BH6-7 → BH6-8/9.

## Success criteria

- Activity log never stores redeemable impersonation tokens.
- Inbound kill switch blocks Sanctum inbound EPCIS.
- Bulk delete requires same export acknowledgement as single delete.
- Kill switches mirror to pair sibling.
- Concurrent impersonation redeem cannot double-login.
- Suspended tenant web access returns 403 (not 500).
- `AggregationLinkReprocessRetirementTest` fully green.
- Site-restricted VerifyProduct does not show tenant-wide VRS stats.

## Out of scope

AS2 inbound, Floor UX, branding, Stripe, self-service sandbox, custom domains/SSO.
