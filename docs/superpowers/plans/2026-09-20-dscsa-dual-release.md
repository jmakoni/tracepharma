# DSCSA Dual-Release (R1.2 / R1.3) Implementation Plan

> **For agentic workers:** Implement task-by-task with TDD. Steps use checkbox syntax.

**Goal:** Accept GS1 US DSCSA R1.2 and R1.3 on every inbound document, and emit exactly one release per trading-partner `epcis_guideline`.

**Architecture:** Detect release per document (not per tenant). Store it on `epcis_documents`. Outbound ship XML branches on `trading_partners.epcis_guideline`. Do not rewrite stored inbound bytes.

**Tech Stack:** Laravel 13, Pest, Filament, tenant migrations.

## Global Constraints

- Guideline R1.2/R1.3 ≠ EPCIS schema 1.2/1.3. Both guidelines author EPCIS 1.2 XML.
- No tenant-level “receive only one release” toggle.
- New Filament partners default to `r12`. Existing rows and factory default `r13` (preserve today’s outbound wire).
- Mixed reject only for contradictory constructs (both NDC type codes, or boolean + qualifier directPurchase). Transitional `guidelineVersion` + `FDA_NDC_11` is R1.3, not mixed.
- Out of scope: DETAIL event, `transactionDate`, void-ship, commissioning grouping, SBDH Authority flip to `GS1`, EPCIS 2.0 JSON-LD dialect.

---

### Task 1: Detect + persist + partner setting + outbound branch

Implemented:

- `EpcisGuideline` enum (`r12` / `r13`)
- `DetectDscsaGuidelineRelease` + persist `epcis_documents.dscsa_guideline_release`
- `MIXED_DSCSA_GUIDELINE_RELEASE` catalog finding (both NDC type codes, or boolean + qualifier DP)
- `trading_partners.epcis_guideline` (DB/factory default `r13`; Filament create default `r12`)
- Outbound lean + full-history + JSON-LD branch: R1.3 official `guidelineVersion` + `dropShipment` + qualifier DP; R1.2 omits those and emits boolean `directPurchase`
- Drop-ship fails closed when the partner is R1.2
