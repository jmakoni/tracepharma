# Buying group network

Honest product status for pharmacy buying-group tenancy in TracePharma.

## GA today

| Surface | Notes |
|---|---|
| Buying group profile | Floor receive/ship and master-data CRUD stay **off** |
| Partner ATP readiness | Control-plane licence visibility (BG-local) |
| Compliance alert center | Integration/ATP signals without quarantine/3911 workstations; **BG network alerts** from member snapshots (ATP gaps, aging exceptions, unhealthy connections, stalled invites) |
| **Member roster** | CRUD roster of member pharmacies (`name`, optional `external_ref`, optional hard-linked `member_tenant_id`, `status`, `contact_email`, plus optional DEA/NPI/state license/primary GLN/affiliation/program SKU/sites count/notes) under Compliance → Member roster; list page **Export CSV** + enrollment stub (soft vs hard-linked counts, % with roster `affiliation_code`) |
| **Hard membership + consent** | Central `buying_group_memberships` (pending → active → revoked). BG Owner invites a Pharmacy tenant from the roster; pharmacy Owner accepts or declines from **Organization settings** (Pharmacy profile). Soft roster rows remain without a hard link. |
| **Member network health** | Compliance → Member health — ATP gaps, open/aging exceptions, connection health, last EPCIS success from daily rollup snapshots. Soft-only roster rows show **N/A / link tenant for live metrics**. **GA** |
| **Authorized partner matrix** | Compliance → Partner matrix — member ↔ wholesaler licence status snapshots (`buying_group_partner_facts`). Soft roster = N/A. **GA** |
| **Member rollup job** | `tracepharma:buying-group-rollup` (scheduled daily) reads active hard memberships, SELECT-only aggregates in member tenancy, writes idempotent `as_of` snapshots into the **BG tenant DB** only. Optional flag `features.buying_group_member_rollups` (default **true**). |
| **Program affiliation code** | BuyingGroup Organization Settings: tenant-level `buying_group.affiliation_code` via `TenantSettings::affiliationCode()`. **GA** |
| **Member compliance APIs** | Sanctum `/api/v1/buying-group/members`, `members/{id}/readiness`, `network/summary`, `partner-matrix` — ability `buying-group:network`, BuyingGroup profile only, paginated, **no EPC payloads**. See [`docs/integrations/buying-group-network-api.md`](../integrations/buying-group-network-api.md). **GA** |
| **Personas** | `BuyingGroupNetworkAdmin` (`NavCompliance` + `UsersManage`), `BuyingGroupAnalyst` (`NavCompliance`), least-privilege `BuyingGroupMember` (empty); Owner / Support Engineer retained |

Gate (Member roster / health / matrix): `TenantFeatures::supportsBuyingGroupNetwork()` (BuyingGroup only). Roster also requires `JobRoleAccess::allowsOwnerOrAny(Permissions::UsersManage)`. Health + matrix require `NavCompliance` + rollups enabled. Organization Settings for BG opens for program affiliation (`supportsBuyingGroupNetwork()`), not master-data CRUD.

Consent UI lives on the **member Pharmacy** Organization Settings page (`supportsMasterData()`). BuyingGroup tenants may open Organization Settings for the **Buying group program** affiliation code.

Hard links are **not** free-text UUID edits on the roster form — use **Invite TracePharma tenant** / Accept invite / Revoke.

## Deferred (not GA)

- Full enrollment analytics (first EPCIS %, exemption tags, white-label CTA copy)

Do not oversell channel enablement beyond the affiliation code + roster enrollment stub. Health scorecards, partner matrix, member APIs, and affiliation **are** GA via snapshots (not live EPC fan-out). Marketing may claim them as shipped for BuyingGroup control-plane tenancy — still **not** “makes the buying group DSCSA compliant as an ATP.”

## Related

- Profile navigation: [`docs/product/profile-navigation.md`](./profile-navigation.md)
- Integrations: [`docs/integrations/buying-group-network-api.md`](../integrations/buying-group-network-api.md)
- Design: Wave 3 slice 1 in `docs/superpowers/specs/2026-08-28-wave3-role-expansion-design.md`
