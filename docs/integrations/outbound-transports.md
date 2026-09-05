# Outbound EPCIS transports

TracePharma’s default outbound path is **HTTPS-first**. Use the transport that matches your trading partner’s capabilities.

## Primary transports (recommended)

| Transport | Direction | Status | Use when |
|-----------|-----------|--------|----------|
| **HTTPS webhook** | Outbound push | Production | Partner exposes a POST endpoint for EPCIS XML |
| **SFTP drop** | Outbound push | Production | Partner requires file drop to an SFTP path |
| **AS2** | Outbound push | Production | Partner requires AS2 (with optional S/MIME) |
| **EPCIS hub** (Systech / UniTrace) | Outbound via hub | Production | Partner is on a supported hub route |
| **Email (EPCIS attachment)** | Outbound push | Production | Partner accepts TI as email attachment (explicit connection only — never ladder fallback) |
| **Client portal** | Buyer pull (OTP app) | Production (templates seeded inactive) | Dispensers without AS2 — publish TI to partner portal; ladder fallback when no B2B |
| **Customer portal (legacy signed link)** | Buyer pull (signed link) | Production | Legacy no-login signed URL; prefer Client portal OTP when enabled |
| **Sanctum API** | Programmatic pull | Production | Integrator polls outbound documents |

### System templates

Every tenant can run `php artisan tenants:seed-outbound-templates` (also runs on tenant create) to ensure two **inactive** system rows exist:

- `system_key=email_attachment` — Email (EPCIS attachment)
- `system_key=client_portal` — Client portal

Owners enable templates, set recipients / notify flags, and optionally mark default. System rows cannot be deleted.

### Unpinned routing ladder

When a document has no pinned `outbound_connection_id`:

1. Partner-scoped active HTTPS / SFTP / AS2  
2. Else global active HTTPS / SFTP / AS2  
3. Else active Client portal connection  
4. Else skip  

**Email is never selected by the ladder** — only via explicit pin or an Email connection set as the partner/global default (`resolve()` for session defaults).

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
