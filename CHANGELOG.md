# Changelog

All notable releases of TracePharma are documented here.

## Unreleased

### Changed

- **Wave E GTM honesty (3PL custody)** — Marketing and compare copy: soft principal registry/filters GA (including scorecards); optional EPC custody enforcement as tenant ops setting (**default off**); do not claim serial isolation as the default product promise; not positioned as LSPedia Edge / ATP DB. In-app `PrincipalsHonesty` shows an enforced sentence when custody is on.
- **Organization Settings** — Logistics 3PL tenants can toggle **Enforce principal custody (EPC isolation)** (`features.principal_custody_enforced`; default off).

## [1.6.0] — 2026-09-07

ATTP-grade connections and tenant authorization: outbound network profiles, a central connection approval queue, hub GLN route directory, TracePharma-owned platform edges (AS2/SFTP), credential and connection-health lifecycle, go-live evidence, ATP depth, tenant lifecycle audit, an ops command center catalogue, and an admin menu manager.

### Added

- **F-1.6.1** — **Outbound network profiles**: central `OutboundNetworkProfile` per serialization network (UniTrace/Systech/SAP ICH/TraceLink reusable values), Admin resource + seeder (`tracepharma:seed-outbound-network-profiles`), tenant outbound connections link to a profile with optional endpoint override, `OutboundSendPreset` derives allowed/default transports, Email/Portal never offered as network transports
- **F-1.6.2** — **Connection approval queue**: tenant-created inbound/outbound connections stay Pending until a platform admin approves; central `ConnectionApprovalRequest` mirror, Admin ConnectionRequests resource, `connections:review`/`connections:list` commands, tenant + admin notifications, resubmit flow, existing connections backfilled as Approved (`tracepharma:backfill-connection-approval-requests`)
- **F-1.6.3** — **Hub GLN route directory**: `hub:routes`/`hub:register-route`/`hub:unregister-route`/`hub:providers`/`hub:enable-provider`/`hub:disable-provider` commands, `HubRouteConflictGuard`, claimable receiver-GLN directory, Admin HubRoutes resource with dry-run route test, `claimed_via` provenance on routes
- **F-1.6.4** — **Platform edges (TracePharma-owned)**: platform AS2 station (certs, AS2 id, sender registry) with hub-inbound webhook + MDN, platform SFTP drop with per-environment poller (`epcis:poll-platform-sftp`), hub token rotation with 24h grace overlap (`hub:rotate-token`), `PlatformOutboundEgress` routing tenant sends over platform edges, Admin **Platform Connections** page with category tabs (Inbound hub / Outbound networks / AS2 station / SFTP drop) x Demo-Stage-Prod sub-tabs
- **F-1.6.5** — **Credential lifecycle**: `credentials_expire_at` on connections, AS2 certificate expiry parsing, `connections:credential-expiry-report` alerts to tenant owners + platform support, `connections:rotate-token` (inbound regenerate / outbound partner-issued), UI expiry badges
- **F-1.6.6** — **Connection health**: `last_success_at`/`last_failure_at`/`consecutive_failures` rollups, `connections:health-sweep` failure-streak alerts with optional auto-pause, suspend/resume commands + UI with audited reasons, suspension enforced on send/receive paths
- **F-1.6.7** — **Go-live checklists + evidence**: per-connection `ConnectionGoLiveChecklist`, `GoLiveChecklistEvaluator`, Markdown evidence pack (`connections:go-live-report`), hypercare sign-off trail
- **F-1.6.8** — **ATP depth**: partner-level license roll-up, license document attachments, signed self-service partner license-update links (`PartnerLicenseUpdateController` + mail), inbound ATP soft-warning escalation, OCI verifiable-credential evidence seam
- **F-1.6.9** — **Tenant lifecycle + platform audit**: `tenant:suspend` (audited reason, cascades to pair sibling) / `tenant:activate` / `tenant:entitle` commands, suspend/activate UI, central `PlatformAuditEvent` log of admin actions on tenants and platform settings, entitlement change history
- **F-1.6.10** — **Ops command center catalogue**: all ~101 app Artisan commands registered in Command Center, grouped (Connections, Hub, Tenants, EPCIS, Compliance, FDA Catalog, Labels & SSCC, Search, Demo & Seeding, Maintenance) with typed variables, flags, confirmations, and queue+timeout for long runs
- **F-1.6.11** — **Admin menu manager**: `notebrainslab/filament-menu-manager` on the admin panel (Settings → Menus) with primary/sidebar/footer locations, drag-and-drop ordering, auto-save
- **F-1.6.12** — **Role-based menu kill switch**: `TRACEPHARMA_ROLE_BASED_MENUS` (default off) opens all admin/tenant navigation regardless of roles until role matrices are seeded; tenant `JobRoleAccess` and admin `Gate::before` honor the flag; tests force it on
- **F-1.6.13** — **Partial-shipment quantity gate**: `split_declared` flag + audited `quantity_gate_overridden` on outbound shipping sessions, shared `OpenShipOrderQuantityCase` exception cases, live-ladder enforcement unchanged for non-split sends

