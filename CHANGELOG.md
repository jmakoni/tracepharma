# Changelog

All notable releases of TracePharma are documented here.

## Unreleased

## [1.2.0] — 2026-08-28

Wave 1 — Mid-market deal blockers (honest GA for SFTP, MDN signals, POET-lite apply-form, drop-ship flag, PMS runbooks).

### Added

- Outbound SFTP transmit (Flysystem) + Filament SFTP connection form; SFTP selectable for create/route/transmit
- AS2 MDN catalog emitters: `PARTNER_REJECTED_FILE` on sync/async reject; scheduled `epcis:emit-pending-mdn-signals` for `MISSING_MDN` / `LATE_MDN`; codes operator-visible
- Partner exception **apply-form** on supplier quarantine portal (WaitingPartner → Investigating); email-reply parser still deferred
- Ship Order **drop-shipment** flag emits GS1 `dropShipment` on outbound EPCIS; TraceLink-style T2 network still deferred
- Named PMS vendor runbooks (`docs/integrations/pms/*`) targeting unified `POST /api/v1/dispense-check`

### Changed

- Integration Health no longer treats outbound SFTP as legacy/unavailable

## [1.1.0] — 2026-08-28

Wave 0 — Buying group control-plane unlock and marketing honesty.

### Added

- Product docs for profile navigation and buying-group network control-plane scope (`docs/product/`)
- Profile navigation matrix unit coverage for Buying Group ATP readiness + Alert center without floor ops

### Changed

- Buying group control-plane unlock: Partner ATP readiness + Compliance Alert Center without floor/master/inbound
- Marketing softened buying-group / 3PL / WMS overclaims where still present
- Roadmap no longer marks buying groups as do-not-port

## [1.0.0] — 2026-08-27

First GA snapshot of the multi-tenant US DSCSA / EPCIS L4 platform (pharmacy + wholesaler ICP).

### Added

- Compliance Alert Center with remediation links, Expired/Expiring/Missing ATP alerts, partner ATP snapshot, and digest command
- ATP partner readiness, evaluation-jurisdiction math, and license-country support
- Inspection Day readiness, Partner Onboarding Kit, PMS integration checklist, Wholesaler/WMS integration pack
- Recall closure dashboard and saleable-return scorecard packaging
- GS1-shaped EPCIS Capture, SimpleEventQuery (Phase-1), and HTTPS subscriptions (document-event delivery)
- EPCIS 2.0 JSON-LD ingest/disposition path (opt-in `accept_20`); XML 1.2 remains the ship spine
- Supplier exception aging notify, PDG-structured notify payload, customer portal ship email
- Support Engineer role assignment controls and tenant user account-created mail
- Pharmacy simplified nav and outbound ship readiness helpers

### Changed

- Ship Orders always author EPCIS **1.2 XML**; connection document version applies to disposition/resolver paths only
- L3 marketing and org settings aligned to commissioning forward (`ForwardCommissioningToL3`), not a public allocation API
- `L3_TRANSMISSION_FAILURE` is operator-visible; MDN partner-reject stubs remain hidden until emitters exist
- Outbound default EPCIS version pinned to 1.2

### Known limitations

Documented for the 1.0.0 GA snapshot (later 1.1.0–1.4.0 releases close several of these):

- Buying group was a **control-plane shell** at GA (1.1.0 unlocks readiness + alert center; 1.4.0 adds member roster). Floor/master/inbound stay off for Buying Group.
- Dual-stack **ship** authoring (JSON-LD 2.0) is not productized; `Xml20Writer` is a retag stub and is not selected
- No Gateway Checker–class TraceReady conformance export; no live OCI/NABP Pulse ATP API (1.3.0 adds internal evidence exports + manual Pulse/OCI attestation sources)
- Certified per-vendor PMS HTTP adapters are not shipped (1.2.0 adds runbooks on unified dispense-check)
- 3PL multi-principal **custody isolation** is not shipped (1.4.0 adds principal registry + soft tags only)
- Full email-reply / POET multienterprise workspace and TraceLink-style multi-party T2 network remain deferred
- AS2 inbound webhook exists but is not operator-selectable on the Inbound Connections form
- Sanctum `GET /api/v1/compliance/*` scorecard routes are not GA — use in-app scorecards
- Outbound SFTP and AS2 MDN catalog emitters ship in 1.2.0 (not in 1.0.0)

[1.2.0]: https://github.com/jmakoni/tracepharma/releases/tag/v1.2.0
[1.1.0]: https://github.com/jmakoni/tracepharma/releases/tag/v1.1.0
[1.0.0]: https://github.com/jmakoni/tracepharma/releases/tag/v1.0.0
