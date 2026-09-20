# Production DSCSA remediation principles

Date: 2026-09-14  
Status: accepted  
Scope: TracePharma production + DSCSA receive/EPCIS remediation (plan c9bcb022)

## Context

Remediation must raise DSCSA/EPCIS compliance and production engineering quality without inventing parallel receive stacks or rewriting custody history. These principles lock agent and human PRs for that work.

## Decision

### 1. Never hard-delete historical EPCIS events

Custody history is immutable. Do not `DELETE` projected event rows for “correction.”

- Aged retention: archive **MOVE** only.
- Corrections / supersession: new events, `valid_to` lifecycle on links/generations, and/or exception cases.
- Authored receive remains locked once `receiving_events_generated_at` is set.

### 2. Strangler + feature-flag for receipt `epcList` / gate severity

Any change that alters authored receipt `epcList` contents or raises Soft → HardBlocking (or equivalent) severity must:

- Strangler-extract behind a clear Action/Support seam first.
- Ship behind a feature flag / dual-run (default off, then tenant opt-in such as demo2).
- Prefer operator-copy and exception UX fixes that do **not** change authored custody lists.

### 3. Session complete ≠ ASN / order complete

Receiving session completion posts what **this session** confirmed. Expected-order / ASN completeness stays on `InboundShipment` + `inbound_expected_lines` rollups.

- Partial / short-close sessions must not silently finish the order.
- Shortage and accept-remaining gates attach to session/order truth separately from “session complete” UX.

### 4. Tests as compliance fixtures

Feature and unit tests for receive, exceptions, and EPCIS authoring are **compliance fixtures**, not optional coverage.

- Failures that encode attestation, shortage, TI gates, or immutability rules block merge.
- Prefer extending existing Pest suites over one-off scripts.

### 5. No parallel receive / EPCIS stacks

Keep Actions + existing `inbound_shipments` / `inbound_expected_lines` / `AggregationLink` / `epcis_*` tables.

- Domain GS1/EPCIS stays Eloquent-free behind Action adapters.
- Do not invent a second receive engine, second EPC store, or a new `ValidateAndCommitEpcisDocumentJob` — wire Domain soft signal into `ProcessEpcisDocument` when needed.
- Ingest validation truth remains `ValidateEpcis12Document`; Domain `Assert*` / `RunDomainEpcisHardGate::validateCandidate` is authoring-first.

## Consequences

- P0/P1 work favors copy, privilege gates, and exception creation over schema rewrites.
- P3 Domain ingest is soft/non-blocking first, then flag-raised severity.
- Skill docs and future ADRs must match code in tree, not aspirational job names.

## Ops follow-ups

Lightweight Phase 4 items (`.env.example` fail-closed comments/defaults) ship without new packages. The following remain deferred.

### Sentry (not installed)

`sentry/sentry-laravel` is **not** in `composer.json`. Do not add it in remediation PRs unless product explicitly requests APM. Recommended wiring when approved:

1. `composer require sentry/sentry-laravel` (pin a current stable; run `php artisan sentry:publish --dsn=`).
2. Set `SENTRY_LARAVEL_DSN` (and optional `SENTRY_TRACES_SAMPLE_RATE`) in stage/prod secrets only — never commit DSNs.
3. Ensure tenant context is tagged on the Hub (e.g. `tenant_id`, `tenant_domain`) via a middleware or `Sentry\configureScope` after Stancl tenancy boots; keep central (`admin` / marketing) events untagged or tagged `context=central`.
4. Exclude noisy `/up` and Horizon heartbeat noise; keep EPCIS job failures and Filament auth failures sampled at 1.0 until volume is known.
5. Verify `APP_ENV=production` + `APP_DEBUG=false` before enabling performance tracing in prod.

Until then, rely on Laravel logs + Horizon failed jobs + Watchdog email for ops signal.

### PHPStan path widen

CI currently runs the **narrow allowlist** in `phpstan.neon` (level 5, selected Support/Services paths). `phpstan-full.neon` / baseline exist for broader runs.

Follow-up (separate PRs, no big-bang):

1. Add one Action/Support subtree per PR to `phpstan.neon` `parameters.paths` (prefer already-touched receive/EPCIS seams first).
2. Fix new errors in-tree; only extend `phpstan-baseline.neon` for legacy noise with a dated owner comment.
3. Keep CI on the allowlist until the widened set stays green for a full week on main.
4. Do not raise level above 5 until the allowlist covers `app/Actions` + `app/Domain` without a growing baseline.
