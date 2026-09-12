# Changelog

All notable releases of TracePharma are documented here.

## Unreleased

### Added

- **P0-2 disposition destroy gate** — `TenantFeatures::supportsDispositionDecommission()` (Pharmacy, Manufacturer, DrugWholesaler, Prepackager, Logistics3pl, Dental). `DecommissionWorkstation` / Operations Hub Destroy card use it; Commission-all stays `supportsCommissioning()` (M+R only).
- **P1-1 Serialization Lots / L3 forward log** — Gate on `supportsCommissioning()` (Manufacturer **and** Prepackager), not raw `profile === Manufacturer`. Wholesaler/Pharmacy remain denied.
- **P1-2 Prepackager job-role catalog** — `TenantRole::forProfile(Prepackager)` unions Manufacturer commission/plant personas with DrugWholesaler receive/ship floor (`ReceivingTechnician`, `OutboundPickAndPackLead`, `InboundExceptionCoordinator`). `TenantFeatures` unchanged; re-seed via `tracepharma:seed-tenant-job-roles` for existing Prepackager tenants.
- **P0-1 Manufacturer inbound EPCIS + NavReceive** — Plant personas (`PackagingLineOperator`, `SerializationSystemsEngineer`, `CmoIntegrationManager`) gain `NavReceive` for Inbound EPCIS under job roles. (Receive / Scan In for Manufacturer added later via `supportsReceiving()` for CMO/partner inbound.)
- **P1-5 Manufacturer intracompany transfer** — `supportsTransferring()` true for Manufacturer (same `TransferringSessionResource`). Buying Group still denied.
- **Manufacturer CMO inbound receive** — `supportsReceiving()` true for Manufacturer (same `ReceivingSessionResource` + Scan In + Receiving Issues). VRS requestor (`supportsVrs`) and Pharmacy outbound desk stay off. Ops Hub “Verify product” directory now gates on `supportsVrs()`. No `TenantProfile::Cmo`; CMO remains a trading partner. Transfer not used for partner ASN receipts.
- **CMO auto-receive (dual gate)** — Tenant master `receiving.auto_receive_from_cmo` (Manufacturer Org Settings, default off) **and** partner `is_cmo` + `auto_receive_inbound`. When both on, validated inbound shipping ASN auto-completes receive (`source=auto_cmo_receive`). Guardian/L3 commissioning ingest excluded. Manual Receive/Scan In unchanged when either gate is off.
- **CMO own-product inbound TS soft-gate** — Partner `cmo_ownership` (`own_product` | `cmo_sells`, default sells). When master auto-receive-from-CMO is on and partner is CMO own-product, inbound missing TS / biz-transaction is warning/soft (not hard-block); auto-receive can complete without `dscsa_affirm`. `cmo_sells` and non-CMO inbound keep hard TS.
- **P0-1 VRS responder vs requestor** — `TenantFeatures::supportsVrsResponder()` (Manufacturer **or** `supportsVrs()`). Inbound VRS webhook + verification-request profile check use responder; Verify Product / history / directory stay on `supportsVrs()` only (Manufacturer requestor UI remains off). Organization Settings portal toggle save/visibility uses `supportsVrsResponder()` so Manufacturer can persist the setting.
- **Marketing GTM alignment** — Sell shipped depth: manufacturer verification portal (settings-gated ≠ VRS alone), 3PL WMS principal map / agent TI / Logistics3pl roles, buying-group home/demo parity, hub connectivity (connection approval, hub GLN routes, platform AS2/SFTP). Provider checklist covers BG control plane and not-GA fences.
- **Wave F6 (partial)** — BuyingGroup personas (`BuyingGroupNetworkAdmin`, `BuyingGroupAnalyst`) with `NavCompliance` / roster `UsersManage`; KB + marketing GTM freeze: member health, partner matrix, and member APIs claimed as GA (control-plane only; not ATP warehouse compliance for the group).
- **Wave F5 (partial)** — BuyingGroup tenant program `affiliation_code` (`TenantSettings` / Organization Settings when `supportsBuyingGroupNetwork()`); Member roster enrollment stub (soft vs hard-linked counts, % with roster affiliation code).
- **Wave F4** — Sanctum `/api/v1/buying-group/*` (members, readiness, network summary, partner-matrix) with ability `buying-group:network`; Postman + integrations note.
- **Wave F3** — `tracepharma:buying-group-rollup`, Member network health, Authorized partner matrix, BG network alerts from member snapshots (hard-linked consented pharmacies only).
- **Wave F2** — Hard membership invite/consent (`buying_group_memberships`) between BuyingGroup roster and Pharmacy tenants.
- **Wave F1** — Member roster identity enrichment + CSV export.

