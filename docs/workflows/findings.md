# Findings from workflow capture

Incidental bugs, env blockers, and UX improvements noticed while building this knowledge base. Separated from product defects.

## Product bugs

N/A — none observed during workflow doc capture.

## Environment / demo blockers

- **pharmacy-outbound** and **repack-transform** return **403** on Drug Wholesaler demo2 (expected profile gating — Pharmacy outbound requires `TenantProfile::Pharmacy`; Repack transform requires `TenantProfile::Prepackager`).
- **Legal documents banner** may appear on dashboard until owners accept current Terms/Privacy (`/accept-legal-documents`). Viewing some document pages redirected to accept until completed.
- Demo inbound EPCIS table may be empty depending on seed state; outbound EPCIS had sample documents during capture (`/outbound-epcis/{id}`).
- **Outbound transmit skipped/failed on demo** when the org kill-switch or outbound EPCIS is disabled — UI message: *Outbound EPCIS is disabled for this organization* (observed on completed ship session 1101).
- **Outbound transmit failed** against placeholder hosts (`partner.example` — cURL DNS error) while document stays `validated` with Events showing `shipping` / `in_transit` (e.g. `#12285`).
- **Demo has many test sites** — on-hand inventory requires site selection in the topbar chooser; the EPC table may appear empty until a site is picked and filters applied.
- **No open ship orders** were available during mid-flow outbound capture; **completed session 1101** was used for session detail and EPCIS screenshots instead.

## UX / docs improvements

- **Suggest:** clarify copy when a **completed ship order has 0 confirmed scans but EPCIS was authored** — common on corrective/demo sessions (session 1101); current wording can read as contradictory to operators.
- **Suggest:** show **biz_step label** on workstation success toasts (Pack, Unpack, Commission, Decommission, etc.) so operators confirm CBV without opening EPCIS documents.
- **Suggest:** add **Operations Hub** link to [workflows README](README.md) from the hub page footer or directory card.
- **Suggest:** on **Asset tracking**, call out **CBV local name vs URN** in the timeline legend (e.g. `receiving` ≡ `urn:epcglobal:cbv:bizstep:receiving`).
- **Suggest:** surface **Inbound EPCIS** / **Outbound EPCIS** deep links from workstation success notifications.