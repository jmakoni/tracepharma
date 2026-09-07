---
title: Platform Connections
parent: settings
order: 25
group: Settings
---

# Platform Connections

Filament classes:

- `App\Filament\Admin\Pages\PlatformConnections`

## When to use

Manage the TracePharma-owned connection edges — the inbound EPCIS hub, outbound network edges, the platform AS2 station, and the platform SFTP drop — per environment (Demo / Stage / Prod). This page replaces the old **EPCIS hub settings** page (the old URL redirects here).

## Prerequisites

- Platform admin rights (`CatalogManage`).
- Knowing which environment you are editing. Stage/prod values are inert until deployed.

## Steps

1. Open **Platform connections** (Admin → Settings). Pick an edge category tab — **Inbound hub**, **Outbound to networks**, **AS2 station**, or **SFTP drop** — then the **Demo / Stage / Prod** sub-tab. Each environment sub-tab shows a status badge (Configured / Not configured). Both tab levels persist in the URL, so you can link directly to e.g. the stage AS2 station.
2. **Inbound hub**: set the hub token and enable receivers — **TracePharma hub (this platform)** for tenant-to-tenant documents, and **External networks** (Systech / UniTrace). Copy the hub URLs for partners that POST into TracePharma; they authenticate with the `X-Epcis-Hub-Token` header.
3. **Outbound to networks**: per network (Systech / UniTrace) set the outbound URL and token. Hub-linked tenant connections without their own endpoint send through this edge automatically — tenants never see these secrets.
4. **AS2 station**: set the station ID (e.g. `TRACEPHARMA-PROD`) and paste the signing/decryption certificate + key PEMs. Register each partner sender (`AS2-From` ID + their signing certificate) so the hub can verify inbound AS2 messages. Partners POST to `https://<admin-host>/api/webhooks/as2/hub`.
5. **SFTP drop**: host, port, username, and password or private key. Inbound files land in the inbound path, are routed to the owning tenant, then moved to processed (or `failed/` when unrouted). Outbound fallback files go to the outbound path.
6. Save once per page. Secret fields are write-only — they never render back; leaving them blank keeps the stored value.

## Rotating the hub token

1. On the **Inbound hub** tab, open the environment sub-tab and use **Generate new token** on the section header.
2. The modal shows the new token once — copy it immediately.
3. The previous token keeps working for 24 hours, so partners can cut over without downtime. Watch the logs for "authenticated with previous (rotating) token" to know when cutover is complete.

## What to send partners

- **HTTPS partners**: the hub URL for your environment + the current hub token.
- **AS2 partners**: your station ID, the AS2 hub URL, and the station public certificate (**Download station certificate** action). Collect their AS2 ID and signing certificate for the sender registry.
- **SFTP partners**: host, port, username, and the inbound/outbound paths.

## Related pages

- [outbound-networks.md](../settings/outbound-networks) — shared network profiles tenants attach to
- [../tenants/connection-requests.md](../tenants/connection-requests) — approving tenant connection requests
- [../tenants/tenants.md](../tenants/tenants) — tenant hub entitlement (`hub_providers`, inbound environment)
- [../platform/analytics.md](../platform/analytics) — hub coverage metrics
- [../../app/integrations/connections.md](../../app/integrations/connections.md) — tenant-side connections

## Notes

- Hub misconfiguration impacts many tenants at once — change windows matter.
- Do not copy stage secrets into prod (or vice versa).
- All PEMs, passwords, keys, tokens, and the sender registry are encrypted at rest.
- Platform edges route only to platform-approved tenant connections.