### Changed

- **Inspection-day competitive demo + Pulse-honest helpers** — Inspection day framed as the FDA walk-in demo path (not a live Pulse feed). One-sentence is/is-not helpers on Alert center, FDA 3911, Quarantine, Tracing, and ATP readiness (manual Pulse/OCI evidence vs no live directory API). Prepackager ATP title: **Repackager ATP diligence.** Admin Pharmacy profile helper: cannot commission.
- **3PL viable-honest** — Principal honesty banners: soft *filter only — not isolated.*; enforced *enforced*. Onboarding matches the custody flag. Filament ship view proves cross-principal scan block when `principal_custody_enforced` is on. Default remains off; no Edge schema / no per-principal DB.
- **Manufacturer VRS honesty + optional requestor** — Manufacturer always responds via `supportsVrsResponder()` (webhook/portal). Verify Product / history / directory stay off unless Org Settings **Manufacturer VRS requestor** (`features.manufacturer_vrs_requestor`, default off) enables `supportsVrs()`.
- **POC floor honesty (three gates)** — Transfer `canAccess`/`view` allows `NavShip` **or** `NavReceive`; `canCreate` and ship mutations stay `NavShip`. Pharmacy Org Settings: **Show warehouse tools** = inverse of `pharmacy_simplified_nav` (default unchanged). Opt-in `pharmacy_full_outbound` (default off) unlocks Scan Out + Outbound EPCIS when warehouse tools are on; desk remains default; no Ship Order / SSCC / T2 for pharmacy.
- **Marketing honesty pass** — Interop story is EPCIS over AS2/SFTP/HTTPS + hub GLN routing / connection presets (not native TraceLink or SAP ATTP replacement). ATP copy = licenses + manual Pulse/OCI evidence (not Pulse certified / live directory API). Drop-ship remains GS1 indicator only (T2 network deferred). 3PL isolation only when custody enforcement is on. Receive HUD chips say “receive policy” (not “Edge-style”).

### Fixed

