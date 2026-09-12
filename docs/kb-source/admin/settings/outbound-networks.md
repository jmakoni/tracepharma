# Outbound networks

Filament classes:

- `App\Filament\Admin\Resources\OutboundNetworkProfiles\OutboundNetworkProfileResource`

## When to use

Manage shared outbound network profiles — one reusable connection per serialization network (Systech, UniTrace, SAP ICH, TraceLink, LSPediA, …). Tenants attach trading partners to a profile instead of cloning endpoints per customer. The **TracePharma** profile is this platform's own tenant-to-tenant hub and is grouped first in tenant dropdowns.

## Prerequisites

- Platform admin rights (`CatalogManage`).
- Reusable values from the network (Hub URL, AS2-To). Tenant enrollment secrets, AS2-From, SFTP hosts, and PEMs are never stored here.

## Steps

1. Open **Outbound networks** (Admin → Settings).
2. Profiles are seeded for all networks × test/prod (TracePharma also demo/stage). Run `php artisan tracepharma:seed-outbound-network-profiles` after deploy.
3. Edit a profile to fill or correct the shared endpoint URL, AS2 URL, AS2-To, subject, allowed transports, or notes.
4. Seeded rows are locked; unlock to edit. Re-seeding without `--force` only fills blank fields on locked rows.
5. Tenants then pick the profile on their outbound connection and attach partners. **Use my own endpoint** on the tenant row overrides the profile URL for that connection only.

## Related pages

- [platform-connections.md](platform-connections.md) — inbound hub edges, platform AS2 station, and SFTP drop
- [../../app/integrations/connections.md](../../app/integrations/connections.md) — tenant outbound connections

## Notes

- Changing a profile URL does not change inbound webhook paths.
- AS2-From is always tenant-owned (each organization uses its own AS2 identifier).
- Email and Client portal are never network transports.
