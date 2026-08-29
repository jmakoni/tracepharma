---
title: Admins
parent: platform
order: 25
group: Settings
---

# Admins

Filament classes:

- `App\Filament\Admin\Resources\Admins\AdminResource`

## When to use

Manage platform administrator accounts for the Admin panel (not tenant Filament users).

## Prerequisites

- Super-admin or equivalent permission to manage admins.
- Strong authentication policy for platform accounts.

## Steps

1. Open **Admins**. Open the page and use Help for live UI.
2. Create an admin with least privilege for their role.
3. Edit or deactivate when staff change.
4. Confirm they can reach only intended resources.

## Related pages

- [activity-log.md](../platform/activity-log) — admin action audit
- [../tenants/tenants.md](../tenants/tenants) — tenant impersonation is powerful
- [mail-templates.md](../platform/mail-templates) — notification templates
- [../../app/settings/users.md](../../app/settings/users.md) — tenant users (separate)

## Notes

- Platform admins can affect all tenants — treat as privileged access.
- Prefer named accounts over shared logins.
