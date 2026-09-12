# GS1 EPCIS Capture + SimpleEventQuery REST — Design Spec (Phase 1)

**Goal:** Offer a GS1-shaped Capture and SimpleEventQuery REST surface on the existing TracePharma canonical store (`epcis_documents` / `epcis_events`), without forking repositories or claiming full GS1 certification.

**Non-goals (Phase 1):** SOAP Query Control Interface; XML EPCIS 2.0 capture (Phase 2); default outbound 2.0 (Phase 3); GS1 subscribe/unsubscribe protocol (Phase 4); vocabulary queries beyond SimpleEventQuery filters listed below.

---

## Locked decisions

| Topic | Decision |
|---|---|
| Store | One spine — Capture reuses `ReceiveEpcisUpload` → process pipeline |
| Auth | Sanctum; capture uses `epcis:upload`; query uses `epcis:view` (no new ability required for MVP; document both in Sanctum labels) |
| Capture formats | JSON-LD 2.0 (when accept_20) and XML 1.2/1.3; reject XML schemaVersion 2.0 until Phase 2 |
| Capture id | `epcis_documents.id` as capture job id; `Location: /api/v1/epcis/capture/{id}` |
| Capture status | Map document status: `received`/`parsing` → `pending`; `parsed`/`validated`/`generated` → `accepted`; `error`/`voided` → `failed` |
| Query | SimpleEventQuery subset: `EQ_bizStep`, `GE_eventTime`, `LE_eventTime`, `MATCH_epc`, `EQ_action`, `perPage`, `nextPageToken` |
| Unknown params | Fail closed 422 `QueryParameterException` |
| Response shape | JSON-LD EPCISQueryDocument-like envelope: `{ "@context", "type": "EPCISQueryDocument", "epcisBody": { "queryResults": { "resultBody": { "eventList": [...] } } } }` |
| SiteAccess | Same as document API — site-restricted users only see events on accessible documents |
| Kill switch | `INBOUND_EPCIS` blocks capture and query |

---

## Endpoints

### `POST /api/v1/epcis/capture`

- Middleware: `auth:sanctum`, `abilities:epcis:upload`
- Body: raw EPCISDocument (JSON or XML 1.2/1.3)
- Success: `202` with `{ captureID, status: "pending" }` and `Location` header
- Errors: 403 (profile/kill), 422 CaptureInvalid, 409 duplicate (existing inbound pattern)

### `GET /api/v1/epcis/capture/{captureId}`

- Middleware: `abilities:epcis:view`
- Returns `{ captureID, status, document_uuid, error_message? }`

### `GET /api/v1/epcis/events`

- Middleware: `abilities:epcis:view`
- Filters as above; cursor pagination via opaque `nextPageToken` (base64 of last event id)
- Default `perPage` 50, max 200

### `GET /api/v1/epcis/events/{eventID}`

- Lookup by `epcis_events.event_id` URN (URL-encoded path). Fallback: numeric primary key when path is digits-only.
- 404 if missing or site denied

---

## Components

| Class | Role |
|---|---|
| `EpcisCaptureController` | Capture POST + status GET |
| `EpcisEventsQueryController` | Events list + show |
| `SimpleEventQuery` | Param → Eloquent builder |
| `CanonicalEventsToJsonLd20::mapEventPublic` / `projectEvents` | Event-level JSON-LD projection |

---

## Success criteria

- Feature tests green for capture JSON-LD, query EQ_bizStep, kill switch 403, unknown param 422
- Existing EPCIS suites remain green
- Marketing: “GS1-shaped Capture + SimpleEventQuery REST (JSON-LD)” only after tests pass
