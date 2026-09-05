# Labeling and SSCC

Filament classes:

- `App\Filament\App\Resources\LabelPrinters\LabelPrinterResource`
- `App\Filament\App\Resources\SsccNumberRanges\SsccNumberRangeResource`
- `App\Filament\App\Resources\SsccLabels\SsccLabelResource`

## When to use

Register label printers, allocate SSCC number ranges, and review printed SSCC label batches for commissioning / shipping.

## Prerequisites

- GS1 company prefix and extension digit strategy defined.
- Printer network reachability from the app/worker hosts.

## Steps

1. Open **Label printers**; add host/queue details. Open the page and use Help for live UI.
2. Create **SSCC number ranges** with capacity and assignment rules.
3. Use **SSCC labels** to review batches and print status.
4. Smoke-test a commission or ship label before production cutover.

## Related pages

- [../master-data/sites-and-devices.md](../master-data/sites-and-devices.md) — devices and sites
- [../master-data/products.md](../master-data/products.md) — products/principals for labels
- [../compliance/l3-forward-log.md](../compliance/l3-forward-log.md) — L3 label/forward audits
- [settings-hub.md](settings-hub.md) — settings entry

## Notes

- Exhausted SSCC ranges block commissioning — monitor remaining capacity.
- Never reuse SSCCs across tenants or ranges.