- **Organization Settings Owner-only feature flags** — Non-owners with UsersManage / Master Data can open Org Settings but cannot persist `pharmacy_full_outbound`, `manufacturer_vrs_requestor`, `principal_custody_enforced`, or `auto_receive_from_cmo` (toggles Owner-visible; save ignores non-Owner writes).
- **Transfer authz honesty** — Receive-tech may list/view transfer sessions (`NavShip|NavReceive`); create and ship-side mutations stay `NavShip` only.
- **Outbound EPCIS canView** — Resource overrides use `NavShip` + outbound features so ship-only users are not 403’d by the shared inbound `EpcisDocumentPolicy`.
- **Soft TS impact map** — Supplier quarantine / exception email use `ExceptionReceiveImpactMap::forCodeOnDocument` so CMO own-product soft TS is not escalated as hard-blocking.
- **Integration API key strength** — WMS bridge and VRS responder keys require at least 16 characters on set.
- **Commissioning action gate** — `EmitCommissioningEpcisForEpcs` requires `supportsCommissioning()` (not UI-only).
- **EPCIS DSCSA section visibility** — “Transaction statement affirmed” / “Legal notice” (and list DSCSA icons) show only for partner ownership-change docs (inbound partner files, authored shipping). Hidden for generated transferring/receiving/commissioning and other non–ownership-change authored kinds so `dscsa_affirm=false` no longer looks like a miss. Ship-session TI/TS affirm unchanged.
- **Buying-group invite directory** — Hard-link invite no longer lists all active Pharmacy tenants. Roster maintainers enter a known tenant UUID or primary domain; invite requires a roster row and corroborates optional roster `primary_gln` against the pharmacy tenant GLN.
- **Buying-group member metrics** — Partner-fact cap (200) no longer stops the ATP gap scan; health scores and network totals keep full licence-risk counts while partner snapshots stay bounded.
- **3PL ASN/transfer principal custody** — Inbound ASN and transfer-receive session open now resolve `principal_id` (site default / explicit) like scan-first, so `principal_custody_enforced` no longer blocks ASN scans or throws on complete.
- **3PL scan-first cross-site gate** — Location on-hand checks use `ShippableEpcsAtSite::isOnHandAtSite()` (ignores principal filter) so custody enforcement cannot fail-open double-receive of stock already at another site.
- **3PL floor ops principal** — Pack/unpack/break-pack/decommission/return/commission-all (and backing actions) pass the site default principal into on-hand and custody gates so enforced custody no longer rejects legitimate stamped inventory as “not at site.”
- **Role-based menus fail-closed** — `TRACEPHARMA_ROLE_BASED_MENUS` defaults to **true**; unset/.env.example no longer leave admin `Gate::before` and `JobRoleAccess` fail-open. `false` remains an emergency kill switch. Added `tracepharma:seed-admin-roles` for the admin Spatie matrix.
- **AS2 hub inbound identity binding** — After S/MIME unwrap, verified `AS2-From` must be authorized for the SBDH sender GLN used in hub routing (`PlatformAs2Station` registry `sender_glns`). Prevents a registered AS2 partner from impersonating another trading partner’s GLN on the hub path.
- **Buying-group rollup** — `BuyingGroupMemberRollupJob` re-checks active membership before reading the member tenant DB.
- **G-P2-03 / G-P2-04** — Align `docs/product/profile-navigation.md` and tenant-type-links skill to `TenantFeatures`: commission **M+R only**; `supportsManufacturerVerificationPortal()` ≠ `supportsVrs()`; real inbound/outbound / `canAuthorOutboundShipments` gates (no phantom `supportsOutboundShipping` etc.).
- **Marketing honesty fences** — Align intentionally-not-claimed language across solutions/features/demo/checklist: named PMS adapters not GA; compliance Sanctum scorecard APIs not GA; Pulse not certified; 3PL ≠ Edge/ATP DB / per-principal DB / full T2 network; plant commission M+R only; BG not ATP warehouse; enrollment analytics deferred.
- **Wave F0→F6 GTM (buying groups)** — Marketing/home/features/compare and solutions copy: Member roster, member health, authorized partner matrix, and member APIs are GA with Partner ATP readiness + Alert center; still control-plane (no floor ops); do not claim the buying group is DSCSA-compliant as an ATP. Product doc gates: roster `UsersManage`; health/matrix `NavCompliance`.
- **Wave E GTM honesty (3PL custody)** — Marketing and compare copy: soft principal registry/filters GA (including scorecards); optional EPC custody enforcement as tenant ops setting (**default off**); do not claim serial isolation as the default product promise; not positioned as LSPedia Edge / ATP DB. In-app `PrincipalsHonesty` shows an enforced sentence when custody is on.
- **Organization Settings** — Logistics 3PL tenants can toggle **Enforce principal custody (EPC isolation)** (`features.principal_custody_enforced`; default off).

### Buying-group network waves F0–F5 (summary)

| Wave | Outcome |
|---|---|
| **F0** | Honesty freeze: claim only shipped control-plane surfaces |
| **F1** | Roster enrichment + CSV |
| **F2** | Hard membership invite / pharmacy consent |
| **F3** | Daily rollup + member health + partner matrix + BG alerts |
| **F4** | Sanctum member/network APIs (no EPC payloads) |
| **F5** | Program affiliation code + enrollment stub |
| **F6** | Personas + KB + GTM freeze promoting F3–F5 as GA |

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
- **F-1.6.12** — **Role-based menu kill switch**: `TRACEPHARMA_ROLE_BASED_MENUS` (default **on**, fail-closed; `false` = emergency open-all) gates admin abilities and tenant `JobRoleAccess`; tests force it on via phpunit.xml; seed with `tracepharma:seed-admin-roles`
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
