# Outbound EPCIS transports

TracePharma’s default outbound path is **HTTPS-first**. Use the transport that matches your trading partner’s capabilities.

## Primary transports (recommended)

| Transport | Direction | Status | Use when |
|-----------|-----------|--------|----------|
| **HTTPS webhook** | Outbound push | Production | Partner exposes a POST endpoint for EPCIS XML |
| **SFTP drop** | Outbound push | Production | Partner requires file drop to an SFTP path |
| **AS2** | Outbound push | Production | Partner requires AS2 (with optional S/MIME) |
| **EPCIS hub** (Systech / UniTrace) | Outbound via hub | Production | Partner is on a supported hub route |
| **Customer portal** | Buyer pull (signed link) | Production | Dispensers without AS2 — TI after ship via signed portal |
| **Sanctum API** | Programmatic pull | Production | Integrator polls outbound documents |

## Inbound (supplier → you)

| Transport | Status |
|-----------|--------|
| HTTPS webhook | Production |
| SFTP poll (inbound) | Production |
| Hub receive | Production (ops-enabled) |
| AS2 inbound | Webhook + unwrap shipped (`As2InboundWebhookController`); **not** selectable on the Inbound Connections form yet |

## Partner exception collaboration

**Shipped MVP:** push email to trading-partner contacts + shared status on the supplier exception portal (including aging notify) and **PDG-aligned structured fields + JSON attachment**. See [partner-exception-collaboration.md](partner-exception-collaboration.md).

**Still pilot-gated:** inbound email-reply parser / partner apply-fix / full ticketing loop (HDA POET-style). Ship only with a named paying wholesaler after MVP is proven.

**Also pilot-gated:** multi-PMS certified adapters beyond the Sanctum dispense-check API + checklist (see [pms.md](pms.md) and [multi-pms-adapters.md](multi-pms-adapters.md)).

**Catalog honesty:** `MISSING_MDN` / `LATE_MDN` / `PARTNER_REJECTED_FILE` are operator-visible. Sync/async AS2 MDN failure emits `PARTNER_REJECTED_FILE` via `RecordOperationalEpcisCatalogSignal::partnerRejected`. Pending MDNs past SLA emit `MISSING_MDN` (default 24h) or `LATE_MDN` (default 72h) from `epcis:emit-pending-mdn-signals`. Open-row de-dupe is by document + exception type. `L3_TRANSMISSION_FAILURE` remains live from `ForwardCommissioningToL3`.

## Drop-ship / T2 dispenser path

**GA:** Ship Order **Drop shipment** flag emits GS1 `dropShipment` in outbound EPCIS; customer portal + portal email-on-ship remain the dispenser TI path. See [drop-ship-t2.md](drop-ship-t2.md).

**Still deferred:** TraceLink-style multi-party T2 network choreography / Delivery UI — ship only with a **named wholesaler design partner** after portal email-on-ship is proven in production.

## Partner onboarding summary

1. Create trading partner + inbound connection (webhook, SFTP poll, or hub).
2. Validate inbound EPCIS (parsed / validated).
3. Complete first receive session.
4. For dispenser customers: issue **customer portal** link after outbound TI ship.

## Related in-app pages

- **Partner onboarding kit** — guided supplier connect checklist
- **Integration health** — 24h transmit/receive status
- **Customer portal links** — signed buyer TI access
- **Outbound connections** — HTTPS / SFTP / AS2 configuration
