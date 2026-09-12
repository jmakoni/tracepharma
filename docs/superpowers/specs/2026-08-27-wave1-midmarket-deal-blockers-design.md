# Wave 1 — Mid-market deal blockers — Design Spec

**Date:** 2026-08-27  
**Status:** Approved (user) — awaiting implementation plan after spec review  
**Plan source:** [Tenant Type Feature Gaps](/home/jmakoni/.cursor/plans/Tenant%20Type%20Feature%20Gaps-365df164.plan.md) Wave 1  
**Approach:** Sequential slices in one branch (SFTP → MDN → POET apply-form → drop-ship indicator → PMS runbooks)

**Goal:** Close the mid-market RFP gaps peers sell as table stakes—outbound SFTP, AS2 MDN catalog emitters, partner apply-form on the exception portal, honest drop-ship indicator path, and named PMS runbooks—without scan-page redesign or TraceLink-scale network products.

---

## Locked decisions

| Topic | Decision |
|---|---|
| Delivery shape | Five GA slices in one branch, sequential; each slice has Pest coverage before the next |
| Scan pages | **No redesign** of Receive / Ship / Transfer / Pack / Unpack / Scan In/Out / VRS workstation layouts |
| Outbound SFTP | Productize transmit + Filament form + unlock select/save/transmit/resolver; reuse inbound Flysystem factory patterns |
| MDN codes | Catalog codes are `MISSING_MDN`, `LATE_MDN`, `PARTNER_REJECTED_FILE` (not `PARTNER_REJECTED`) |
| POET | **Apply-form only** on existing supplier quarantine case page; **no** inbound email-reply parser; **no** multienterprise workspace |
| Drop-ship / T2 | Emit/consume `dropShipment` on outbound EPCIS when ship is marked drop-ship; portal + email-on-ship remain dispenser TI path; **no** multi-party network choreography / Delivery UI |
| Named PMS | Keep single `POST /api/v1/dispense-check`; add vendor runbooks for top marketing vendors; **no** `/api/v1/pms/{vendor}/dispense` product routes |
| Compliance APIs | **Out of Wave 1** — do not invent `GET /api/v1/compliance/*` |
| Docs | Flip pilot language to GA for each landed slice; keep deferred items explicitly deferred |

---

## Non-goals

- Inbound email → ticket status from mailbox Message-ID correlation
- HDA POET-style shared second case system
- TraceLink-style manufacturer→wholesaler→dispenser T2 custody routing
- Certified per-vendor PMS adapters or vendor-specific API paths
- Dual-stack ship authoring / Xml20Writer (Wave 4)
- Sanctum compliance scorecard APIs (Wave 4)
- Scan UX changes

---

## Slice 1 — Outbound SFTP productize

### Behavior

1. `SftpOutboundSender::send` uploads `$content` to the connection’s outbound path/filename via Flysystem SFTP (no hard throw).
2. Credential mapping mirrors inbound: host, port, username, password and/or private key from `OutboundConnection` settings JSON (exact keys aligned with inbound SFTP connection fields where possible).
3. `OutboundTransportAvailability::isSelectable(Sftp)` → true; `assertSavable` / `assertTransmittable` allow SFTP; `OutboundConnectionResolver` includes SFTP when active.
4. Filament `OutboundConnectionForm` gains an SFTP section (visible when transport = SFTP): host, port, username, password, private key (optional), remote outbound path / directory.
5. Integration Health retires “legacy SFTP unavailable” messaging for productized connections; deactivate action remains for operators cleaning bad rows.
6. Docs: `docs/integrations/outbound-transports.md` — outbound SFTP = Production.

### Failure modes

- Auth / network / put failure → transmitter marks document/transmission failed with actionable message (same pattern as HTTPS/AS2 failures).
- Missing host/path/credentials on save → validation error on form / model.

### Tests

- Unit: sender puts file (Flysystem fake or mocked filesystem).
- Feature: create SFTP outbound connection, transmit succeeds path; Integration Health no longer treats active SFTP as unavailable stub.
- Update `OutboundTransportAvailabilityTest`, `SftpOutboundSenderTest`, `OutboundConnectionIntegrationTest`.

