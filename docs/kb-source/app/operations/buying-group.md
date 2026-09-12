---
title: Buying Group
parent: operations
order: 25
group: Operations
---

# Buying Group

Filament classes:

- `App\Filament\App\Resources\BuyingGroupMembers\BuyingGroupMemberResource`

## When to use

Maintain the buying-group **member roster** on a control-plane tenant (roster + ATP readiness + alerts). There is no warehouse floor, master-data CRUD, or member health scorecards on this profile.

## Prerequisites

- Tenant profile is Buying Group.
- Member identifiers and contact status known.

## Steps

1. Open **Member roster**. Use Help for live UI.
2. Create or edit members with required identifiers and status.
3. Suspend or remove members who leave the group.
4. Use **ATP readiness** and **Alert center** for network visibility — not receive/ship ops.

## Related pages

- ATP readiness and Compliance alert center (control-plane)
- [../settings/settings-hub.md](../settings/settings-hub) — not available for buying-group profiles

## Notes

- Display organization type is **Buying group**, not Distributor.
- Membership changes affect roster visibility only — there is no floor ops surface for this profile.
- Member health scorecards and compliance APIs are not part of this resource.
