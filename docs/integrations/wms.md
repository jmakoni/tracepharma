# WMS integration pack (wholesaler ship-confirm)

TracePharma exposes tenant-scoped **ship-confirm** endpoints so warehouse management systems (WMS) can drive outbound shipping sessions and optional outbound EPCIS without replacing your WMS.

## Webhook bridge (recommended for WMS middleware)

```
POST https://{your-tenant-domain}/api/webhooks/wms/{tenantId}
X-Wms-Api-Key: {wms-bridge-api-key}
Content-Type: application/json
Idempotency-Key: {uuid}
```

Configure the bridge API key in **Organization Settings → WMS ship-confirm bridge**. TracePharma validates `X-Wms-Api-Key` (or `Authorization: Bearer` with the same key).

## Sanctum connector

```
POST https://{your-tenant-domain}/api/v1/wms/ship-confirm
Authorization: Bearer {sanctum-token}
Content-Type: application/json
Idempotency-Key: {uuid}
```

### Required token ability

Sanctum tokens must include the `wms:ship-confirm` ability.

Create a token from **Settings → API tokens** in the App panel and include **WMS ship-confirm (Connector)** when issuing.

## Request body

| Field | Required | Description |
|-------|----------|-------------|
| `site_id` | No | Ship-from site when job roles limit site access |
| `scans` | Yes | Array of GS1 element strings (at least one) |
| `complete` | No | `false` to confirm scans without closing the session; omit or `true` to complete |
| `trading_partner_id` | No | Downstream customer / trading partner |
| `customer_id` | No | Alias for trading partner |
| `ship_to_site_id` | No | Destination site |
| `ship_to_gln` | No | Destination GLN (13 digits) |
| `asn` / `asn_number` | No | Advance ship notice reference |
| `po` / `customer_po` | No | Customer purchase order |
| `invoice_number` | No | Invoice reference |
| `shipment_reference` | No | Free-text shipment reference |
| `dscsa_affirm` | No | Affirm DSCSA TI/TS for the shipment |

**Idempotency-Key** header is required in production. Replays with the same key return the original result; conflicting payloads return HTTP 409.

## Response

```json
{
  "status": "confirmed",
  "session_id": 42,
  "confirmed_count": 3,
  "message": "Scans confirmed."
}
```

When blocked (quarantine, ATP, or session state):

```json
{
  "status": "blocked",
  "session_id": 42,
  "confirmed_count": 0,
  "message": "Shipment cannot proceed.",
  "blockers": ["quarantined_serial"]
}
```

Optional fields: `scan_errors`, `idempotent_replay`.

## Outbound EPCIS (Sanctum)

After ship-confirm, transmit outbound EPCIS with a token that includes `epcis:transmit`:

```
POST https://{your-tenant-domain}/api/v1/epcis/outbound
Authorization: Bearer {sanctum-token}
Content-Type: application/xml
```

Retrieve a submitted document:

```
GET https://{your-tenant-domain}/api/v1/epcis/outbound/{documentId}
Authorization: Bearer {sanctum-token}
```

List inbound documents (when inbound integrations are enabled):

```
GET https://{your-tenant-domain}/api/v1/epcis/documents
Authorization: Bearer {sanctum-token}
```

## Postman

Import [postman/tracepharma-wms-ship-confirm.json](postman/tracepharma-wms-ship-confirm.json).

Set collection variables:

- `base_url` — `https://your-tenant.example.com`
- `api_token` — Sanctum token with `wms:ship-confirm` (and optionally `epcis:transmit`)
- `wms_api_key` — Organization WMS bridge key (webhook path)
- `tenant_id` — Tenant UUID for webhook URL

## Certification checklist

- [ ] WMS bridge API key set (Organization settings → WMS ship-confirm bridge) **or** Sanctum token with `wms:ship-confirm`
- [ ] Integration Health shows outbound throughput baseline
- [ ] Test ship-confirm with `complete: false` then `complete: true`
- [ ] Idempotency-Key replay verified in staging
- [ ] Outbound EPCIS token (`epcis:transmit`) issued if middleware posts XML directly
- [ ] Confirm WMS webhooks are not disabled by admin kill switch

### Kill switch note

Platform admins can block WMS ship-confirm webhooks per tenant (**Block WMS ship-confirm webhooks**). When active, both the webhook bridge and Sanctum ship-confirm endpoints return errors until the switch is cleared.

## Related

- In-app: **Wholesaler / WMS pack**, **Integration health**, **API tokens**, **Organization settings**
- Operations: Scan Out workstation, outbound shipping sessions, outbound EPCIS documents
