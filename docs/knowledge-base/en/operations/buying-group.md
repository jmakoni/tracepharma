---
title: Buying Group
parent: operations
order: 25
group: Operations
---

# Buying Group

Filament classes:

- `App\Filament\App\Resources\BuyingGroupMembers\BuyingGroupMemberResource`
- `App\Filament\App\Pages\MemberNetworkHealth`
- `App\Filament\App\Pages\AuthorizedPartnerMatrix`
- `App\Filament\App\Pages\AtpPartnerReadiness`
- `App\Filament\App\Pages\OrganizationSettings` (program affiliation on BuyingGroup; consent on Pharmacy)

## When to use

Operate a buying-group **network control plane**: member roster, partner ATP readiness, member health snapshots, authorized partner matrix, compliance alerts, and Sanctum member APIs — without warehouse receive/ship for the group entity. Legal DSCSA trading-partner duties stay with member dispensers (and any separate wholesaler the organization also runs).

## Prerequisites

- Tenant profile is Buying Group.
- **Roster CRUD / invites / affiliation settings:** Owner or a role with `UsersManage` (e.g. Buying Group Network Admin). See `BuyingGroupMemberResource::canAccess()`.
- **ATP readiness, Member health, Partner matrix:** `NavCompliance` (same gate as `AtpPartnerReadiness`) — Buying Group Analyst or Network Admin when job roles are on.
- Member rollups flag `features.buying_group_member_rollups` enabled (default **true**) for health and matrix pages.
- Hard-linked pharmacy members must accept network consent before live metrics appear.

## Steps

### Member roster

1. Open **Member roster** under Compliance. Use Help for live UI.
2. Create or edit members with required identifiers and status. Optional roster fields: DEA, NPI, state license, primary GLN, affiliation code, program SKU, sites count, and notes.
3. Use **Export CSV** on the list page when partners need an offline roster snapshot.
4. Invite a TracePharma Pharmacy tenant via **Invite TracePharma tenant** (do not paste free-text UUIDs). Suspend or remove soft roster rows who leave the group.
5. Review the enrollment stub (soft vs hard-linked counts, % with roster affiliation code).

### Member health

1. Open **Member health**. Rows come from the daily `tracepharma:buying-group-rollup` snapshot (ATP gaps, open/aging exceptions, connection health, last EPCIS success).
2. Soft-only roster rows show **N/A / link tenant for live metrics** until a hard membership is active.
3. Use **Alert center** for network signals derived from the same snapshots.

### Authorized partner matrix

1. Open **Partner matrix**. Filter by licence status when needed.
2. Member ↔ wholesaler licence facts are denormalized into the buying-group tenant DB only — no EPC payloads.
3. Soft roster members stay N/A until hard-linked.

### Program affiliation (BuyingGroup)

1. Open **Organization settings** → Buying group program.
2. Set the tenant-level **affiliation code** (`TenantSettings::affiliationCode()`).
3. Roster enrollment stub reports how many members carry a matching roster affiliation code.

### Consent (Pharmacy members)

1. On the **member Pharmacy** tenant, open Organization settings.
2. Accept or decline a pending buying-group membership invite; consent is versioned and audited.
3. Revoke from the buying-group roster when membership ends (roster may move to suspended).

### Member APIs

1. Create a Sanctum token with ability `buying-group:network` on a BuyingGroup tenant.
2. Call paginated `/api/v1/buying-group/members`, `members/{id}/readiness`, `network/summary`, and `partner-matrix`.
3. Expect snapshot / roster control-plane JSON only — **no EPC or EPCIS document payloads**. See product integrations note for details.

## Related pages

- ATP readiness and Compliance alert center (control-plane)
- [../compliance/atp-readiness.md](../compliance/atp-readiness) — BG-local partner ATP shell
- [../compliance/compliance-alerts.md](../compliance/compliance-alerts) — integration / ATP / network alerts
- [../integrations/api-tokens.md](../integrations/api-tokens) — Sanctum token abilities
- [../settings/settings-hub.md](../settings/settings-hub) — Settings Hub remains off for buying-group profiles; Organization settings opens for affiliation / pharmacy consent

## Notes

- Display organization type is **Buying group**, not Distributor.
- Floor receive/ship, master-data CRUD, and VRS workstations stay **off** on this profile.
- **GA:** Member roster (CRUD + CSV + invite/consent), Member network health, Authorized partner matrix, Member compliance APIs, program affiliation code + enrollment stub.
- Soft roster rows never invent live metrics; hard link + consent is required for rollups.
- TracePharma does **not** make the buying group itself DSCSA-compliant as an ATP warehouse — members remain trading partners.
