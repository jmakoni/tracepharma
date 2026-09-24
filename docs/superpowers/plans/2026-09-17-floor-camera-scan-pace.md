# Floor Camera Scan Pace Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Per-user Careful/Balanced/Rapid camera scan pace on floor mobile camera overlays only.

**Architecture:** `FloorCameraScanPace` enum owns numeric mapping; `User.preferences.floor.camera_scan_pace` persists; shared Livewire trait + Blade partial feed `tpFloorReceive` Alpine config; JS reads cool-down/fps from config and can update via `$wire.setFloorCameraScanPace`.

**Tech Stack:** Laravel 13, Filament 5 Livewire, Alpine, html5-qrcode, Pest/PHPUnit.

## Global Constraints

- Floor mobile pages only — do not change desk `scan-field` or Organization Settings.
- Balanced = current hardcoded values (1000 ms, fps 24, frameRate 24/30).
- No new DB migration (`users.preferences` JSON already exists).
- Source-first: edit only `/dpool/tracepharma`.

---

### Task 1: Enum + User preference API

**Files:**
- Create: `app/Enums/FloorCameraScanPace.php`
- Modify: `app/Models/User.php`
- Test: `tests/Unit/Enums/FloorCameraScanPaceTest.php`, `tests/Unit/Models/UserFloorCameraScanPaceTest.php`

**Interfaces:**
- Produces: `FloorCameraScanPace::tryFromUserValue(?string): self`, `toAlpineConfig(): array`, `User::floorCameraScanPace()`, `User::setFloorCameraScanPace()`

- [ ] Write failing unit tests for preset table + User round-trip / invalid → Balanced
- [ ] Implement enum + User methods
- [ ] Run unit tests — expect pass

### Task 2: Livewire trait

**Files:**
- Create: `app/Filament/App/Concerns/SetsFloorCameraScanPace.php`
- Modify: floor Mobile page classes (receiving/shipping/transferring + pack/unpack/break-pack/verify)
- Test: Feature on `MobileViewReceivingSession`

**Interfaces:**
- Produces: `setFloorCameraScanPace(string $pace): array`

- [ ] Write failing Livewire test
- [ ] Implement trait; use on Mobile pages
- [ ] Run feature test — expect pass

### Task 3: Alpine + overlay UI

**Files:**
- Modify: `public/js/tp-floor-receive.js`
- Create: `resources/views/filament/app/partials/floor-camera-scan-pace.blade.php`
- Modify: all seven mobile floor blades (x-data config + overlay include)
- Modify: `resources/css/filament/app/theme.css` (pace chip styles)

**Interfaces:**
- Consumes: Alpine config keys from enum
- Produces: `setScanPace(pace)` on Alpine component

- [ ] Plumb config into JS start/cooldown
- [ ] Add overlay Pace chips partial + CSS
- [ ] Wire all floor blades
- [ ] Smoke: unit/feature still green

### Task 4: Verify

- [ ] Run enum + User + Livewire tests
- [ ] Manual checklist: open floor receive camera → switch Rapid → scan twice quickly; reopen page → Rapid selected
