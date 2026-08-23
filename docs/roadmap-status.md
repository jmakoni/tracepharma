# TracePharma roadmap status

Living gap summary vs the greenfield build plan and the selective port from the mature sibling ([vatengitracerx.com](/home/jmakoni/projects/vatengitracerx.com)).

- Greenfield history: [TracePharma Greenfield](/home/jmakoni/.cursor/plans/TracePharma%20Greenfield-365df164.plan.md)
- **Next work detail:** [Vatengi feature port phases](/home/jmakoni/.cursor/plans/Vatengi%20feature%20port%20phases-c446060f.plan.md)
- Phase 5 compliance depth: [Phase 5 compliance depth](/home/jmakoni/.cursor/plans/Phase%205%20compliance%20depth-365df164.plan.md)

**Port method:** adapt Vatengi workflows/tests into TracePharma’s existing spine. Do not dual-run Vatengi receiving services or import AI/persona/dashboard sprawl (see Vatengi lean plan).

## Shipped

| Phase | Theme |
|-------|--------|
| 0–1 | Scaffold, tenancy, Admin/App panels, `TenantFeatures`, demo2 |
| 2 | Master data + catalog / ATP / FDA |
| 3 | EPCIS 1.2 ingest + App UI + Horizon jobs |
| 4 (slice) | Receiving end-to-end (open/confirm/complete, authored receiving events, last-seen) |
| 4 (slice) | Scan-first receive (ASN-free / transfer_receive kinds, Receive UI, Ops Hub routing) |
| 4 (slice) | Scan-in → ASN reconcile (confirm/backfill expected ASN lines from scan-first; null-site + completed session handling) |
| 4 (slice) | Transferring (open/confirm/complete, authored ship+receive EPCIS, App Transfer UI) |
| 4 (slice) | Transfer & Receive Active/History tabs (site-scoped lists, CurrentSite filter, cross-links) |
| 4 (slice) | Repair missing receiving EPCIS (`tracepharma:repair-missing-receiving-epcis`) for completed sessions |
| 4/6 (slice) | SSCC labeling / commissioning (PDF, ZPL, pools, commission/agg/disagg) |
| 4 (slice) | Floor receive: mobile `/floor` page, camera, layout cookie, **staged-scan batch confirm** |
| 4 (slice) | Floor transfer + ship mobile `/floor` pages (scan-only; ship send stays desktop) |
| 5 (slice) | Return / Pack / Unpack / Break-pack workstations + returning EPCIS; Unpacked items list |
| 5 (slice) | Exceptions + quarantine workstation, Asset Tracking, Verify Product (VRS shell) |
| 5 (slice) | VRS Phase 4: `supportsVrs`, verification detail, Sanctum dispense-check, responder webhook, Http env docs |
| 5 (slice) | VRS async verify job on receive confirm (staged + desktop) |
| 5 (slice) | Recall broadcast: suggest outbound partners + email + `tracing_request_notifications` tracking |
| 5 (slice) | FDA 3911 CRUD + PDF + exception draft path |
| 5 (slice) | Tracing requests + SLA clocks + respond/evidence UI |
| 5 (slice) | Compliance reports hub (transaction PDF, serialized PDF, TI history CSV, audit ZIP) |
| 5 (slice) | SiteAccess on Exceptions, Quarantine, ComplianceReports document picker |
| 7 (slice) | Inbound integrations + Sanctum token UI |
| 7 (slice) | EPCIS hub (Systech/UniTrace): Admin demo/stage/prod config, tenant env + hub_providers, central URL + hub route registration |
| 7 (slice) | Outbound connections + real transmitter; Ship Order; Outbound EPCIS browser; `ship_proven` onboarding |
| 7 (slice) | EPCIS Jobs Phase 1: queue-backed authored outbound transmit ledger + App management UI (cancel/requeue/archive); flag `TRACEPHARMA_EPCIS_JOBS_ENABLED` |
| 7 (slice) | EPCIS Jobs Phase 2: `inbound_process` ledger on existing `ProcessEpcisDocumentJob` (no second pipeline); requeue = reprocess + new receipt; same flag / `tracepharma.epcis_jobs.enabled` |
| 7 (slice) | Inbound EPCIS catalog gated by `received_via` (Upload / hub / HTTPS webhook / SFTP; CLI excluded) |
| 6 (slice) | Integration health dashboard (24h inbound/outbound EPCIS stats, connection tables, Settings + Ops Hub links) |
| 6 (slice) | Lean AS2 outbound (`As2OutboundSender` — AS2-shaped HTTPS POST with sync MDN capture; lean S/MIME CMS when certs configured) |
| 6 (slice) | AS2 certificate vault on outbound connections (encrypted PEM storage; lean S/MIME sign/encrypt on send) |
| 6 (slice) | AS2 async MDN webhook (`POST /api/webhooks/as2/mdn/{tenantId}/{connectionId}`) |
| 6 (slice) | WMS ship-confirm webhook (`POST /api/webhooks/wms/{tenantId}`, `ProcessWmsShipConfirm`, `complete:false`, Idempotency-Key) |
| 6 (slice) | PMS dispense bridge scorecard on Verify Product + deferred/unavailable buckets + `POST /api/v1/dispense-check` hint |
| 6 (slice) | External L3 commissioning forward (`ForwardCommissioningToL3` job when L3 enabled + endpoint set) |
| 8 (slice) | VRS manufacturer failure notify (`ManufacturerVerificationNotifier` on failed/suspect; `vrs_notify_email` override + FDA catalog fallback) |
| 8 (slice) | Desktop receive camera via shared `scan-field` (immediate confirm) |
| 8 (slice) | Recall partner ack portal (signed link, `ack_share_uuid`) |
| 8 (slice) | Recall ack link rotate/revoke UX (per-notification, activity log) |
| 8 (slice) | Demo receive→ship choreography (`tracepharma:seed-demo-choreography` / setup-demo) |
| 8 (slice) | Demo `--transfer` second-SSCC receive→transfer path |
| 8 (slice) | Recall ack link rotate/revoke on ViewTracingRequest |
| 8 (slice) | Sanctum EPCIS API (`POST /api/v1/epcis/inbound`, `GET /api/v1/epcis/documents`, `POST/GET /api/v1/epcis/outbound`) |
| 8 (slice) | Tenant Scout indexes (Product, TradingPartner, EpcisDocument, EpcisEvent) + `tracepharma:scout-reindex` + Meilisearch filterable attrs |
| 8 (slice) | Production Meilisearch per-tenant rollout (`scout-sync-index-settings`, `scout-reindex-all`, `scout-health`, provision-on-create) |
| 8 (slice) | Bug-hunt hardening: AS2 MDN auth + disposition; per-tenant WMS key + unique idempotency; outbound partner fail-closed + transmit skip-if-sent; portal/ack authz; Scout exit honesty |
| 8 (slice) | Bug-hunt #2: scan-first custody + SiteAccess mutate; inactive pin fail-closed; ApiTokens allowlist; connection policies; IntegrationHealth redact; Scout finally; per-tenant VRS; Wave2 WMS/AS2/queue/quarantine/dispense |
| 8 (slice) | Bug-hunt #3: EPCIS force-fail/overlap locks; pack site on-hand + aggregation hard-fail; exception site_id + changeStatus; receive null-auth; dispense grant; AssetTrace caps; Wave2 SLA/ATP/LabelPrinter/WMS Idempotency/locks |
| 8 (slice) | Bug-hunt #4: completed-session double-ship/receive window; outbound enqueue/transmit fail-closed; SSCC batch SiteAccess; transfer↔ship gate; commission per-EPC locks + L3 idempotency; WMS `complete` replay; portal/MDN edge cases; Wave2 SiteAccess align + unpack/break locks |
| 8 (slice) | Bug-hunt #5 Wave 1: FDA registry view-only for Support (`CatalogManage` edit); hub settings + hub widgets gated; activity log `AdminsManage`; admin widget catalog permission split; App Today Activity VRS hidden for site-restricted |
| 8 (slice) | Bug-hunt #5 Wave 2: pair stage-failure rollback (`DeleteTenantPair`); outbound SFTP fail-closed (create/activate/resolver/transmit); EPCIS JobRoleAccess on reprocess/requeue (receive-only denied); `epcis:fail-stale-jobs` Sending/Processing SLA recovery; rejected onboarding slug/`tenant_id` release |
| 8 (slice) | Tenant management P0: `TenantAccess` + `EnsureTenantIsActive` on App/API/webhooks/SFTP/tenant routes; outbound transmit bail; pair cascade suspend; PlatformAdmin audited impersonation (Support denied); isolation/suspension Pest suite |
| 8 (slice) | Tenant management Phase 1: per-tenant kill switches (`outbound_epcis`, `inbound_epcis`, `sanctum_api`, `wms_webhooks`); async compliance export ZIP + delete warn without recent export |
| 8 (slice) | Bug-hunt #6 Wave 1: impersonation token not logged raw; inbound kill on Sanctum API; bulk delete export ack; kill-switch pair cascade; atomic impersonation consume; suspended App → 403 |
| 8 (slice) | Bug-hunt #6 Wave 2: aggregation reprocess preserves retired links (`valid_to`); VerifyProduct scorecard gated by `SitesAccessAll` |
| 8 (slice) | Bug-hunt #7 Wave 1: Sanctum outbound/documents kill-switch parity; aggregation FK doctor (`tracepharma:doctor-aggregation-link-fk`); bulk delete per-tenant failure summary |
| 8 (slice) | Bug-hunt #7 Wave 2: Verification history + Analytics `vrsRates` SiteAccess (own or site exception); floor mobile receive live.blur + Enter DOM scan |
| 8 (slice) | Bug-hunt #8 Wave 1: floor ship/transfer mobile live.blur + Enter DOM `stageScan`; outbound kill honest ship complete UX (failed/pending transmit, not false sent); AS2 MDN webhook `OUTBOUND_EPCIS` kill gate |
| 8 (slice) | Bug-hunt #8 Wave 2: VerifyProduct today list SiteAccess; Analytics `vrsRates` site-filter actor-owned unlinked; Integration Health legacy SFTP deactivate + badge; aggregation FK doctor daily schedule + alert + Hub drift badge (BH8-8 stretch AS2 inbound skipped) |
| 8 (slice) | Bug-hunt #9 Wave 1: `FINDINGS_TRUNCATED` overflow when per-type cap drops hits; `MISSING_COMMISSIONING` HardBlocking + critical receive impact; same-doc receiving-before-usable-commission gate; CAS/`MISSING_COMMISSIONING` dedupe on same EPC |
| 8 (slice) | Bug-hunt #9 Wave 2: desktop ship/transfer `live.blur` + Enter DOM `stageScan`; Hub aggregation FK doctor never-checked surfacing; `OutboundShippingSessionTest` demo fixtures (56/62; remaining corrective-ship edge cases deferred); `SHIP_BEFORE_COMMISSION` documented as superseded orphan (BH9 stretch AS2 inbound skipped) |
| 8 (slice) | Delete-session hardening: hard-delete guardrails (empty/open/in-progress only; block completed/cancelled/authored EPCIS) + SiteAccess/job-role gates + invoice blob cleanup + transfer-receive mark revert + confirm-phrase UI gate across Receiving/Shipping/Transferring |
| 8 (slice) | Demo `--unpack` / `--pack` / `--return` hierarchy choreography flags |
| 8 (slice) | Supplier portal token UX on Trading Partners (portal badge, copy/rotate/revoke link) |
| 8 (slice) | Lean multi-partner outbound routing (`is_default` per partner on Ship Order) |
| — | Onboarding / Settings hub |
| — | Site-scoped authorization (`site_user`, `sites.access_all`, pickers/lists/actions, current-site switcher) |
| — | Dashboard lean widgets + Analytics page + My dashboard prefs; home bundle renders opted-in analytics keys |
| — | Admin platform dashboard: Platform Analytics, home analytics bundle, My dashboard prefs, platform widget defaults |

