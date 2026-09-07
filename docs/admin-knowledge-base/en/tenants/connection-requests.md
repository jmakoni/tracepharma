---
title: Connection Requests
parent: tenants
order: 20
group: Tenants
---

# Connection Requests

Filament classes:

- `App\Filament\Admin\Resources\ConnectionRequests\ConnectionRequestResource`

## When to use

Review inbound/outbound connections that tenant users create. Every new connection stays **Pending review** — it cannot send or receive EPCIS documents — until a platform admin approves it here. This is the gate that keeps rogue endpoints off the platform domains.

## Prerequisites

- Admin role with the **Manage tenants** permission.

## Steps

1. Open **Tenants → Connection requests**. The navigation badge shows the pending count.
2. Filter by status or direction; open a request to review the snapshot: tenant, connection name, network/provider, transport, counterparty, endpoint host, and who requested it.
3. **Approve** — the tenant connection flips to Approved and traffic flows immediately.
4. **Reject** — requires a reason. The note is shown to the tenant on the connection page; the connection stays blocked.
5. A tenant editing a rejected connection automatically resubmits it — it reappears here as pending.

## Related pages

- [tenants.md](../tenants/tenants) — tenant provisioning
- [customer-onboarding.md](../tenants/customer-onboarding) — customer onboarding queue
- [../settings/platform-connections.md](../settings/platform-connections) — hub environments and tokens

## Notes

- The queue row is a central snapshot; the decision is written back into the tenant database automatically.
- Connections created before this gate existed, and platform-seeded system templates, are approved by default and never appear here.
- Approval is per connection. Re-approval after endpoint/credential edits on an approved connection is not required in this version.
