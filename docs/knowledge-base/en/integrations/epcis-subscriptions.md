---
title: EPCIS subscriptions
parent: integrations
order: 30
group: Integrations
---

# EPCIS subscriptions

Filament classes:

- `App\Filament\App\Resources\EpcisSubscriptions\EpcisSubscriptionResource`

## When to use

Manage EPCIS query/subscription records that pull or receive ongoing event streams from partners or hubs.

## Prerequisites

- Partner connection or hub credentials available.
- Clear subscription query scope (EPC classes, locations, event types).

## Steps

1. Open **EPCIS subscriptions**. Open the page and use Help for live UI.
2. Create or edit a subscription: destination, schedule/callback, and filters.
3. Activate and confirm first deliveries appear under inbound documents/jobs.
4. Pause or adjust filters when partners change scope.

## Related pages

- [connections.md](../integrations/connections) — underlying endpoints
- [integration-health.md](../integrations/integration-health) — delivery health
- [../exceptions/inbound-epcis.md](../exceptions/inbound-epcis) — received documents
- [../operations/epcis-jobs.md](../operations/epcis-jobs) — processing jobs

## Notes

- Over-broad subscriptions create noise and exception volume — tighten filters early.
- Deleting an active subscription can drop partner deliveries without notice; prefer disable.
