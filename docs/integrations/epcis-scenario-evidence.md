# EPCIS scenario evidence export

> **Honesty:** This is **internal** DSCSA / GS1 US IG scenario evidence produced by TracePharma’s fixture matrix and validation engine.
>
> It is **NOT TraceReady / Gateway Checker / GS1 Trustmark certified.**
> Do not present these reports as GS1 Trustmark, TraceReady, or Gateway Checker certification.

## What it does

Runs a curated set of existing EPCIS fixtures under a tenant through the live ingest/validate path (`IngestEpcisXmlDocument`), compares each result to the expected pass/fail outcome, and writes:

- Markdown summary (human-readable, with honesty banner)
- JUnit XML (CI-friendly)

Default output directory: `storage/app/evidence/epcis-scenarios`

## How to run

```bash
php artisan epcis:export-scenario-evidence --tenant={tenant-id}
```

Options:

| Option | Default | Description |
|--------|---------|-------------|
| `--tenant=` | *(required)* | Stancl tenant id |
| `--output=` | `storage/app/evidence/epcis-scenarios` | Directory for report files |
| `--format=` | `all` | `md`, `junit`, or `all` |

Exit code is **FAILURE** if any scenario’s actual outcome does not match its expected outcome (including missing fixtures). Exit **SUCCESS** only when every expected outcome matches.

## Scenario matrix (Wave 2)

| Id | Fixture | Expect |
|----|---------|--------|
| `rx-r12-minimal-pack` | `tests/Fixtures/epcis/minimal_object_shipping.xml` | pass |
| `rx-r12-missing-locations` | `tests/Fixtures/epcis/commissioning_sscc_missing_locations.xml` | fail |
| `rx-schema-1-3-pack` | `tests/Fixtures/epcis/minimal_object_shipping_1.3.xml` | pass |
| `rx-r12-shipping-masterdata` | `tests/Fixtures/epcis/minimal_with_shipping_refs.xml` | pass |
| `rx-r12-3pl-four-party` | `tests/Fixtures/epcis/shipping_3pl_four_party.xml` | pass |

Matrix definition: `app/Support/Epcis/Conformance/ScenarioMatrix.php`.

## Talk-track

Use these exports for mid-market RFP diligence (“we run GS1 US Rx–aligned internal scenario packs and can show pass/fail evidence”). Do **not** claim third-party certification product status.

Related Wave 2 trust pack: [Partner ingest quality rollup](partner-ingest-quality.md) (not clean-data certified / not TraceReady).
