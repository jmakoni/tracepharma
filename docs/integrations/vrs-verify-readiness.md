# VRS Verify readiness

**Honesty:** This checklist is an internal TracePharma go-live path for Verification Router Service (VRS) wiring. It is **not** Gateway Certified, TraceReady, GS1 Trustmark, or any GS1 Exchange / Query Control Interface certification.

Production keeps `VRS_DRIVER` defaulting to **`null`** until a real HTTP endpoint is configured. With `null`, required verify flows (Verify Product, dispense-check, saleable return) **fail closed** — they do not record `verified`. Receive still skips the async VRS job when the driver is null (accept is not verify-before-accept). Local/non-production defaults to `fake` so Verify Product works without a live router.

## Prerequisites checklist

1. Set **`VRS_DRIVER=http`** in the target environment.
2. Set a **real** (non-placeholder) **`VRS_BASE_URL`** — absolute `https://…` host that is not `*.example.com` / placeholder markers. `HttpVrsClient` fail-closes on example hosts.
3. Set **`VRS_API_KEY`** (partner-issued) and **`VRS_REQUESTOR_GLN`** (or rely on tenant site GLN when present).
4. Optionally set **`VRS_VERIFY_PATH`** (default `/api/v1/verify`) and **`VRS_TIMEOUT`**.
5. Confirm `HttpVrsClient::assertConfigured()` succeeds (constructor and deploy checks reject empty / non-absolute / placeholder URLs).

## Smoke tests

1. **Filament Verify Product** — run a known-good GTIN+serial (and lot/expiry if required by the partner) and confirm a `verifications` row with an expected status.
2. **`POST /api/v1/dispense-check`** — Sanctum token with `vrs:dispense-check` ability; confirm the API returns a persistable verification outcome for the same identifier.
3. **Optional responder** — if acting as a VRS responder, configure per-tenant `vrs_responder_api_key` (preferred) or lab-only `VRS_RESPONDER_API_KEY`, then smoke `POST /api/webhooks/vrs/{tenantId}` with `X-Vrs-Api-Key` or Bearer.

## Evidence export

```bash
php artisan vrs:export-readiness-log --tenant=<tenant-id> --limit=100
# default output: storage/app/evidence/vrs-readiness.json
```

The JSON includes `certification_claim` and an `honesty` note stating this pack is **not Gateway Certified**. Empty tenants export an empty `verifications` array and exit 0.

## Related

- `config/vrs.php` — driver defaults and HTTP / responder settings
- `.env.example` — VRS_* variable comments
- `App\Services\Vrs\HttpVrsClient` — placeholder host rejection