Wholesaler inbound critical path is live: master data → EPCIS → receive → last-seen → SSCC labels → exceptions. Inter-site transfer and scan-in/ASN reconciliation are live.

## Partial

| Item | There | Missing |
|------|--------|---------|
| VRS | Verify + clients; history; dispense-check (**`vrs:dispense-check`** + `tracepharma:grant-dispense-check-ability`); responder (**per-tenant key**); async verify; manufacturer notify (FDA-first) | — |
| WMS | Ship-confirm; `complete:false`; per-tenant key; unique idempotency; **prod requires Idempotency-Key**; ASN/PO/invoice match | Full WMS bidirectional sync |
| PMS | Dispense scorecard; Verify Product history link; dispense-check API | PMS-specific adapters beyond Sanctum API |
| L3 | Organization L3 (masked API key UI); ForwardCommissioningToL3 | Full L3 UI / reconciliation workflows |
| Recall / tracing | Tracing + SLA (**first-response clock preserved**); complete requires response; recall broadcast; ack portal | — |
| Outbound EPCIS | Connections + LabelPrinters (**mutation policies**); transmitter fail-closed; Ship Order (**ATP zero-site block**); jobs (**force-fail sticks**, overlap lock release, parsing→error); AS2 S/MIME + MDN; pack/unpack/break (**site on-hand**, aggregation hard-fail, **child+parent locks**); SSCC SiteAccess + ship_from_site_id; **completed-pending-generate exclusivity**; **enqueue TOCTOU + transmit fail-closed**; **L3 forwarded idempotency** | Partner AS2 MIME quirks; AS2 inbound |
| Quarantine / compliance UI | Workstation + SiteAccess; **exception changeStatus cannot fake resolve/close**; **manual exceptions site_id scoped**; **3911/Find-Recall/Verification/Unpacked/AssetTrace aligned**; reports hub | — |
| Floor receive UX | Floor + camera; staged scans; scan-first custody; ASN SiteAccess; **null-auth/null-site fail-closed**; mobile **promptCopy scan helper**; return custody re-check | — |
| Sanctum | Token mint allowlist; EPCIS APIs; dispense grant command | AS2 inbound |
| Scout | Tenant indexes; ingest validate-before-generation / finally index; tenancy-required Scout | — |
| Demo path | Master-data + choreography seed (`--transfer` / `--unpack` / `--pack` / `--return`) | `--receive-only` live Ship Order click |
| Tenant management | DB-per-tenant; pair provision; suspend gate + cascade; impersonation; kill switches; compliance export ZIP | Self-service sandbox; usage quotas; custom domains/SSO; branding/Stripe out of scope |

