# PioneerRx → TracePharma dispense-check runbook

**Product API (only):** `POST /api/v1/dispense-check`  
Authenticated with Laravel Sanctum Bearer token that includes the **`vrs:dispense-check`** ability.

**There is no** `POST /api/v1/pms/pioneerrx/dispense` (or any `/api/v1/pms/{vendor}/dispense`) route. Named per-vendor adapter routes are not GA.

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
| `gtin` / `gtin14` | Yes* | 14-digit GTIN (digits; padded left with zeros if shorter) |
| `serial` | Yes* | Serial number (AI 21) |
| `barcode` | No* | Full GS1 element string; alternative to gtin+serial |
| `lot` | No | AI 10; appended when building the scan from discrete fields |
| `expiry` | No | AI 17; `YYMMDD` or `YYYYMMDD` (8-digit normalized to 6) |

\* See `DispenseCheckRequest`: barcode alone is enough; otherwise both GTIN and serial are required.

Example:

```json
{
  "gtin": "00301123456789",
  "serial": "ABC123",
  "lot": "L1",
  "expiry": "261231"
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

When blocked (example — quarantine):

```json
{
  "allowed": false,
  "status": "quarantined",
  "message": "Under quarantine (exception #42). Clear or release quarantine before dispensing.",
  "verification_id": 12346,
  "exception_id": 42
}
```

- Gate fills on `allowed === true` only.
- `exception_id` is omitted when the token user lacks site access to the case.
- VRS not enabled for the tenant profile → HTTP 403.

## Mapping: PioneerRx webhook / middleware → unified payload

PioneerRx remains the system of record. Middleware (or a small bridge like [../samples/pms-webhook-adapter.php](../samples/pms-webhook-adapter.php)) should normalize vendor fields before calling TracePharma:

| Typical vendor / webhook field | Map to TracePharma |
|--------------------------------|--------------------|
| GTIN / NDC-as-GTIN / `gtin14` | `gtin` or `gtin14` |
| Serial / SN / AI(21) | `serial` |
| Full GS1 DataMatrix string | `barcode` (preferred when available) |
| Lot / batch | `lot` (optional) |
| Expiry / EXP | `expiry` (optional; strip non-digits) |
| Rx number, patient, store id | **Do not send** — not part of dispense-check |

If PioneerRx emits a shared-secret header (marketing docs may mention `X-PioneerRx-Secret`), that authenticates **your** bridge only. TracePharma product auth is **Sanctum Bearer + `vrs:dispense-check`**, not a PioneerRx-specific product route.

## Cutover checklist

Complete certification in-app: **Integrations → PMS integration** (`PmsIntegrationChecklistPage`).

Also walk [../pms.md](../pms.md) certification items and Postman collection `docs/integrations/postman/tracepharma-pms-dispense-check.json`.

Operator file for this vendor: `docs/integrations/pms/pioneerrx.md`.
