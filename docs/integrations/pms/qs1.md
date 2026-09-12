# QS/1 → TracePharma dispense-check runbook

**Product API (only):** `POST /api/v1/dispense-check`  
Authenticated with Laravel Sanctum Bearer token that includes the **`vrs:dispense-check`** ability.

**There is no** `POST /api/v1/pms/qs1/dispense` (or any `/api/v1/pms/{vendor}/dispense`) route. Named per-vendor adapter routes are not GA.

Parent guide: [../pms.md](../pms.md) · Certified adapters deferred: [../multi-pms-adapters.md](../multi-pms-adapters.md)

## Auth

1. Sanctum token with **`vrs:dispense-check`** via App panel **API tokens**, or:

```bash
php artisan tracepharma:grant-dispense-check-ability --tenant={tenant-id}
```

2. Endpoint:

```
POST https://{your-tenant-domain}/api/v1/dispense-check
Authorization: Bearer {sanctum-token}
Content-Type: application/json
Accept: application/json
```

## Unified request body

| Field | Required | Notes |
|-------|----------|--------|
| `gtin` / `gtin14` | Yes* | 14-digit GTIN |
| `serial` | Yes* | Serial |
| `barcode` | No* | Full GS1 element string |
| `lot`, `expiry` | No | Optional |

\* barcode **or** gtin/gtin14 + serial (`DispenseCheckRequest`).

## Response shape (200)

```json
{
  "allowed": true,
  "status": "verified",
  "message": null,
  "verification_id": 12345
}
```

Blocked fills return `allowed: false` with `status` / `message` from verification or quarantine (`DispenseCheckController`).

## Mapping: QS/1 webhook / middleware → unified payload

| Typical vendor / webhook field | Map to TracePharma |
|--------------------------------|--------------------|
| GTIN / NDC→GTIN | `gtin` or `gtin14` |
| Serial number | `serial` |
| Full scan string | `barcode` |
| Lot / expiry | `lot` / `expiry` |
| Claim / Rx metadata | **Do not send** |

`X-QS1-Secret` (if configured on a local adapter) is not a TracePharma product auth mechanism. Forward to `POST /api/v1/dispense-check` with Sanctum. Sample: [../samples/pms-webhook-adapter.php](../samples/pms-webhook-adapter.php).

## Cutover checklist

In-app: **Integrations → PMS integration**. Operator file: `docs/integrations/pms/qs1.md`. Parent: [../pms.md](../pms.md).
