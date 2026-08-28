# Liberty / Rx30 → TracePharma dispense-check runbook

**Product API (only):** `POST /api/v1/dispense-check`  
Authenticated with Laravel Sanctum Bearer token that includes the **`vrs:dispense-check`** ability.

**There is no** `POST /api/v1/pms/liberty/dispense` or `POST /api/v1/pms/rx30/dispense` (or any `/api/v1/pms/{vendor}/dispense`) route. Named per-vendor adapter routes are not GA.

Parent guide: [../pms.md](../pms.md) · Certified adapters deferred: [../multi-pms-adapters.md](../multi-pms-adapters.md)

## Auth

1. Issue a Sanctum token with **`vrs:dispense-check`** (App panel **API tokens**, or `tracepharma:grant-dispense-check-ability`).
2. Call:

```
POST https://{your-tenant-domain}/api/v1/dispense-check
Authorization: Bearer {sanctum-token}
Content-Type: application/json
Accept: application/json
```

## Unified request body

Same contract as all PMS vendors — see `DispenseCheckRequest` / [../pms.md](../pms.md):

| Field | Required | Notes |
|-------|----------|--------|
| `gtin` / `gtin14` | Yes* | 14-digit GTIN |
| `serial` | Yes* | Serial |
| `barcode` | No* | Full GS1 string |
| `lot`, `expiry` | No | Optional |

## Response shape (200)

```json
{
  "allowed": true,
  "status": "verified",
  "message": null,
  "verification_id": 12345
}
```

Gate on `allowed`. Optional `exception_id` when visible to the token user (`DispenseCheckController`).

## Mapping: Liberty / Rx30 webhook / middleware → unified payload

| Typical vendor / webhook field | Map to TracePharma |
|--------------------------------|--------------------|
| GTIN / product code | `gtin` or `gtin14` |
| Serial | `serial` |
| Scanned barcode | `barcode` |
| Lot / expiry | `lot` / `expiry` |
| Store, Rx, patient | **Omit** |

Optional local shared secret (`X-Liberty-Secret`) is for your bridge; TracePharma uses Sanctum only. Reference: [../samples/pms-webhook-adapter.php](../samples/pms-webhook-adapter.php).

## Cutover checklist

In-app: **Integrations → PMS integration**. Operator file: `docs/integrations/pms/liberty-rx30.md`.