### Fixed

- Orphan-SSCC exception auto-resolves when a later aggregation event makes the SSCC a parent (break-and-pack flow)
- Outbound EPCIS summary counts children/grandchildren (includes `sscc_commissioning` documents in the open-tree summary)
- Outbound EPCIS product rows fall back to tenant catalog + FDA data for name/NDC/manufacturer/dosage/strength when the document lacks vocabulary (GTINs collapse into one NDC group)
- EPC custody gate no longer reports confirmed-received EPCs as not-in-custody
- `filament:upgrade` / `filament:assets` no longer crashes on raw-HTML assets with no file path (zebra print, scan sounds, TracePharma/daisy styles moved to `HEAD_END` render hooks)

## [1.5.1] — 2026-09-02

FDA identifier expansion and registry list search.

### Added

- **F-1.5.10** — **Chemical Reg** (`chemical_reg_number`) on FDA establishments & WDD facilities and tenant sites/trading partners; forms/infolists/tables, create-time freeze, FdaPrefill, activity log
- **F-1.5.11** — **DUNS** column/UI length **9 → 14** on orgs, establishments, WDD, match reviews, and tenant sites/partners; `normalizeDuns` rejects >14 digits
- **F-1.5.12** — Admin FDA **street-address search** on Organizations, Establishments, WDD, 3PL staging, licenses (`facility.street_address`), org relation managers; global search attrs

### Fixed

- Match-review inserts no longer crash on 14-char DUNS (`fda_organization_match_reviews.duns_number` widened)
- Tenant Chemical Reg migrate no longer adds the column without DUNS/DEA/HIN; repair migration heals skewed tenants
- WDD create freeze proven against `fillFromFda` overwrite for Chemical Reg / DUNS / DEA / HIN

## [1.5.0] — 2026-09-02

GTM follow-on cut: async exports, manufacturer verification-request portal, DSCSA shipping extensions, FDA DEA/HIN facility create, ship wizard UX, impersonation hardening, and audit-driven reliability fixes.

### Added

- **F-1.5.1** — Async **Track & Trace / data exports** (tenant `data_exports`, queue jobs, API + signed download, Filament queue, stale/purge commands, site-scoped caps)
- **F-1.5.2** — Client portal **shipments export** and portal track/trace PDF export paths
- **F-1.5.3** — Manufacturer **verification-request portal** (cases/responses, secure unlock, mail notifications, routes/middleware)
- **F-1.5.4** — EPCIS **DSCSA shipping / direct-purchase extensions** (parser, promote on ingest, document columns, compliance/transaction report statements)
- **F-1.5.5** — FDA **DEA/HIN/DUNS** on establishments & WDD + tenant sites/partners; Admin **Create** for Establishments/WDD (`CatalogManage`); create-time freeze + address-fingerprint uniqueness/US country defaults
- **F-1.5.6** — Outbound **ship wizard / scan-out** UX (wizard steps + session concerns)
- **F-1.5.7** — Tenant user **impersonation `public_id`** (opaque URL id + server-side token store)
- **F-1.5.8** — GTM hardening from bug audits (`TenantRunner` on jobs/webhooks, OIDC id_token/nonce binding, export FK `restrictOnDelete`, optional Filament plugins, PHPStan baseline, related security/reliability fixes)
- **F-1.5.9** — Manufacturer **Guardian L3 lot-close ingest** — HTTPS webhook archives DataFeed XML, auto-projects commissioning + aggregation into EPCIS/`epcs`, Serialization Lots UX, Asset Tracking Fields tab (toggle: Org Settings → Accept Guardian lot-close inbound)

