# PrimeRx → TracePharma dispense-check runbook

**Product API (only):** `POST /api/v1/dispense-check`  
Authenticated with Laravel Sanctum Bearer token that includes the **`vrs:dispense-check`** ability.

**There is no** `POST /api/v1/pms/primerx/dispense` (or any `/api/v1/pms/{vendor}/dispense`) route. Named per-vendor adapter routes are not GA.

Parent guide: [../pms.md](../pms.md) · Certified adapters deferred: [../multi-pms-adapters.md](../multi-pms-adapters.md)

## Auth

1. App panel → **Settings → API tokens** → include **Dispense-check (VRS verification gate)** (`vrs:dispense-check`).
2. Or:

```bash
php artisan tracepharma:grant-dispense-check-ability --tenant={tenant-id}
```

3. Endpoint:

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
| `serial` | Yes* | Serial (AI 21) |
| `barcode` | No* | Full GS1 element string |
| `lot` | No | Optional |
| `expiry` | No | Optional `YYMMDD` / `YYYYMMDD` |

\* `DispenseCheckRequest`: barcode **or** (gtin/gtin14 + serial).

```json
{
  "barcode": "(01)00301123456789(21)PRIME99(10)LOT1(17)261231"
}
```

## Response shape (200)

```json
{
  "allowed": true,
  "status": "verified",
  "message": null,
  "verification_id": 12345
}
```

Blocked example:

```json
{
  "allowed": false,
  "status": "failed",
  "message": "Verification did not pass",
  "verification_id": 12347
}
```

Treat only `allowed: true` as clear-to-dispense. Implemented by `DispenseCheckController`.

## Mapping: PrimeRx webhook / middleware → unified payload

| Typical vendor / webhook field | Map to TracePharma |
|--------------------------------|--------------------|
| GTIN / NDC encoded as GTIN | `gtin` / `gtin14` |
| Serial | `serial` |
| Raw barcode / DataMatrix | `barcode` |
| Lot, expiry | `lot`, `expiry` |
| Script / fill metadata | **Do not forward** |

`X-PrimeRx-Secret` (if used) authenticates your local adapter only — not a TracePharma product route. Forward normalized JSON to `POST /api/v1/dispense-check` with Sanctum. Sample: [../samples/pms-webhook-adapter.php](../samples/pms-webhook-adapter.php).

## Cutover checklist

In-app: **Integrations → PMS integration**. Operator file: `docs/integrations/pms/primerx.md`. Parent: [../pms.md](../pms.md).
