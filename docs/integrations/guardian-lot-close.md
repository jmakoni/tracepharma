# Guardian (Systech) lot-close inbound

Manufacturer-only inbound feed: Guardian/UniSeries (L1–L3 line control) POSTs a
proprietary `DataFeed` XML at lot close. TracePharma archives the raw file,
projects a lot-master record for a UniTrace-style lot view, and auto-projects
commissioning + Pallet→Case→Bottle aggregation into the existing EPCIS
ingest pipeline (`epcs` / `aggregation_links` — no parallel event store).

See the full design decision in
`docs/superpowers/plans/2026-08-27-guardian-l3-lot-ingest.md`.

## Enabling it

Manufacturer tenants only, in Organization Settings → External L3:

1. Turn on **External L3 integration** (`l3_enabled`).
2. Set **L3 provider** to `Systech` (required — unset provider is rejected).
3. Set an **L3 API key** — Guardian sends this as `Authorization: Bearer {key}`.
4. Turn on **Accept Guardian lot-close inbound** (`l3_guardian_lot_close_enabled`).

Also requires:

- Tenant profile **Manufacturer**
- Platform **Inbound EPCIS** kill switch off (`TenantKillSwitches::INBOUND_EPCIS`)

## Endpoint

```
POST /api/v1/l3/guardian/lot-close
Host: {tenant-domain}
Authorization: Bearer {l3.api_key}
Content-Type: application/xml

<DataFeed>...</DataFeed>
```

- Tenant is resolved from the request host (same as other tenant API routes).
- Body is the raw Guardian `DataFeed` XML (no wrapper, no multipart).
- Max size: `config('tracepharma.guardian_lot_close.max_upload_mb')` (default 50MB).
  `Content-Length` over the limit is rejected with 413 before the body is buffered.
- DOCTYPE declarations are rejected (422).

### Responses

| Status | Meaning |
|--------|---------|
| 202 | Accepted — `{feed_id, message_id, status}`. Idempotent on `Envelope/MessageID` **or** identical payload SHA-256. Replays of `processing`/`accepted` do not re-dispatch (except stale `processing` older than the job timeout). **Failed** feeds redispatch on resubmit and the job resets `failed` → `processing` before re-running conversion (not a silent no-op). |
| 401 | Missing/incorrect Bearer key. |
| 403 | Feature off, provider not Systech, non-Manufacturer profile, kill switch on, or tenant suspended. |
| 413 | Body / Content-Length exceeds the configured max upload size. |
| 422 | Body isn't XML, DOCTYPE present, or `Envelope/MessageID` is missing. |

## What happens after 202

`App\Jobs\L3\ConvertAndAcceptGuardianLotJob` (queue `epcis`) runs asynchronously:

1. Stream-parses the archived XML (`App\Services\L3\GuardianDataFeedParser` — `XMLReader`).
2. Upserts one `serialization_lots` row and replaces `serialization_lot_container_fields`.
3. Authors EPCIS 1.2 commissioning + aggregation via `AuthorGuardianLotEpcisDocument` (Domain hard gates, deterministic event IDs, per-container event times).
4. Feeds that XML through `ReceiveEpcisUpload` as **`direction=outbound`** (self-authored capture, like SSCC labeling) with `received_via=guardian_lot_close`, sync.
5. Marks feed/lot `accepted` only when the EPCIS document status is **`validated`**; otherwise both are `failed`.

## UI

- **Operations → Serialization Lots** (Manufacturer only): overview, Lot Control Data, Hierarchy counts.
- **Asset Tracking → Fields** tab when a container-field row exists for the looked-up EPC; lot number deep-links to View Lot.
