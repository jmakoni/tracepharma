# PMS integration pack (dispense-check)

TracePharma exposes a tenant-scoped **dispense-check** API so pharmacy management systems (PMS) can block fills until DSCSA verification passes.

## Endpoint

```
POST https://{your-tenant-domain}/api/v1/dispense-check
Authorization: Bearer {sanctum-token}
Content-Type: application/json
```

## Required token ability

Sanctum tokens must include the `vrs:dispense-check` ability.

Grant on existing tokens (tenant context):

```bash
php artisan tracepharma:grant-dispense-check-ability --tenant={tenant-id}
```

Create a new token from **Settings → API tokens** in the App panel and include **Dispense-check (VRS verification gate)** when issuing.

## Request body

| Field | Required | Description |
|-------|----------|-------------|
| `gtin` | Yes* | 14-digit GTIN |
| `serial` | Yes* | Serial number |
| `barcode` | No | Full GS1 element string (alternative to gtin+serial) |
| `site_id` | No | Site scope when job roles limit site access |

\* Provide either `barcode` or both `gtin` and `serial`.

## Response (200)

```json
{
  "allowed": true,
  "reason": null,
  "verification_id": 12345
}
```

When blocked:

```json
{
  "allowed": false,
  "reason": "quarantined",
  "verification_id": null
}
```

## Reference flows

1. **Direct REST** — PMS middleware calls dispense-check before completing a fill (recommended).
2. **Webhook adapter** — See [samples/pms-webhook-adapter.php](samples/pms-webhook-adapter.php) for a minimal bridge that accepts a PMS webhook and forwards to dispense-check.
3. **Workstation fallback** — Staff use **Verify Product** when API integration is not yet live.

## Postman

Import [postman/tracepharma-pms-dispense-check.json](postman/tracepharma-pms-dispense-check.json).

Set collection variables:

- `base_url` — `https://your-tenant.example.com`
- `api_token` — Sanctum token with `vrs:dispense-check`

## Certification checklist

- [ ] ATP evaluation jurisdictions configured (org facility states/countries, or preferred receiving state fallback)
- [ ] Upstream wholesaler EPCIS receiving proven
- [ ] API token issued with `vrs:dispense-check`
- [ ] Test GTIN+serial returns `allowed: true` for in-custody product
- [ ] Test quarantined serial returns `allowed: false`
- [ ] PMS logs blocked reason for audit
- [ ] Production cutover runbook shared with pharmacy IT

## Related

- In-app: **Verify Product**, **VRS lookup directory**, **API tokens**
- Compliance: dispenser scorecard and FDA 3911 exports in **Compliance reports**
- Per-vendor certified adapters remain pilot-gated — see [multi-pms-adapters.md](multi-pms-adapters.md)
