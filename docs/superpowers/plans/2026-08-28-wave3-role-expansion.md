# Wave 3 Role Expansion Implementation Plan

> **For agentic workers:** Use subagent-driven-development or executing-plans. Checkbox steps for tracking.

**Goal:** Ship four honest Wave 3 MVPs per [`docs/superpowers/specs/2026-08-28-wave3-role-expansion-design.md`](../specs/2026-08-28-wave3-role-expansion-design.md).

**Architecture:** Profile-gated Filament surfaces + tenant migrations. No scan redesign. No EPC custody rewrite. No allocation/Guardian/compliance APIs.

**Tech Stack:** Laravel 13, Filament 5, Pest, Stancl tenancy.

## Global Constraints

- No Receive/Ship/Transfer/Pack/Unpack/VRS layout redesign
- No `/api/v1/compliance/*`; no fake BG health scores
- Principal = soft label/filter only
- L3 = forward log/retry only
- Do not commit unless asked

---

### Task 1: Buying group member roster

**Create:** migration `buying_group_members`; model; Filament Resource/Page; `TenantFeatures::supportsBuyingGroupNetwork()`; docs; banner update; tests.

Fields: `name`, `external_ref` nullable, `member_tenant_id` nullable string, `status` enum invited|active|suspended, `contact_email` nullable.

- [x] Failing access + CRUD tests
- [x] Implement migration/model/Filament/gate
- [x] Update ProfileNavigationMatrixTest + banner + docs
- [x] Green

---

### Task 2: 3PL principal registry

**Create:** `principals` table; model; Filament resource (Logistics3pl only); nullable `principal_id` on `sites` + `outbound_shipping_sessions`; form selects + list filters; docs; marketing vapor cleanup.

- [x] TDD create/attach/filter
- [x] Implement
- [x] Green + honesty docs

---

### Task 3: L3 forward log

**Create:** Filament page listing docs with `l3_forwarded_at` and/or open `L3_TRANSMISSION_FAILURE`; retry dispatches `ForwardCommissioningToL3`; Manufacturer gate; docs.

- [ ] TDD access + retry Bus::fake
- [ ] Implement
- [ ] Green

---

### Task 4: Prepack TransformationEvent + Asset Trace

**Create:** thin Repack/Transform page (Prepackager); action authoring TransformationEvent; extend BuildAssetTrace for input↔output edges; docs distinguishing Pack vs Transform.

- [x] TDD transform + trace edge
- [x] Implement minimal authoring using existing EPCIS event persist patterns
- [x] Green

---

### Task 5: CHANGELOG + roadmap + plan todo

---

Proceeding with **inline execution**.
