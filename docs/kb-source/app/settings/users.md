# Users

Filament classes:

- `App\Filament\App\Resources\Users\UserResource`
- `App\Filament\App\Resources\Roles\RoleResource`

## When to use

Invite, edit, and deactivate tenant users; assign roles and site access for Filament App. Use **Roles** to override the seeded capability bundle for a job role (then assign that role on Users).

## Prerequisites

- Tenant admin or user-management permission.
- Intended role/permission set agreed with compliance (SoD).

## Steps

1. Open **Users**. Open the page and use Help for live UI.
2. Create a user with email, role, and site scope.
3. Edit roles when job functions change; avoid over-privileged accounts.
4. Optionally open **Roles** (Settings) to change which capabilities a persona grants, or **Reset to defaults** to restore the catalog map.
5. Deactivate leavers promptly; do not reuse personal accounts.

## Related pages

- [settings-hub.md](settings-hub.md) — organization defaults
- [onboarding.md](onboarding.md) — guided getting started
- [../exceptions/investigator-sla.md](../exceptions/investigator-sla.md) — investigator workload
- [../master-data/customer-portal.md](../master-data/customer-portal.md) — external portal (not Filament users)

## Notes

- Mass decommission SoD requires a second user with the mass-approve permission — plan roles accordingly.
- Platform admins are managed in the Admin panel, not here.
- Users assigns personas; Roles edits the permission bundles behind those personas.
