# FDA DECRS file format

Source: `https://www.accessdata.fda.gov/cder/drls_reg.zip` (`FDA_DECRS_URL`).

Akamai on `accessdata.fda.gov` returns an HTML apology page (HTTP 302 → 404) for Chrome-like User-Agents. TracePharma downloads with `Mozilla/5.0 (compatible; TracePharma/1.0; +https://tracepharma.test)`, the same UA as the WDD TSV importer. Validate ZIP magic `PK\x03\x04` and a `drls_reg.txt` header containing `FEI_NUMBER`.

## Zip members

| Member | Use |
|---|---|
| `drls_reg.txt` | Parsed (tab-delimited, CRLF) |
| `drls_reg.xls` | Ignored in v1 |

Cached under `storage/app/fda/decrs/decrs-YYYY-MM-DD.zip`.

## `drls_reg.txt` columns

Trim headers and cells. The live file has a leading space on `FEI_NUMBER` and a trailing tab on data rows.

| Column | Notes |
|---|---|
| `FEI_NUMBER` | Unique establishment key. Blank FEI rows are skipped. |
| `DUNS_NUMBER` | Establishment DUNS |
| `FIRM_NAME` | Establishment / facility name |
| `ADDRESS` | One concatenated string ending in `(ISO3)`. US: `{street}, {city}, {StateName} ({ST}) {ZIP}, United States (USA)` |
| `EXPIRATION_DATE` | `MM/DD/YYYY` |
| `OPERATIONS` | Semicolon-separated codes (`MANUFACTURE`, `ANALYSIS`, `API MANUFACTURE`, …) |
| `ESTABLISHMENT_CONTACT_NAME` / `_EMAIL` | Stored nullable |
| `AGENT_DETAILS` | US-agent blob |
| `REGISTRANT_NAME` / `REGISTRANT_DUNS` | Legal entity for `fda_organizations`. **Identity is `REGISTRANT_DUNS`**: same DUNS → same org; different DUNS → separate orgs even when names are similar. Blank registrant DUNS falls back to exact canonical name only (no fuzzy/prefix merge). Establishment `DUNS_NUMBER` is not used for org identity. |
| `REGISTRANT_CONTACT_NAME` / `_EMAIL` | Stored nullable |
| `EXCLUSION_FLAG` | `Y` / `N` in the file. Stored as TINYINT: `Y` = 1 (excluded). |

`is_currently_registered` is set in PHP on import: not excluded AND (`expiration_date` is null or ≥ today). Recalculate later with `tracepharma:recalc-fda-establishment-registration`.

Import: `php artisan tracepharma:import-fda-decrs` or `--path=` for a local zip/txt.
