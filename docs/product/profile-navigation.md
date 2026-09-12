# Profile navigation and capability gating

TracePharma gates Filament App navigation and page access from **tenant profile**, not coarse organization type. Use `TenantFeatures` for every capability check; use `TenantProfile::tenantType()` only for display labels (Organization Settings, IA copy).

## Type vs profile

`TenantType` is a collapsed view of `TenantProfile` for UI. It does **not** drive feature flags.

| Profile (`TenantProfile`) | Type (`TenantType`) | Label |
|---|---|---|
| Pharmacy | Pharmacy | Pharmacy |
| Manufacturer | Distributor | Manufacturer |
| Drug Wholesaler | Distributor | Drug Wholesaler |
| Prepackager | Distributor | Prepackager |
| Logistics (3PL) | ThreePl | 3PL / Logistics |
| Dental / Medical Supply | Distributor | Dental / Medical Supply |
| Buying Group | Buying group | Buying Group |

Resolution path:

```
Tenant (DB) → TenantProfile → TenantFeatures::forTenant($tenant)
                           → TenantProfile::tenantType()  // display only
```

## Capability matrix

Columns: **P** Pharmacy · **M** Manufacturer · **W** Drug Wholesaler · **R** Prepackager · **3** Logistics 3PL · **D** Dental / Medical Supply · **B** Buying Group

All values come from `App\Support\TenantFeatures` `supports*` methods.

| Method | P | M | W | R | 3 | D | B | Notes |
|---|---|---|---|---|---|---|---|---|
| `supportsReceiving()` | ✓ | | ✓ | ✓ | ✓ | ✓ | | |
| `supportsVrs()` | ✓ | | ✓ | ✓ | ✓ | ✓ | | |
| `supportsTransferring()` | ✓ | | ✓ | ✓ | ✓ | ✓ | | |
| `supportsUnpacking()` | | ✓ | ✓ | ✓ | ✓ | ✓ | | |
| `supportsPacking()` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | | |
| `supportsCommissioning()` | | ✓ | | ✓ | | | | **M + R only.** Plant ObjectEvent commission/decommission. Wholesaler, 3PL, and dental do **not** commission. |
| `supportsReturning()` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | | |
| `supportsMasterData()` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | | B excluded |
| `supportsPrincipals()` | | | | | ✓ | | | Soft 3PL principal registry + FK filters only |
| `supportsRepackTransform()` | | | | ✓ | | | | Prepackager TransformationEvent authoring |
| `supportsBuyingGroupNetwork()` | | | | | | | ✓ | BG member roster + network pages/APIs |
| `supportsInboundIntegrations()` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | | B excluded |
| `supportsOutboundIntegrations()` | | ✓ | ✓ | ✓ | ✓ | ✓ | | Pharmacy stays inbound-focused |
| `supportsPharmacyOutboundDesk()` | ✓ | | | | | | | Low-volume pharmacy TI desk only |
| `supportsSsccLabeling()` | | ✓ | ✓ | ✓ | ✓ | ✓ | | Same profiles as outbound integrations |
| `supportsTracingRequests()` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | | B forced off |
| `supportsPartnerReadiness()` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | `supportsMasterData()` **or** Buying Group |
| `supportsComplianceAlertCenter()` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | `supportsComplianceCases()` **or** Buying Group |
| `supportsComplianceReports()` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | | Same as inbound integrations |
| `supportsComplianceCases()` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | | FDA 3911 / quarantine; B excluded |

Related helpers (not static profile columns, or used in nav):

| Method | Notes |
|---|---|
| `supportsManufacturerVerificationPortal()` | **≠ `supportsVrs()`.** Settings-gated (`TenantSettings::manufacturerVerificationPortalEnabled()`). When enabled: Manufacturer **or** any `supportsVrs()` profile. Do **not** gate the portal on `supportsVrs()` alone — that blocked Manufacturer (G-P0-01). |
| `supportsClientPortalV2()` | Settings-gated client portal; not a profile matrix column |
| `supportsTrackAndTraceExport()` | Pharmacy, Manufacturer, distribution-ops profiles |

| Method | P | M | W | R | 3 | D | B |
|---|---|---|---|---|---|---|---|
| `hasAnyOperations()` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | |
| `canAuthorOutboundShipments()` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | |
| `showsWholesaleOperationsNav()` | * | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |

\* Pharmacy only: `false` when tenant setting `pharmacySimplifiedNavEnabled()` is on (`TenantSettings`); otherwise `true`.

### Dental / Medical Supply = wholesaler-lite

Dental / Medical Supply = distributor ops (receive/ship/VRS/pack/SSCC); **no plant commission** (`supportsCommissioning()` is false). Wholesaler-lite — use `isFullWholesalerFamilyProfile()` for future W-only flags so dental does not inherit by copy-paste. Do not map dental → Pharmacy.

### Buying Group = control-plane only

Buying Group is **not** a Distributor floor tenant. Display type is **Buying group**. Capabilities: member roster + ATP readiness + alert center only — no receive/ship floor, master-data CRUD, or inbound integrations.

