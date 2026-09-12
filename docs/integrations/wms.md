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
| `expected_count` / `quantity` | No | Expected scan count for the ship order (`quantity` is an alias) |
| `principal_id` | No* | Logistics3pl: TracePharma principal id |
| `principal_external_ref` | No* | Logistics3pl: WMS client code → `principals.external_ref` (active) |
| `principal_gln` | No* | Logistics3pl: client ATP GLN → `principals.gln` (active, 13 digits) |
| `trading_partner_id` | No | Downstream customer / trading partner |
| `customer_id` | No | Alias for trading partner |
| `ship_to_site_id` | No | Destination site |
| `ship_to_gln` | No | Destination GLN (13 digits) |
| `asn` / `asn_number` | No | Advance ship notice reference |
| `po` / `customer_po` | No | Customer purchase order |
| `invoice_number` | No | Invoice reference |
| `shipment_reference` | No | Free-text shipment reference |
| `dscsa_affirm` | No | Affirm DSCSA TI/TS for the shipment |

\*Ignored unless the tenant profile supports principals (`Logistics3pl`). Provide any one of `principal_id`, `principal_external_ref`, or `principal_gln` (conflicting values → HTTP 422). When `principalCustodyEnforced` is on and the payload omits all principal hints **and** the ship-from site has no default principal, TracePharma **rejects** the request (HTTP 422 / `DomainException`). Soft mode may open with null principal or the site default. Full soft vs enforced semantics: [3pl-principals.md](3pl-principals.md).

**Idempotency-Key** header is required in production. Replays with the same key return the original result; conflicting payloads (including a different principal) return HTTP 409.

### Logistics3pl principals (cross-link)

For multi-client 3PL tenants, ship-confirm can tag the outbound session with a principal via `principal_id`, `principal_external_ref`, or `principal_gln` (or rely on the site default). Tenant/hub GLNs (sites, own org) stay distinct from principal GLNs (TI seller / owning party when enforcement is on). See **[3PL principals](3pl-principals.md)** for hub vs principal identity, soft filters vs `principalCustodyEnforced`, role pack, and Wave D5 non-goals.

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
- Logistics3pl: [3PL principals](3pl-principals.md) (WMS principal map, soft vs enforced custody, hub vs principal GLNs)