### Key files

- `app/Services/Epcis/Outbound/SftpOutboundSender.php`
- `app/Support/SftpConnectionProviderFactory.php` (or outbound sibling)
- `app/Support/Integrations/OutboundTransportAvailability.php`
- `app/Services/Epcis/OutboundConnectionResolver.php`
- `app/Filament/App/Resources/OutboundConnections/Schemas/OutboundConnectionForm.php`
- `app/Filament/App/Pages/IntegrationHealth.php` + blade
- `docs/integrations/outbound-transports.md`

---

## Slice 2 — MDN catalog emitters

### Behavior

1. **Partner rejected:** On sync AS2 MDN failure path in `ConnectionOutboundEpcisTransmitter` and on async `ProcessAs2AsyncMdn` failed disposition → call `RecordOperationalEpcisCatalogSignal::partnerRejected(...)` (idempotent / de-dupe if already recorded for same transmission).
2. **Missing / late MDN:** Scheduled Artisan command scans `transmission_mdns` with `mdn_status = pending` (and related outbound doc still awaiting ACK):
   - past **missing** SLA → `missingMdn`
   - past **late** SLA (wider window or separate threshold) → `lateMdn`
3. Config: `config/tracepharma.php` (or sibling) keys for missing/late hours with env overrides; document defaults (e.g. missing 24h, late 72h — tune to existing AS2 ack expectations if present).
4. Remove the three codes from `ExceptionCorrectionProfile::operatorHiddenStubCodes()` once emitters are live.
5. Docs: catalog-honesty note in outbound-transports — codes are operator-visible.

### Failure modes

- Emitter must not create duplicate open exceptions for the same code + document/transmission on every schedule tick (use existing catalog-signal / unique patterns).
- Command is safe no-op when no pending MDNs.

### Tests

- Unit/feature: async MDN failed → `PARTNER_REJECTED_FILE` recorded.
- Feature: pending MDN aged past SLA → `MISSING_MDN` / `LATE_MDN`.
- Update `ExceptionCorrectionProfileStubCodesTest` — codes no longer hidden.

### Key files

- `app/Actions/Epcis/RecordOperationalEpcisCatalogSignal.php` (reuse)
- `app/Actions/Integrations/ProcessAs2AsyncMdn.php`
- `app/Services/Epcis/ConnectionOutboundEpcisTransmitter.php`
- New command under `app/Console/Commands/`
- `routes/console.php`
- `app/Support/Exceptions/ExceptionCorrectionProfile.php`
- `config/tracepharma.php`
- `docs/integrations/outbound-transports.md`

---

## Slice 3 — Partner apply-form (POET-lite)

### Behavior

1. On `supplier-quarantine/show`, add a structured **Apply correction** form (alongside or above free-text comment):
   - Acknowledgement (required checkbox or select)
   - Corrected shipment / document reference (optional string)
   - Corrected GTIN / serial / lot / expiry fields as optional strings (PDG-shaped; store in activity `meta`, do not invent new tables)
   - Notes (optional text)
2. Submit → create partner-visible `exception_activities` row (`kind` comment or dedicated kind if one already fits; prefer existing enums — extend only if necessary) with `meta` JSON of the structured fields.
3. If case status is `WaitingPartner`, transition via `ExceptionService::transition(..., Investigating, ...)` with system/null actor so buyer queue lights up.
4. Partners still **cannot** resolve/close; buyer remains authority.
5. Filament `ViewException` already shows partner activities — ensure structured meta is readable (simple formatted block or JSON summary).
6. Docs: `partner-exception-collaboration.md` — apply-form GA; email-reply still deferred.

### Failure modes

- Invalid share / closed case → existing portal access asserts.
- Transition only when `WaitingPartner`; other statuses still accept apply-form activity without illegal transitions.

### Tests

- Feature: signed partner submit apply-form → activity + status Investigating when was WaitingPartner.
- Feature: non-WaitingPartner submit → activity only, status unchanged.
- No scan-page tests/changes.

### Key files

