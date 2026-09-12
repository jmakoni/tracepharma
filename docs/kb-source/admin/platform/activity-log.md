# Platform activity log

Filament classes:

- `App\Filament\Admin\Resources\ActivityLogs\ActivityLogResource`

## When to use

Audit platform-level actions: admin changes, tenant operations, registry edits, and other Admin panel events.

## Prerequisites

- Admin permission to view platform activity logs.

## Steps

1. Open **Activity log** in the Admin panel. Open the page and use Help for live UI.
2. Filter by admin user, subject, or date.
3. Open entries for detail when investigating incidents.
4. Retain exports per your platform retention policy.

## Related pages

- [admins.md](admins.md) — admin accounts
- [../tenants/tenants.md](../tenants/tenants.md) — tenant-affecting actions
- [analytics.md](analytics.md) — activity volume metrics
- [../../app/operations/activity-log.md](../../app/operations/activity-log.md) — tenant app activity log

## Notes

- Admin and App activity logs are separate resources in different panels.
- Impersonation and compliance archive exports should always leave clear audit rows.
