# Drop-ship / T2 dispenser path (conditional)

Peers sometimes market network drop-ship paths (e.g. TraceLink T2) where a wholesaler ships on behalf of a manufacturer to a dispenser with multi-party TI ownership.

## TracePharma today

- Outbound ship + customer portal + **email-on-ship** deliver TI/TS to dispensers without requiring AS2.
- Multi-site transfer and GLN-scoped custody cover intracompany movement.

## What is deferred

**Drop-ship / T2 dispenser network path** is **not** in the general release.

Ship it only when a **named paying wholesaler pilot** needs manufacturer→wholesaler→dispenser drop-ship choreography **and** portal email-on-ship is already proven for that partner’s dispensers.

Until then:

1. Use Ship Order + customer portal / email-on-ship for dispenser TI.
2. Prefer HTTPS / hub for partner EPCIS (see [outbound-transports.md](outbound-transports.md)).

## Related

- Customer portal
- Partner onboarding kit
- [outbound-transports.md](outbound-transports.md)
- [partner-exception-collaboration.md](partner-exception-collaboration.md) — full email-to-ticket also pilot-only
