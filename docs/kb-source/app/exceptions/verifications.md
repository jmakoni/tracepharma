# Verifications and VRS directory

Filament classes:

- `App\Filament\App\Resources\Verifications\VerificationResource`
- `App\Filament\App\Pages\VrsLookupDirectory`

## When to use

Review product verification history and look up VRS / verification endpoints for trading partners.

## Prerequisites

- Verification features enabled for the tenant profile.
- EPC or GTIN context for lookups.

## Steps

1. Open **Verifications** to list historical checks. Open the page and use Help for live UI.
2. Open a verification for request/response detail and disposition.
3. Use **VRS lookup directory** to find partner verification endpoints.
4. Escalate suspect results to quarantine / exceptions / 3911 as required.

## Related pages

- [exceptions.md](exceptions.md) — investigation records
- [../compliance/quarantine.md](../compliance/quarantine.md) — hold suspect product
- [../compliance/tracing-and-fda3911.md](../compliance/tracing-and-fda3911.md) — tracing and Form 3911
- [../master-data/trading-partners.md](../master-data/trading-partners.md) — partner master

## Notes

- Directory entries can lag partner changes — confirm with the partner before production cutover.
- Failed VRS connectivity is an integration issue as much as a product issue.
