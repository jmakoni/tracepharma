# Floor camera scan pace — design

Date: 2026-09-17  
Status: accepted  
Related research: Camera Scan Pace Research plan (c9bcb022)

## Problem

Floor operators share devices and differ in how fast they want continuous camera decode. Today [`tp-floor-receive.js`](../../../public/js/tp-floor-receive.js) hardcodes **fps 24** and a **1000 ms** post-scan cool-down for everyone.

## Decision

Ship **per-user named presets** (Careful / Balanced / Rapid) that adjust **cool-down + decode fps/frameRate together**. Control lives only on the **floor camera overlay**. Persist in `users.preferences` (no migration).

Balanced equals today’s hardcoded values so unset users see no behavior change.

## Presets

| Pace | Value | Cool-down | fps | frameRate ideal / max |
|------|-------|-----------|-----|------------------------|
| Careful | `careful` | 1800 ms | 12 | 12 / 15 |
| Balanced (default) | `balanced` | 1000 ms | 24 | 24 / 30 |
| Rapid | `rapid` | 450 ms | 30 | 30 / 30 |

Mode stays **continuous** for all three (camera remains open between labels).

## Copy / a11y

- Overlay label group: **Pace** (`role="group"` `aria-label="Camera scan pace"`)
- Chip labels: **Careful**, **Balanced**, **Rapid**
- Selected chip: `aria-pressed="true"`; others `false`
- Macro targets ≥48px height (match Close button)
- Changing pace announces via existing chip state (no toast required in v1)

## Storage

- Key: `preferences.floor.camera_scan_pace` ∈ `careful|balanced|rapid`
- `User::floorCameraScanPace(): FloorCameraScanPace`
- `User::setFloorCameraScanPace(FloorCameraScanPace $pace): void`
- Missing / invalid → `Balanced`

## API

```php
enum FloorCameraScanPace: string {
    case Careful = 'careful';
    case Balanced = 'balanced';
    case Rapid = 'rapid';

    public function label(): string;
    /** @return array{cameraScanPace: string, cooldownMs: int, fps: int, frameRateIdeal: int, frameRateMax: int} */
    public function toAlpineConfig(): array;
}
```

Livewire (shared trait on floor Mobile pages):

```php
public function setFloorCameraScanPace(string $pace): array
```

Returns `toAlpineConfig()` after persist. Alpine applies cool-down immediately; restarts camera only when fps/frameRate change.

## UI surface

Camera overlay bar on all floor HUDs that load `tpFloorReceive`:

- `mobile-view-receiving-session`
- `mobile-view-outbound-shipping-session`
- `mobile-view-transferring-session`
- `mobile-pack-workstation`
- `mobile-unpack-workstation`
- `mobile-break-pack-workstation`
- `mobile-verify-product`

Shared partial for the Pace chips; Alpine init merges `FloorCameraScanPace::forUser(...)->toAlpineConfig()` with `libraryUrl`.

## Non-goals

- Tenant / Organization Settings
- Desk `scan-field` camera
- Raw ms / FPS sliders
- localStorage-only prefs
- Torch / resolution / facingMode UI
- Fixing camera Livewire method name mismatch on pack/ship/verify (separate)

## Tests

1. Unit: enum resolver table + User preference round-trip / invalid fallback
2. Feature: Livewire `setFloorCameraScanPace` on `MobileViewReceivingSession` persists and returns Alpine config
