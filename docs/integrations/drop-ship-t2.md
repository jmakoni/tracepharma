# Drop-ship / T2 dispenser path

Peers sometimes market network drop-ship paths (e.g. TraceLink T2) where a wholesaler ships on behalf of a manufacturer to a dispenser with multi-party TI ownership.

## TracePharma today (GA)

- **Drop-shipment indicator:** On Ship Order, operators can mark **Drop shipment**. When flagged, outbound shipping EPCIS includes a GS1 `dropShipment` element (`<gs1ushc:dropShipment>true</gs1ushc:dropShipment>`) that inbound `EpcisCatalogBusinessRules::checkDropShipmentIndicator` detects. Default is off — existing ships are unchanged. If the flag is on but the indicator cannot be emitted, authoring fails closed with a clear error.
- **Dispenser TI delivery:** Outbound ship + customer portal + **email-on-ship** deliver TI/TS to dispensers without requiring AS2.
- Multi-site transfer and GLN-scoped custody cover intracompany movement.

See also [outbound-transports.md](outbound-transports.md).

## What is still deferred

**TraceLink-style multi-party drop-ship / T2 network choreography** (manufacturer→wholesaler→dispenser custody routing / Delivery UI) is **not** in the general release.

Ship that network path only when a **named paying wholesaler pilot** needs it **and** portal email-on-ship is already proven for that partner’s dispensers.

Until then:

1. Use Ship Order (optional drop-shipment indicator) + customer portal / email-on-ship for dispenser TI.
2. Prefer HTTPS / hub for partner EPCIS (see [outbound-transports.md](outbound-transports.md)).

## Related

- Customer portal
- Partner onboarding kit
- [outbound-transports.md](outbound-transports.md)
- [partner-exception-collaboration.md](partner-exception-collaboration.md)
