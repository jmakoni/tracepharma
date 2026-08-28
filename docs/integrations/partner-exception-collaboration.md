# Partner exception collaboration

TracePharma closes the **partner exception loop** with push email + shared portal status. Full **email-reply / ticketing** (inbound parse → case status) stays pilot-gated.

## Shipped MVP (general release)

1. **Email supplier** from Exception detail, Investigator SLA, or “request partner correction” (optional toggle).
2. **`exceptions:notify-aging-suppliers`** (daily 08:30) emails active trading-partner contacts for open cases older than `TRACEPHARMA_SUPPLIER_EXCEPTION_AGING_DAYS` (default 3), with cooldown `TRACEPHARMA_SUPPLIER_EXCEPTION_NOTIFY_COOLDOWN_HOURS` (default 72).
3. Notify **ensures case `share_uuid`** so the case appears on the **supplier exception portal** with status, days open, aging badge, and last notified.
4. Activity log records each notify (`DSCSA exception email sent…`, partner-visible).
5. **PDG / HDA-aligned notify pack** — supplier emails include structured fields (notification UUID, issue type, ship-to GLN, GTIN/SN/lot/expiry, shipment refs, buyer resolution request) plus an attached `pdg-exception-EX-*.json` summary. Notify-only; not a mandatory PDG transport.

## Explicitly not in this slice

- **No inbound email-reply parser** — partners do not change case status by replying to mail.
- No partner apply-fix UI / HDA POET-style multienterprise workspace.
- No second case system; portal is pull + status shared from TracePharma.

## What remains pilot-gated

**Full email-to-ticket / reply-driven workflow** ships only when a **named paying wholesaler** has enough exception volume after this MVP is live. Until then:

1. Use Email supplier / aging notify + supplier portal.
2. Resolve cases in Quarantine / Exceptions.
3. Prefer HTTPS / hub / customer portal for data exchange (see [outbound-transports.md](outbound-transports.md)).
4. See also [drop-ship-t2.md](drop-ship-t2.md) for the deferred drop-ship path.

## Related

- Compliance Alert Center + digests
- Supplier portal on Trading Partners
- Investigator SLA
- [outbound-transports.md](outbound-transports.md) — outbound SFTP also pilot-only
- [drop-ship-t2.md](drop-ship-t2.md) — drop-ship / T2 also pilot-only
- [wms.md](wms.md) — wholesaler WMS ship-confirm pack
