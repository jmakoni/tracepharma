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

Maintain buying-group membership for tenants that participate in GPO / buying-group limited workflows.

## Prerequisites

- Buying-group features enabled for the tenant profile.
- Member identifiers and eligibility known.

## Steps

1. Open **Buying group members**. Open the page and use Help for live UI.
2. Create or edit members with required identifiers and status.
3. Deactivate members who leave the group.
4. Confirm limited banners / gates behave correctly for non-members.

## Related pages

- [../master-data/trading-partners.md](../master-data/trading-partners) — related partner records
- [../settings/settings-hub.md](../settings/settings-hub) — organization context
- [on-hand-and-unpacked.md](../operations/on-hand-and-unpacked) — inventory still site-scoped

## Notes

- Membership changes can immediately affect UI gates — communicate to warehouse users.
- Not all tenant types show this resource.
