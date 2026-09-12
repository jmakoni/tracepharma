# BestRx → TracePharma dispense-check runbook

**Product API (only):** `POST /api/v1/dispense-check`  
Authenticated with Laravel Sanctum Bearer token that includes the **`vrs:dispense-check`** ability.

**There is no** `POST /api/v1/pms/bestrx/dispense` (or any `/api/v1/pms/{vendor}/dispense`) route. Named per-vendor adapter routes are not GA.

Parent guide: [../pms.md](../pms.md) · Certified adapters deferred: [../multi-pms-adapters.md](../multi-pms-adapters.md)

## Auth

1. In the App panel: **Settings → API tokens**, create a token and enable **Dispense-check (VRS verification gate)** (`vrs:dispense-check`).
2. Or grant on an existing token (tenant context):

```bash
php artisan tracepharma:grant-dispense-check-ability --tenant={tenant-id}
```

3. Call the tenant domain:

```
POST https://{your-tenant-domain}/api/v1/dispense-check
Authorization: Bearer {sanctum-token}
Content-Type: application/json
Accept: application/json
```

## Unified request body

Provide **either** `barcode` **or** both GTIN and serial (`gtin` or `gtin14` + `serial`).

| Field | Required | Notes |
|-------|----------|--------|
| `gtin` / `gtin14` | Yes* | 14-digit GTIN |
| `serial` | Yes* | Serial number (AI 21) |
| `barcode` | No* | Full GS1 element string |
| `lot` | No | AI 10 |
| `expiry` | No | AI 17; `YYMMDD` or `YYYYMMDD` |

\* See `DispenseCheckRequest`.

Example:

```json
{
  "gtin14": "00301123456789",
  "serial": "SN-77881"
}
```

## Response shape (200)

From `DispenseCheckController`:

```json
{
  "allowed": true,
  "status": "verified",
  "message": null,
  "verification_id": 12345
}
```

When blocked:

```json
{
  "allowed": false,
  "status": "quarantined",
  "message": "Under quarantine. Clear or release quarantine before dispensing.",
  "verification_id": 12346
}
```

Gate fills on `allowed === true` only. Optional `exception_id` when the caller may see the linked exception case.

## Mapping: BestRx webhook / middleware → unified payload

| Typical vendor / webhook field | Map to TracePharma |
|--------------------------------|--------------------|
| GTIN / product identifier | `gtin` or `gtin14` |
| Serial number | `serial` |
| Scanned GS1 string | `barcode` |
| Lot / batch | `lot` (optional) |
| Expiration date | `expiry` (optional) |
| Rx / claim / patient fields | **Omit** — not in the product API |

Shared-secret headers such as `X-BestRx-Secret` belong on your pharmacy-side bridge if you use one. TracePharma accepts only Sanctum on `POST /api/v1/dispense-check`. Reference bridge: [../samples/pms-webhook-adapter.php](../samples/pms-webhook-adapter.php).

## Cutover checklist

Complete certification in-app: **Integrations → PMS integration**.

Operator file for this vendor: `docs/integrations/pms/bestrx.md`. See also [../pms.md](../pms.md).