## Missing (mapped to port phases)

| Port phase | Gap |
|------------|-----|
| **1 — Outbound** | Done for ICP spine — remaining depth folds into Phase 6 (AS2, health, multi-partner routing) |
| **2 — Compliance** | Done for ICP including recall broadcast email + delivery tracking rows + partner ack portal |
| **3 — Floor UX** | Done for receive + transfer + ship floor pages (camera; receive staged scans) |
| **4 — VRS** | Done for ICP including async verify job on receive confirm |
| **5 — Returns / pack** | Done for ICP (Return / Pack / Unpack / Break-pack workstations). Ship-from-received covered by outbound Ship Order from site custody |
| **6 — Integration ops** | Partner-specific AS2 MIME tuning; full L3 UI — lean MVPs shipped (health dashboard, AS2 + lean S/MIME, async MDN webhook, WMS webhook with idempotency, PMS scorecard buckets, L3 commissioning forward job) |
| — | Persona-gated nav (opt-in `job_roles_enabled`; default off = profile + site membership) |

## Next (locked sequence)

Source of truth for scope and exit criteria: [Vatengi feature port phases](/home/jmakoni/.cursor/plans/Vatengi%20feature%20port%20phases-c446060f.plan.md).

| Order | Phase | Lock rationale |
|-------|-------|----------------|
| **1** | **Outbound network** | Shipped (connections, transmitter, Ship Order, Outbound EPCIS, `ship_proven`) — further depth → Phase 6 |
| **2** | **Compliance case depth** | ICP shipped including recall broadcast |
| **3** | **Floor UX parity** | Receive + Transfer + Ship floor pages shipped |
| **4** | **Production VRS** | ICP shipped including async verify on receive confirm |
| **5** | **Returns / pack / hierarchy** | ICP shipped (Return / Pack / Unpack / Break-pack); not a greenfield port |
| **6** | **Integration ops** | **Shipped** — AS2 S/MIME + async MDN, multi-partner `is_default` routing, WMS `complete:false` + Idempotency-Key, PMS scorecard buckets, L3 forward job; remaining: partner MIME quirks, AS2 inbound, full WMS bidir, L3 reconcile UI |