- `app/Http/Controllers/SupplierQuarantineController.php`
- `resources/views/supplier-quarantine/show.blade.php`
- `routes/tenant.php` (new POST if needed)
- `app/Services/Exceptions/ExceptionService.php` (reuse)
- `docs/integrations/partner-exception-collaboration.md`

---

## Slice 4 — Drop-ship indicator (honest T2)

### Behavior

1. Add an operator-facing **drop-ship** flag on outbound ship authoring (Ship Order / outbound shipping session — **not** a scan layout redesign: checkbox/toggle on existing form or session fields only).
2. When flag set, outbound EPCIS generation includes GS1 `dropShipment` indicator consistent with inbound validation rule (`EpcisCatalogBusinessRules::checkDropShipmentIndicator`).
3. Dispenser TI delivery remains customer portal + email-on-ship (no new network hops).
4. Docs: `drop-ship-t2.md` — indicator + portal path GA; multi-party TraceLink-style T2 still deferred.

### Failure modes

- Flag default off; existing ships unchanged.
- If writer cannot place indicator, fail closed with clear exception rather than silent omit when flag is on.

### Tests

- Unit/feature: flagged ship produces EPCIS containing dropShipment (or equivalent catalog-satisfied marker).
- Unflagged ship does not require the indicator.

### Key files

- Outbound shipping session model/form + `GenerateShippingEpcisEvents` (or current ship XML builder)
- `app/Support/Epcis/Validation/EpcisCatalogBusinessRules.php` (reference only)
- `docs/integrations/drop-ship-t2.md`
- `docs/integrations/outbound-transports.md` (cross-link)

---

## Slice 5 — Named PMS runbooks

### Behavior

1. Product API remains **only** `POST /api/v1/dispense-check`.
2. Add runbook docs under `docs/integrations/pms/` (or sections in `pms.md` + `multi-pms-adapters.md`) for top vendors from `MarketingPlatformIntegrations::pmsVendors()` (at least PioneerRx, BestRx, PrimeRx; optionally Liberty/Rx30, QS/1).
3. Each runbook: auth (Sanctum token + ability), request/response shape, mapping notes from vendor webhook → unified payload, cutover checklist link to in-app PMS checklist page.
4. `PmsIntegrationChecklist` / page links to runbooks.
5. Marketing pages already honest about no per-vendor routes — keep that; optionally deep-link runbooks from marketing vendor pages.

### Failure modes

- Do not add fake `/api/v1/pms/{vendor}` routes “for marketing symmetry.”

### Tests

- Lightweight: checklist or docs route smoke if applicable; no need for per-vendor HTTP adapters.
- Existing `DispenseCheckApiTest` remains the API contract.

### Key files

- `docs/integrations/pms.md`, `docs/integrations/multi-pms-adapters.md`, new runbook files
- `app/Support/PmsIntegrationChecklist.php` / Filament checklist page
- `app/Support/Marketing/MarketingPlatformIntegrations.php` (optional link only)

---

## Implementation order

1. Outbound SFTP  
2. MDN emitters  
3. Partner apply-form  
4. Drop-ship indicator  
5. PMS runbooks  
6. CHANGELOG + roadmap-status Wave 1 notes  

---

## Success criteria

| Slice | Done when |
|---|---|
| SFTP | Operator can create SFTP outbound connection, transmit file succeeds in tests, docs say Production |
| MDN | Reject + schedule produce catalog exceptions; codes visible in Filament filters |
| Apply-form | Partner structured submit moves WaitingPartner → Investigating with activity meta |
| Drop-ship | Flagged ship EPCIS includes dropShipment; docs honest about deferred network T2 |
| PMS | ≥3 vendor runbooks exist; checklist links them; still one API |

---

## Spec self-review

- [x] No placeholder TBD for locked decisions  
- [x] Non-goals explicit (email-reply, network T2, vendor API routes)  
- [x] Catalog code name corrected to `PARTNER_REJECTED_FILE`  
- [x] No scan redesign; no compliance Sanctum invention  
- [x] Scope matches user choice “2” (all five GA slices)  

---

## Next step

User reviews this file. On confirmation, invoke **writing-plans** to produce a bite-sized implementation plan, then implement with Laravel TDD per slice.
