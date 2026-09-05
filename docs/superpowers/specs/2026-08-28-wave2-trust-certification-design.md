# Wave 2 — Trust / certification — Design Spec

**Date:** 2026-08-28  
**Status:** Approved (user) — awaiting written-spec review before implementation plan  
**Plan source:** [Tenant Type Feature Gaps](/home/jmakoni/.cursor/plans/Tenant%20Type%20Feature%20Gaps-365df164.plan.md) Wave 2  
**Approach:** Honest evidence pack (not TraceReady / Pulse-listed / Gateway Certified branding). Order: **cert-first** (scenario export + VRS readiness), then Pulse manual evidence + partner ingest quality rollup.

**Goal:** Close mid-market RFP diligence gaps with exportable internal conformance evidence, a documented VRS go-live path, honest ATP Pulse evidence capture, and partner ingest quality visibility — without claiming GS1 Trustmark, TraceReady, Gateway Certified, or NABP Pulse listing.

---

## Locked decisions

| Topic | Decision |
|---|---|
| Branding | Never claim TraceReady, GS1 Trustmark, Gateway Certified, or Pulse-listed for TracePharma |
| Order | Slice 1 scenario export → Slice 2 VRS readiness → Slice 3 Pulse evidence → Slice 4 partner quality |
| Conformance | Internal scenario matrix + Artisan export (Markdown + JUnit/JSON) from existing fixtures/catalog |
| VRS prod default | Keep `null` → deferred until real `VRS_DRIVER=http` + configured endpoint |
| Pulse / OCI / Spherity | Manual verification evidence source only — **no** API clients |
| Clean-data | Read-only partner ingest exception rollup; optional hard gate deferred |
| Compliance APIs | Do not invent `GET /api/v1/compliance/*` |
| Scan pages | No redesign |

---

## Non-goals

- GS1 Exchange / Query Control Interface certification
- TraceReady or Gateway Checker product packaging / badges
- Live NABP Pulse directory sync or OCI/Spherity token verify APIs
- Fake Integration Health “Pulse connection”
- Certified per-vendor PMS HTTP adapters (Wave 1 runbooks remain)
- Dual-stack ship / AS2 inbound form (Wave 4)

---

## Slice 1 — GS1 US Rx scenario evidence export

### Behavior

1. Define a curated scenario matrix (target 10–20 rows) in code or YAML under `tests/Fixtures/epcis/` or `app/Support/Epcis/Conformance/`, each with:
   - scenario id + short title
   - fixture path (existing where possible)
   - expected catalog rule IDs / pass-fail expectation
   - IG reference note (e.g. GS1 US Rx / R1.2–R1.3 talk-track — internal mapping only)
2. Artisan command e.g. `epcis:export-scenario-evidence` that:
   - runs scenarios against existing validation engine (or records fixture validation results)
   - writes Markdown summary + JUnit XML (and/or JSON) to a configurable path (default under `storage/app/evidence/` or `docs/evidence/generated/`)
3. Docs: `docs/integrations/epcis-scenario-evidence.md` — honesty banner that this is **internal DSCSA/GS1 US IG scenario evidence**, not Trustmark/TraceReady/Gateway Checker certified.
4. Optional stretch (same slice if cheap): Admin or Settings download link — only if Filament pattern already exists for similar exports; otherwise CLI-only is GA.

### Failure modes

- Missing fixture → scenario marked failed with clear error in report (fail closed, not silent skip).
- Command exits non-zero if any required scenario fails (CI-friendly).

### Tests

- Unit/feature: matrix loads; at least one pass and one expected-fail scenario produce correct report rows.
- Command smoke test with temp output path.

### Key files

- New: scenario matrix + exporter command + evidence docs
- Reuse: `ValidateEpcis12Document` / catalog engine, `tests/Fixtures/epcis/*`

---

## Slice 2 — VRS Verify readiness

### Behavior

