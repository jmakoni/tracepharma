# 3PL principals

Logistics 3PL tenants can maintain a **principal registry** and optionally tag sites, receive sessions, exceptions, and outbound ship orders with a principal.

## Tenant / hub GLNs vs principal GLNs (Wave D4)

Two different GLN identities appear in a multi-client 3PL tenant. Do not conflate them.

| Identity | Where it lives | Used for |
|----------|----------------|----------|
| **Tenant / hub GLNs** | Sites and the 3PL’s own organization (SGLN / facility GLNs claimed for hub routing) | Hub receiver routing, ship-from / bizLocation on outbound events, location custody (`EpcCustodyGate`), inbound SBDH receiver → tenant resolution |
| **Principal GLNs** | `principals.gln` (client ATP / title-holder identity) | Operational ownership tags; when custody is enforced, **TI seller / source owning party** on agent outbound authorship |

Implications:

- Hub route claims remain **tenant** GLNs. A principal GLN is **not** a separate hub-routed tenant and does not by itself claim documents on the EPCIS hub.
- Soft or enforced principal tagging does not change which GLN the hub uses to deliver inbound EPCIS to the 3PL warehouse.
- Optional later (not Wave D): claim principal facility GLNs via the existing hub claim-request workflow when the 3PL operates client docks under those GLNs.

## Soft filters vs `principalCustodyEnforced` (default off)

Gate for the product surface: `TenantFeatures::supportsPrincipals()` (true only for `TenantProfile::Logistics3pl`) plus `JobRoleAccess` / `nav.master_data`.

### Soft mode (default)

- Master Data → **Principals** CRUD (`name`, optional `gln`, optional `external_ref`, `is_active`)
- Optional `principal_id` on `sites`, `receiving_sessions`, `outbound_shipping_sessions`, `exceptions`, and `epcs`
- List filters on Sites / Ship Orders / Exceptions (and related ops surfaces) by principal
- Honesty copy: *Principals filter lists; serials are not isolated per client.*

Soft mode does **not** hard-gate scans. Inventory, verification, and EPCIS custody remain tenant-scoped (`EpcCustodyGate` location rules) unless enforcement is on.

### Enforced mode (opt-in)

`TenantSettings::principalCustodyEnforced()` (stored as `features.principal_custody_enforced`, **default false**). Enforcement also requires `supportsPrincipals()`.

When on:

1. Opening receive / ship requires a principal (explicit or site default).
2. Completing receive stamps `epcs.principal_id` from the session.
3. Ship / pack / return / decommission refuse cross-principal serials with: *This serial belongs to another principal.*
4. `ShippableEpcsAtSite` filters to the session principal.
5. **Agent TI (Wave C):** outbound seller / source owning party = principal GLN; ship-from / source location = 3PL site GLN. Authoring hard-fails if the principal has no GLN.
6. Exceptions inherit principal (site → EPC); list filter by principal.
7. VRS history shows principal; saleable-return disposition is principal-gated.
8. Asset Tracking + track-and-trace export constrain EPCs to principals on the actor’s accessible sites (Owners with all-site access see stamped EPCs across principals).
9. **WMS ship-confirm:** must resolve a principal (`principal_external_ref`, `principal_gln`, or site default); mismatch with site default is rejected.

Enable from **Settings → Organization → Principals → Enforce principal custody (EPC isolation)** (Logistics 3PL only), or via `TenantSettings::setPrincipalCustodyEnforced(true)` + `$tenant->save()` after backfill. Default remains **off**.

Backfill helper (dry-run by default):

```bash
php artisan tracepharma:backfill-epc-principals --tenants=<tenant-id>
php artisan tracepharma:backfill-epc-principals --tenants=<tenant-id> --apply
```

## WMS ship-confirm principal fields (Wave D1)

WMS may send either identifier on ship-confirm (webhook bridge or Sanctum). Both are optional unless enforcement is on and the ship-from site has no default principal.

| Field | Maps to | Notes |
|-------|---------|--------|
| `principal_external_ref` | `principals.external_ref` | WMS / host-system client code (max 128); must match an **active** principal |
| `principal_gln` | `principals.gln` | 13-digit client ATP GLN; normalized; must match an **active** principal |

Resolution (`ResolveWmsShipPrincipal`):

1. Payload hints (`principal_id`, `principal_external_ref`, and/or `principal_gln`) must agree if more than one is present.
2. Else fall back to the ship-from site’s default `principal_id`.
3. If a payload principal and a site default both resolve and **differ**, confirm is rejected.
4. When `principalCustodyEnforced` is on, a resolved principal is **required**.

See [wms.md](wms.md) for endpoint paths, auth, and the full request body table.

## Logistics3pl role pack (Wave D3)

`TenantRole::forProfile(Logistics3pl)` includes floor / WMS roles **plus** receive and verification personas aligned with wholesaler-class ops:

| Role | Notes |
|------|--------|
| SupportEngineer | Existing |
| **ReceivingTechnician** | Receive / Scan In floor |
| OutboundPickAndPackLead | Existing |
| InboundExceptionCoordinator | Existing |
| **AtpVerificationManager** | ATP / license verification ops |
| **VrsAnalyst** | VRS / verify product ops |
| WmsIntegrationSpecialist | Existing WMS connector |
| QuarantineAndReturnsSpecialist | Existing |

Owner is always included. Seed via the usual tenant role seeder for the Logistics3pl profile.

## Non-goals / deferred (Wave D5)

Explicitly **out of Wave D** (and not claimed as GA multi-client depth):

- **Drop-ship / full T2 network** — TraceLink-style multi-hop T2 drop-ship networking stays deferred as a separate epic. The existing GA `is_drop_shipment` flag on outbound sessions is unchanged; it is not a full T2 principal network.
- Separate database or Stancl tenant per principal
- MariaDB LIST partition by principal for isolation
- Full LSPedia OneScan Edge / Investigator / ATP directory product clone
- Plant commissioning for 3PL (`supportsCommissioning` stays false)
- Principal GLNs as hub route tenants (see hub vs principal table above)

## What this is not

- Not a separate DB per client, and not MariaDB partitions for client isolation (partitions stay time/retention).
- Direct-purchase TS statement for Logistics3pl remains **null** (agent without title — legal copy review if a principal-facing statement is required later).
- Deleting a principal nulls FKs (`nullOnDelete`); it does not rewrite historical EPCIS.
