# Wave 2 Trust / Certification Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Ship honest trust evidence — internal EPCIS scenario export, VRS readiness checklist/export, manual Pulse ATP evidence source, partner ingest quality rollup — per [`docs/superpowers/specs/2026-08-28-wave2-trust-certification-design.md`](../specs/2026-08-28-wave2-trust-certification-design.md).

**Architecture:** Cert-first. Scenario export reuses `ValidateEpcis12Document` + fixtures under tenancy. VRS stays fail-closed on placeholders. Pulse is manual enum only. Partner quality is read-only Filament metrics.

**Tech Stack:** Laravel 13 Artisan, Pest/PHPUnit, Filament 5, Stancl tenancy.

## Global Constraints

- Never claim TraceReady / GS1 Trustmark / Gateway Certified / Pulse-listed
- No scan-page redesign; no `/api/v1/compliance/*`
- No live Pulse/OCI/Spherity API clients
- Do not commit unless user asks

---

### Task 1: Scenario matrix + evidence exporter

**Files:**
- Create: `app/Support/Epcis/Conformance/ScenarioMatrix.php` (or YAML + loader)
- Create: `app/Console/Commands/ExportEpcisScenarioEvidenceCommand.php`
- Create: `docs/integrations/epcis-scenario-evidence.md`
- Create: `tests/Feature/Epcis/ExportEpcisScenarioEvidenceCommandTest.php`

**Interfaces:**
- Produces: `epcis:export-scenario-evidence {--tenant=} {--output=} {--format=md|junit|all}` exit 0 only if all expected outcomes match

Scenarios (existing fixtures only):

| id | fixture | expect |
|----|---------|--------|
| rx-r12-minimal-pack | minimal_object_shipping.xml | pass |
| rx-r12-missing-locations | commissioning_sscc_missing_locations.xml | fail |
| rx-schema-1-3-pack | minimal_object_shipping_1.3.xml | pass |
| rx-r12-shipping-masterdata | minimal_with_shipping_refs.xml | pass |
| rx-r12-3pl-four-party | shipping_3pl_four_party.xml | pass |

Implementation: initialize tenant → for each scenario ingest/validate fixture via existing receive+validate path (mirror Feature test helpers) → compare pass/fail → write Markdown + JUnit to `--output` dir (default `storage/app/evidence/epcis-scenarios`).

Honesty banner in MD and docs: not TraceReady/Gateway Checker certified.

- [x] **Step 1:** Failing test that command writes report for tenant
- [x] **Step 2:** Implement matrix + command
- [x] **Step 3:** Docs + green tests

---

### Task 2: VRS readiness docs + export

**Files:**
- Create: `docs/integrations/vrs-verify-readiness.md`
- Create: `app/Console/Commands/ExportVrsReadinessLogCommand.php` (`vrs:export-readiness-log`)
- Create: `tests/Feature/Vrs/ExportVrsReadinessLogCommandTest.php`
- Modify: `.env.example` VRS comments if needed

Command: `{--tenant=} {--limit=100} {--output=}` loops active tenants (or one), exports recent `verifications` JSON with honesty header field `"certification_claim": "none — not Gateway Certified"`.

- [ ] **Step 1–3:** TDD + docs + assert HttpVrsClient placeholder still fails

---

### Task 3: Manual Pulse/OCI ATP evidence source

**Files:**
- Modify: `AtpVerificationSource` enum + Filament record action labels
- Modify: docs/marketing ATP honesty note if needed
- Test: enum / action accepts new source

- [ ] **Step 1–3:** Add `pulse_partner_evidence` (and optionally `oci_partner_evidence`) — manual only

---

### Task 4: Partner ingest quality rollup

**Files:**
- Create: `app/Support/Integrations/PartnerIngestQualityMetrics.php`
- Create: Filament page `PartnerIngestQuality` (Compliance group)
- Create: feature test
- Docs: short section in epcis or integrations

Metrics: per trading_partner_id, 7d/30d counts of ingest exception types (parse / INTERNAL_VALIDATION / catalog hard codes — use existing exception_type codes).

Gate: `supportsInboundIntegrations()` + NavCompliance or NavIntegrations.

- [ ] **Step 1–3:** TDD + page + docs

---

### Task 5: CHANGELOG + roadmap

Update Unreleased + pilot-only talk-track for Wave 2 honesty.

---

## Spec coverage

| Spec slice | Tasks |
|------------|-------|
| Scenario export | 1 |
| VRS readiness | 2 |
| Pulse evidence | 3 |
| Partner quality | 4 |
| Docs/CHANGELOG | 1–5 |

Proceeding with **inline execution**.
