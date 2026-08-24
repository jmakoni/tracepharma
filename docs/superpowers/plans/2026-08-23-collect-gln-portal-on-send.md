# Collect dest GLN/SGLN + portal on send — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans (tightly coupled TDD slice). Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Refuse outbound send until the customer’s dest SGLN is on record, collect that identity on Pharmacy Outbound Desk, and issue the existing customer portal link after a successful author.

**Architecture:** Extract dest-SGLN candidate/resolve into `ResolveOutboundShipToSgln` so `ValidateOutboundShippingSend` and `GenerateShippingEpcisEvents` stay in sync. Desk paste writes **site** GLN/SGLN only. `CompleteOutboundShippingSession` issues the portal uuid after author; portal failure must not fail the send.

**Tech Stack:** Laravel 13, Pest/PHPUnit, Filament 5, MariaDB tenant DB (Demo2).

## Global Constraints

- Collect-only. Never invent a 6/6 SGLN or assign a GLN from the tenant prefix.
- Frozen pages stay observe-only: Ship Order HUD/Blade, Trading Partners form, Outbound EPCIS, Scan Out, Dashboard. Ship Order change is the send **action** notification only.
- Tests first. Pint only files you touch. No `pint --dirty`. Do not commit unless asked.
- Demo2 isolation: tenant `13fe9068-cb05-4bab-9e0e-a89f2a458832`. Use unique SSCCs when ingesting.
- Do not test encoding SGLN from a 13-digit GLN.

---

## File map

| File | Responsibility |
|---|---|
| `app/Support/Shipping/ResolveOutboundShipToSgln.php` | Dest GLN + candidate URNs + `SglnResolution` (same list as today’s author) |
| `app/Support/Shipping/OutboundPortalPickupNotice.php` | Non-fatal signed portal URL for send notifications |
| `app/Actions/Shipping/RecordOutboundDestIdentity.php` | Desk: ensure dest site, persist pasted GLN/SGLN onto that site |
| `app/Actions/Shipping/ValidateOutboundShippingSend.php` | Dest-SGLN blocker |
| `app/Actions/Shipping/GenerateShippingEpcisEvents.php` | Use the shared resolver for dest |
| `app/Actions/Shipping/CompleteOutboundShippingSession.php` | After author, `ensureCustomerPortalLink` (non-fatal) |
| `app/Filament/App/Pages/PharmacyOutboundDesk.php` + blade | Dest site picker + paste fields |
| Send actions (desk + Ship Order view) | Success notification body includes signed portal URL |
| `tests/Feature/Shipping/CollectGlnPortalOnSendTest.php` | CG-1…CG-6 |

---

### Task 1: Dest-SGLN blocker (CG-1, CG-2)

**Files:**
- Create: `app/Support/Shipping/ResolveOutboundShipToSgln.php`
- Modify: `ValidateOutboundShippingSend.php`, `GenerateShippingEpcisEvents.php`
- Test: `tests/Feature/Shipping/CollectGlnPortalOnSendTest.php`
- Also update `send_refuses_to_invent_an_sgln_for_a_customer_that_has_none` to assert the validate copy (`not ours to guess`) because Complete now fails before author.

**Interfaces:**
- `ResolveOutboundShipToSgln::destParty(OutboundShippingSession): array{gln: ?string, site_id: ?int}`
- `ResolveOutboundShipToSgln::candidates(OutboundShippingSession): list<string>` — site `sgln`, partner `sgln`, inbound `event_locations`/`event_parties` URNs for that GLN
- `ResolveOutboundShipToSgln::resolve(OutboundShippingSession): ?string` — `SglnResolution::resolve($gln, $candidates, orgPrefix)`

Blocker copy (stable):

> Record the customer’s SGLN on the trading partner or ship-to site for GLN {gln}. A partner’s GS1 company prefix is theirs to state, not ours to guess.

- [x] Write CG-1 / CG-2 (partner GLN, site present, both SGLNs null → blocker; after site SGLN recorded → no dest-SGLN blocker). Session stays open on CG-1.
- [x] Run tests — expect FAIL (blocker missing).
- [x] Implement helper + validator + wire Generate dest path through the helper.
- [x] Run CG-1/CG-2 + `send_uses_inbound_event_party_sgln_when_customer_site_sgln_is_blank` (CG-3) — expect PASS.

---

### Task 2: Portal on send (CG-5, CG-6)

**Files:**
- Create: `app/Support/Shipping/OutboundPortalPickupNotice.php`
- Modify: `CompleteOutboundShippingSession.php`, Ship Order `sendShipment` action, Pharmacy desk `sendShipment` action
- Test: same CollectGln file

After successful `GenerateShippingEpcisEvents`, if the customer is **active**, call `CustomerPortalService::ensureCustomerPortalLink`. Catch/log failures; still return the completed session. Inactive customer: skip portal, send still succeeds.

Callers append `OutboundPortalPickupNotice::signedUrl($session)` to the success notification body. Do not change `shipCompleteCopy()` (HUD).

- [x] Write CG-5 (inactive partner after party save → completed, uuid not required) and CG-6 (active → signed portal index lists the new shipping doc).
- [x] Run — expect FAIL (uuid missing / portal empty).
- [x] Implement Complete + notice + action notifications.
- [x] Run CG-5/CG-6 — expect PASS.

---

### Task 3: Pharmacy dest collect (CG-4)

**Files:**
- Create: `app/Actions/Shipping/RecordOutboundDestIdentity.php`
- Modify: `PharmacyOutboundDesk.php`, `pharmacy-outbound-desk.blade.php`

Rules:
- Dest select = active sites of the selected customer.
- Zero sites → create one (`name` = partner name, `is_active` true, `is_organization_facility` false) and select it.
- Many sites → operator must pick; paste never writes a random site.
- One site → use that site (not random).
- Paste GLN+SGLN onto the **selected site** only. `SglnRules::check` must pass. Never derive the URN from 13 digits. Do **not** overwrite partner `gln`/`sgln`.
- Set session `ship_to_site_id` / `ship_to_gln` via `UpdateOutboundShippingParty`.
- Send TI stays `CompleteOutboundShippingSession`.

- [x] Write CG-4: desk paste dest GLN+SGLN, Send TI authors a document, `customer_portal_uuid` set. Unique SSCC. ATP gate off for this case (desk-created site has no license).
- [x] Run — expect FAIL (no dest fields).
- [x] Implement action + desk UI + saveRefs wiring.
- [x] Run CG-4 — expect PASS.

---

### Task 4: Verify

- [x] `php artisan test --filter=CollectGlnPortalOnSendTest`
- [x] `php artisan test --filter=send_uses_inbound_event_party_sgln_when_customer_site_sgln_is_blank`
- [x] `php artisan test --filter=send_refuses_to_invent_an_sgln_for_a_customer_that_has_none`
- [x] `php artisan test --filter=PharmacyOutboundDeskTest`
- [x] `php artisan test --filter=CustomerPortalTest`
- [x] Pint only touched PHP files.