| Area | Buying Group |
|---|---|
| Floor ops (receive, transfer, unpack, pack, commission, return, VRS) | **Off** |
| `hasAnyOperations()` | **Off** |
| Master data CRUD (`supportsMasterData()`) | **Off** |
| Inbound integrations (`supportsInboundIntegrations()`) | **Off** |
| Compliance cases / 3911 (`supportsComplianceCases()`) | **Off** |
| Partner ATP readiness (`supportsPartnerReadiness()`) | **On** (explicit exception) |
| Compliance alert center shell (`supportsComplianceAlertCenter()`) | **On** (integration/ATP signals without quarantine/3911) |
| Member roster (`supportsBuyingGroupNetwork()`) | **On** (roster CRUD + member health + partner matrix + member compliance APIs) |
| Member health / partner matrix | **On** (snapshot pages; `features.buying_group_member_rollups` default true) |

Buying groups see network control-plane surfaces without operational workflows. Member compliance APIs (`/api/v1/buying-group/*`) are GA for BuyingGroup tenants with ability `buying-group:network`.

## Navigation gating stack

Filament visibility is evaluated in layers:

```
1. TenantProfile
      ↓
2. TenantFeatures::forTenant(tenant())   ← profile capability (supports*, hasAnyOperations)
      ↓
3. Page/Resource::canAccess()            ← often + JobRoleAccess / Spatie permissions
      ↓
4. shouldRegisterNavigation()            ← optional; may further hide sidebar entry
      ↓
5. HidesForPharmacySimplifiedNav trait   ← requires showsWholesaleOperationsNav()
```

**Job roles (opt-in):** When `access.job_roles_enabled` is off, `JobRoleAccess` passes through (capabilities unrestricted). When on, pages also require matching `nav.*` permissions. **Owner** always retains Organization Settings access when `supportsMasterData()` **or** `supportsBuyingGroupNetwork()` is true.

### Reference: key page gates

| Surface | Profile gate | Additional gates |
|---|---|---|
| **ATP readiness** (`AtpPartnerReadiness`) | `supportsPartnerReadiness()` | `JobRoleAccess::allows(NavCompliance)` |
| **Member roster** (`BuyingGroupMemberResource`) | `supportsBuyingGroupNetwork()` | Owner **or** `UsersManage` |
| **Member health** (`MemberNetworkHealth`) | `supportsBuyingGroupNetwork()` + rollups enabled | `allows(NavCompliance)` |
| **Partner matrix** (`AuthorizedPartnerMatrix`) | `supportsBuyingGroupNetwork()` + rollups enabled | `allows(NavCompliance)` |
| **Alert center** (`ComplianceAlertCenter`) | `supportsComplianceAlertCenter()` | `allowsAny(NavExceptions, NavCompliance)` |
| **Organization** (`OrganizationSettings`) | `supportsMasterData()` **or** `supportsBuyingGroupNetwork()` | `JobRoleAccess::canAccessOrganizationSettings()` |
| **Operations Hub** (`OperationsHub`) | `hasAnyOperations()` | `allowsAny(NavReceive, NavShip, NavExceptions, NavVerify)`; nav may use `HidesForPharmacySimplifiedNav` |
| **Analytics** (`Analytics`) | `hasAnyOperations()` **or** `supportsComplianceCases()` | Owner **or** `allowsAny(NavCompliance, NavReceive, NavShip, NavIntegrations)`; nav uses `HidesForPharmacySimplifiedNav` |
| **HQ rollup** (`HqRollup`) | `supportsComplianceCases()` **and** `hasAnyOperations()` | `allows(NavCompliance)` **and** `SitesAccessAll`; nav also requires `showsWholesaleOperationsNav()` |
| **Partner onboarding** (`PartnerOnboardingKitPage`) | `supportsInboundIntegrations()` | `canAccessOrganizationSettings()` |
| **Users** (`UserResource`) | *(none — profile-agnostic)* | `UsersManage` **and** `NavUsers` |

`shouldRegisterNavigation()` on ATP readiness, alert center, partner onboarding, and HQ rollup mirrors `canAccess()` (HQ rollup additionally checks `showsWholesaleOperationsNav()`).

## How to add nav items

1. **Pick the capability** — Add or reuse a `TenantFeatures::supports*()` method (profile `match` only; no `TenantType` checks).
2. **Gate the page/resource** — In `canAccess()`:
   ```php
   public static function canAccess(): bool
   {
       return TenantFeatures::forTenant(tenant())->supportsYourFeature()
           && JobRoleAccess::allows(Permissions::NavSomething);
   }
   ```
3. **Register navigation** — Override `shouldRegisterNavigation()` when sidebar visibility should differ from route access, or when mirroring `canAccess()`.
4. **Pharmacy simplified nav** — For wholesaler-heavy floor surfaces, use `HidesForPharmacySimplifiedNav` so nav respects `showsWholesaleOperationsNav()`.
5. **Document the matrix** — Update this file and `tests/Unit/ProfileNavigationMatrixTest.php` when adding a new `supports*` method.
6. **Do not gate on `TenantType`** — Coarse type is for labels; profiles can diverge within a type (e.g. Buying Group vs Drug Wholesaler, both Distributor).

## Source of truth

- Profiles: `app/Enums/TenantProfile.php`
- Capabilities: `app/Support/TenantFeatures.php`
- Job-role overlay: `app/Support/Auth/JobRoleAccess.php`
- Pharmacy nav trim: `app/Support/Auth/HidesForPharmacySimplifiedNav.php`
- Contract test: `tests/Unit/ProfileNavigationMatrixTest.php`
