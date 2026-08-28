# Wave 3 — Role expansion — Design Spec

**Date:** 2026-08-28  
**Status:** Approved (user) — awaiting written-spec review before implementation plan  
**Plan source:** [Tenant Type Feature Gaps](/home/jmakoni/.cursor/plans/Tenant%20Type%20Feature%20Gaps-365df164.plan.md) Wave 3  
**Approach:** Four honest MVPs (roster, principal soft-tag, L3 forward log, TransformationEvent + trace). Order: BG → 3PL → L3 → prepack.

**Goal:** Expand role-shaped product beyond pharmacy/wholesaler ICP without overclaiming peer network, multi-client custody, plant allocation, or multi-hop repack products.

---

## Locked decisions

| Topic | Decision |
|---|---|
| Buying group | Member roster only; floor/master/inbound stay off; no health scorecards or compliance APIs |
| 3PL principal | Registry + optional FK filter; **not** EPC-level custody isolation |
| Manufacturer L3 | Forward log + retry; **no** SGTIN allocation API; **no** Guardian ingest; **no** L2↔L3 count reconcile |
| Prepack | TransformationEvent authoring + Asset Trace edges; Pack/BreakPack unchanged as aggregation tools |
| Scan pages | No Receive/Ship/Transfer/Pack/Unpack/VRS workstation layout redesign |
| Marketing | Fix remaining “principal-scoped / member health” vapor where still present |

---

## Non-goals

- Cross-tenant member compliance APIs (`/api/v1/compliance/*`)
- Buying-group auth matrix / at-risk health dashboards
- Full 3PL multi-client custody partition
- Public L3 allocation pools / Guardian lot feed product
- Full multi-hop “repack page” GTM product
- Wave 4 network / EPCIS 2.0 ship / AS2 inbound form

---

## Slice 1 — Buying group member roster

### Behavior

1. Tenant migration: `buying_group_members` with `name`, `external_ref` (nullable string), `member_tenant_id` (nullable UUID string), `status` (`invited|active|suspended`), `contact_email` (nullable), timestamps.
2. Model + Filament resource or simple page CRUD (Owner/SupportEngineer).
3. `TenantFeatures::supportsBuyingGroupNetwork(): bool` → true only for `BuyingGroup`.
4. Nav: Compliance or Organization group “Member roster”.
5. Update buying-group limited banner to link/point to roster.
6. Docs: `docs/product/buying-group-network.md` — roster GA; matrix/APIs/health deferred.

### Tests

- BG owner can CRUD members; Pharmacy cannot access.
- ProfileNavigationMatrix / TenantFeaturesTest updated for new helper.

---

## Slice 2 — 3PL principal registry (soft)

### Behavior

1. Tenant migration: `principals` (`name`, `gln` nullable, `is_active`, timestamps).
2. Optional `principal_id` nullable FK on `sites` and `outbound_shipping_sessions` (or sessions only if sites migration is heavier — prefer both for filter utility).
3. Filament: Principal resource (3PL profile only) + select on Site / Ship Order forms when `TenantProfile::Logistics3pl`.
4. List filters by principal where cheap (Sites table, Ship Orders).
5. Docs honesty: label/filter only; EPC custody still tenant-scoped via `EpcCustodyGate`.
6. Soften home/compare vapor claiming principal-scoped GA if still present.

### Tests

- 3PL can create principal and attach to site/session; wholesaler page gated off (or hidden).
- Filter returns expected rows.

---

## Slice 3 — Manufacturer L3 forward log

### Behavior

1. Filament page “L3 forward log” (Manufacturer + L3 enabled or commissioning support):
   - Rows: outbound/commissioning docs with `l3_forwarded_at` set and/or open `L3_TRANSMISSION_FAILURE`
   - Action: retry → dispatch `ForwardCommissioningToL3` when eligible
2. Subheading honesty: forward status only — not allocation / Guardian / reconcile.
3. Docs: short L3 ops note; keep marketing “no public allocation API”.

### Tests

- Page access manufacturer vs pharmacy.
- Retry dispatches job (fake/bus assert) when failure present.

---

## Slice 4 — Prepack TransformationEvent + Asset Trace

### Behavior

1. Action/service: create TransformationEvent from selected input EPC IDs → new output SGTINs (or existing commissioned outputs), persist via existing EPCIS authoring patterns (`transformation_id`, inputEPC/outputEPC). Prefer thin Filament page “Repack (transform)” gated to Prepackager — **not** redesigning PackWorkstation layout.
2. Extend `BuildAssetTrace` (or equivalent) to include transformation input↔output edges in addition to aggregation ancestors.
3. Docs: Pack/BreakPack = aggregation; Repack transform = lineage; original-link TI extensions deferred.

### Tests

- Transform creates event + linkable outputs.
- Asset Trace shows transformation edge.
- Non-prepackager cannot access page.

---

## Implementation order

1. Buying group roster  
2. 3PL principals  
3. L3 forward log  
4. Prepack transform + trace  
5. CHANGELOG + roadmap + marketing vapor cleanup  

---

## Success criteria

| Slice | Done when |
|---|---|
| BG roster | CRUD members; BG-only access; banner/docs honest |
| 3PL principal | Registry + optional FK + filter; docs deny full custody |
| L3 log | Manufacturer sees forward/fail + retry |
| Prepack | TransformationEvent authored; Asset Trace shows edges |

---

## Spec self-review

- [x] All four slices covered with honest non-goals  
- [x] No scan redesign; no compliance APIs; no allocation/Guardian  
- [x] Principal custody explicitly soft  
- [x] Order locked  

---

## Next step

User reviews this file. On confirmation, invoke **writing-plans**, then implement with Laravel TDD.