### Fixed

- Guardian lot-close: project as self-authored **outbound** EPCIS and fail closed unless document status is `validated` (was marking feed/lot accepted despite `MISSING_DSCSA_STATEMENT`)
- Guardian lot-close: sha256 receive lock, deterministic event IDs, Domain hard gates, Manufacturer/Systech/kill-switch gates, stale-processing redispatch, CaseQty/URI/Bundle/DOCTYPE guards
- Guardian lot-close (residual): failed-feed resubmit actually reprocesses (`failed` → `processing`, not terminal skip); accepted lots are not overwritten by a later failed re-ingest; job re-checks Manufacturer/L3/Systech/kill-switch at run time; missing/null container `Type` fails closed
- Outbound EPCIS builder: correlation header emits SBDH-first `EPCISHeader` (GS1 EPCIS 1.2 XSD-valid); Guardian lot-close restores document-level correlation
- Webhooks rate limiter scoped by host+IP (was a single global bucket)
- FDA Create: WDD duplicate address fingerprint returns form validation instead of SQL unique crash; admin-entered DEA/HIN frozen on create so later `fillFromFda` cannot overwrite
- Refuse SGLN derivation / authoring when GLN fails GS1 check digit (Round 3)

## [1.4.0] — 2026-08-28

Wave 3 — Role expansion MVPs (buying-group roster, 3PL principals, L3 forward log, prepack transform) plus subscription delivery hardening.

### Added

- Buying group **Member roster** (link/status only; health/matrix/APIs deferred)
- 3PL **Principal** registry + soft site/ship tag/filter (not EPC custody isolation)
- Manufacturer **L3 forward log** with retry (not allocation / Guardian / reconcile)
- Prepackager **Repack transform** (TransformationEvent) + Asset Trace transformation edges

### Changed

- Marketing softened remaining principal-scoped / member-network overclaims where still present

### Fixed

- Outbound EPCIS subscriptions only dispatch on `sent` (not `validated`) so hooks do not fire before transmit
- Subscription delivery and Test ping pin DNS via `CURLOPT_RESOLVE` after SSRF allowlisting (closes DNS-rebinding TOCTOU)

## [1.3.0] — 2026-08-28

Wave 2 — Trust / certification evidence (honest internal packs; not TraceReady / Pulse-listed / Gateway Certified).

### Added

- Internal EPCIS scenario evidence export (`epcis:export-scenario-evidence`) — not TraceReady / Gateway Checker certified
- VRS Verify readiness checklist + `vrs:export-readiness-log` — not Gateway Certified
- Manual ATP verification sources for partner-supplied Pulse / OCI evidence (no Pulse API; not Pulse-listed)
- Partner ingest quality rollup page (7d/30d inbound exception counts) — not clean-data certified

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

[1.5.0]: https://github.com/jmakoni/tracepharma/releases/tag/v1.5.0
[1.4.0]: https://github.com/jmakoni/tracepharma/releases/tag/v1.4.0
[1.3.0]: https://github.com/jmakoni/tracepharma/releases/tag/v1.3.0
[1.2.0]: https://github.com/jmakoni/tracepharma/releases/tag/v1.2.0
[1.1.0]: https://github.com/jmakoni/tracepharma/releases/tag/v1.1.0
[1.0.0]: https://github.com/jmakoni/tracepharma/releases/tag/v1.0.0