**ICP default:** pharmacy + wholesaler inbound/outbound (demo2). Manufacturer/L3 and buying-group later.

### Do not port (lean filter)

AI copilot / AI enrichment, 8 demo persona roles + `PersonaAccess` as a forced nav matrix, buying-group profile, analytics workspace / dashboard sprawl, duplicate Vatengi receiving stack, marketing/competitor site (unless GTM asks).

**Job roles (opt-in):** Tenant setting `access.job_roles_enabled` (Organization → Access, default **off**). When off: `TenantFeatures` + `SiteAccess` only; master-data writes are Owner-only. When on: turning the toggle on auto-runs `TenantRoleSeeder` (permissions sync on enable); seeded persona roles get atomic `nav.*` permissions; Filament `canAccess()` also requires `JobRoleAccess::allows(...)`. **Owner** always retains Organization Settings access. Buying Group tenants have no Organization Access toggle (`supportsMasterData` false — Owner-only persona). Users with the flag on but no assigned role land on Dashboard with a no-role message (not a hard 403). Re-seed manually with `php artisan tracepharma:seed-tenant-job-roles` if needed. Do not introduce TraceLink company/partner/module Spatie team scopes — Stancl + SiteAccess already cover company/site.

**Below-container item-level receiving (parked):** Do not greenfield a Delivery/T2 workspace, three-panel hierarchy IDE, or offline scan queue. No tenant profile requires unit-by-unit under an open tote today — `ReceivingPolicy` defaults sealed parent confirm (pharmacy tote/case; others pallet) with unpack as a separate step for wholesaler/3PL. Product answer: sealed SSCC confirm + `ReceivingIssues` / `FlagManualReceivingException` for shortage/overage/damaged. If a warehouse tenant later needs anti-comingling, extend `ReceivingSession` with locked `active_parent_epc_id` + item scan mode only — do not invent a Delivery domain.

### Porting rules

1. One workflow at a time; Pest tests against TracePharma schema.
2. Map models before copying Filament pages.
3. Enforce `SiteAccess` on every new picker/list/action.
4. Gate with `TenantFeatures`; optional job-role capabilities via `JobRoleAccess` when the tenant enables them — do not force PersonaAccess nav matrices.
5. Prefer Vatengi Actions/Services + golden tests, then thin TracePharma Filament pages.