1. Docs: `docs/integrations/vrs-verify-readiness.md` — checklist for `VRS_DRIVER=http`, real base URL/API key/requestor GLN, smoke via VerifyProduct + `POST /api/v1/dispense-check`, optional responder webhook.
2. When `VRS_DRIVER=http`, fail closed on placeholder hosts (already partially in `HttpVrsClient`) — ensure go-live path calls `assertConfigured()` at a sensible boot or first-use gate without breaking local `fake`/`null`.
3. Artisan or Filament-adjacent export: recent verification rows (N, configurable) as JSON/CSV “VRS Verify readiness log” with honesty header (not Gateway Certified). Prefer Artisan `vrs:export-readiness-log` for GA.
4. Update `.env.example` / roadmap talk-track if needed for clarity (prod null default intentional).

### Failure modes

- Export with no rows → empty pack + message, exit 0.
- `http` driver + placeholder URL → clear DomainException / config error (existing behavior extended, not weakened).

### Tests

- Export command produces file with honesty marker.
- Placeholder host still rejected when driver is http.

### Key files

- `config/vrs.php`, `HttpVrsClient`, `NullVrsClient`, VerifyProduct / verification models
- New: readiness doc + export command + tests

---

## Slice 3 — ATP Pulse / OCI manual evidence

### Behavior

1. Extend `AtpVerificationSource` (or equivalent enum) with a value such as `pulse_partner_evidence` / `oci_partner_evidence` (or single `third_party_directory_evidence`) for **manual** partner-supplied Pulse/OCI screenshots or profile URLs.
2. Existing Record ATP verification Filament action accepts source + optional evidence URL/notes (reuse partner-doc pattern).
3. Marketing: keep NABP Pulse “not yet listed”; no go-live date invention.
4. Docs: short note under ATP / partner readiness — manual evidence ≠ Pulse API integration.

### Failure modes

- No automatic Pulse sync; selecting the source without notes/URL may warn but not invent credentials.

### Tests

- Enum/source accepted on verification record; Filament/action validation if applicable.

### Key files

- `AtpVerificationSource`, Record ATP verification action, partner readiness docs/marketing (honesty only)

---

## Slice 4 — Partner ingest quality rollup

### Behavior

1. Read-only metrics: per `trading_partner_id` (inbound docs), counts of ingest-related exceptions over 7d and 30d (parse errors, catalog validation failures, ATP soft warnings — map to existing exception type codes).
2. Surface: new lightweight Filament page under Compliance or a section on Integration Health (prefer Compliance “Partner data quality” if Integration Health is transport-heavy).
3. Gate with existing `supportsInboundIntegrations()` / compliance nav permissions.
4. Docs: “Partner ingest quality rollup — not clean-data certified / not TraceReady.”
5. Optional hard gate (partner fail-rate block receive) **out of GA** unless trivial; defer to follow-up.

### Failure modes

- Partners with zero inbound traffic → empty or zero rows, not errors.
- SiteAccess: respect existing document/partner scoping patterns for the acting user.

### Tests

- Feature: seed partner docs/exceptions → rollup shows expected counts.
- Buying group / no-inbound profiles cannot access page.

### Key files

- New metrics class + Filament page
- Reuse: `EpcisException` / exception types, inbound documents, Integration Health patterns

---

## Implementation order

1. Scenario evidence export  
2. VRS readiness docs + export + http assert path  
3. Pulse/OCI manual verification source  
4. Partner ingest quality rollup  
5. CHANGELOG Unreleased + roadmap pilot-only talk-track refresh  

---

## Success criteria

| Slice | Done when |
|---|---|
| Scenario export | Command writes MD + JUnit; docs deny TraceReady branding; tests green |
| VRS readiness | Checklist doc + export log; http placeholders still fail closed |
| Pulse evidence | Manual source selectable; marketing still “not listed” |
| Partner quality | Page shows 7/30d ingest exception counts per partner |

---

## Spec self-review

- [x] No TraceReady/Pulse-listed/Gateway Certified product claims  
- [x] Cert-first order locked  
- [x] All four trust-pack slices covered  
- [x] Non-goals explicit  
- [x] No `/api/v1/compliance/*` invention  

---

## Next step

User reviews this file. On confirmation, invoke **writing-plans** then implement with Laravel TDD (cert slices first).
