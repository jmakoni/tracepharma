# Directories QA checklist (demo2)

Login: `owner@demo.test` — confirmed Dashboard → Operations Hub `/operations-hub`.

## Visible Directories (19)

| # | Title | Path | Status |
|---|-------|------|--------|
| 1 | Receive | /receiving-sessions | pass (list/search; no open sessions to view) |
| 2 | Unpacking | /unpack-workstation | pass (smoke) |
| 3 | Unpacked items | /unpacked-items | pass (smoke) |
| 4 | Packing | /pack-workstation | pass (smoke) |
| 5 | Break & pack | /break-pack-workstation | pass (smoke) |
| 6 | Return | /return-workstation | pass (smoke) |
| 7 | Transfer | /transferring-sessions | pass (list) |
| 8 | Asset Tracking | /asset-tracking | pass (smoke) |
| 9 | Verify product | /verify-product | pass (page title: Dispense / verify) |
| 10 | Integration health | /integration-health | pass |
| 11 | Analytics | /analytics | pass |
| 12 | Inbound EPCIS | /inbound-epcis | pass |
| 13 | Inbound Connections | /inbound-connections | pass (view AS2 connection) |
| 14 | API Tokens | /api-tokens | pass (list only; no token create/revoke) |
| 15 | Find / Recall | /inbound-epcis?action=findRecall | pass after fix (see Bugs) |
| 16 | Trading Partners | /trading-partners | pass (search + view Cardinal Health) |
| 17 | FDA Products | /fda-products | pass (search) |
| 18 | Product directory | /products | pass (search) |
| 19 | Site directory | /sites | pass (create **QA Cursor Demo Site**) |

## Not shown (expected feature gates)

- Commission-all, Decommission (no commissioning)
- Ship Order, Outbound EPCIS (no outbound integrations on this tenant profile)

## Bugs fixed (source)

1. **Find / Recall Hub deep link** — `?findRecall=1` called `mountAction` in `mount()` before header actions were cached → silent no-op. Hub/AssetTracking/scan now use `?action=findRecall`. Legacy `?findRecall=1` sets `$defaultAction` for `wire:init`.
2. **Regulatory Confirm before form validation** — `CreateRecord` / `EditRecord` now `mountUsing` → `$this->form->validate()` before password modal.

## Known non-blocking

- Console 404: `/css/app/filament/sticky-table-header.css` (theme already `@import`s vendor CSS; separate asset URL missing on demo2 deploy).
- Demo announcement “Duplicate guard — Run twice” is not an app crash.
- Sites list ~3–6s TTI; table already eager-loads `tradingPartner`, `atpLicenses`, `principal` — no index change without query proof.

## Screenshots

See [directories-qa-storyboard.md](../../directories-qa-storyboard.md) and files in this folder.
