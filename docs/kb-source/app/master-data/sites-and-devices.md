# Sites and devices

Filament classes:

- `App\Filament\App\Resources\Sites\SiteResource`
- `App\Filament\App\Resources\Devices\DeviceResource`
- `App\Filament\App\Resources\LocationDevices\LocationDeviceResource`
- `App\Filament\App\Resources\ReadPoints\ReadPointResource`

## When to use

Configure physical sites (GLN / SGLN), scanners and printers as devices, location–device bindings, and EPCIS read points.

## Prerequisites

- Site GLNs and layout known.
- Device IDs / printer hosts available from IT.

## Steps

1. Open **Sites**; create or edit site master and ATP licenses. Open the page and use Help for live UI.
2. Register **Devices** and bind them via **Location devices**.
3. Maintain **Read points** for scan locations used in events.
4. Confirm site selection works on receiving, shipping, and quarantine desks.

## Related pages

- [../compliance/atp-readiness.md](../compliance/atp-readiness.md) — site ATP licenses
- [../settings/labeling.md](../settings/labeling.md) — label printers and SSCC
- [../operations/on-hand-and-unpacked.md](../operations/on-hand-and-unpacked.md) — site inventory views
- [trading-partners.md](trading-partners.md) — partner locations vs owned sites

## Notes

- Wrong SGLN on a site corrupts event location data — verify before go-live.
- Devices are not interchangeable with read points; both may be required depending on scanner setup.
