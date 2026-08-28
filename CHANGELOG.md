# Changelog

All notable releases of TracePharma are documented here.

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

Documented for the next competitive backlog (not fixed in 1.0.0):

- Buying group profile is an empty control-plane shell
- Outbound SFTP transmit is stubbed; drop-ship/T2 and POET-style email-reply workspaces are not shipped
- Dual-stack **ship** authoring (JSON-LD 2.0) is not productized; `Xml20Writer` is a retag stub and is not selected
- No Gateway Checker–class TraceReady conformance export; no OCI/NABP Pulse ATP token integration
- Named per-vendor PMS adapters and 3PL multi-principal custody are not shipped
- AS2 inbound webhook exists but is not operator-selectable on the Inbound Connections form

[1.0.0]: https://github.com/jmakoni/tracepharma/releases/tag/v1.0.0
