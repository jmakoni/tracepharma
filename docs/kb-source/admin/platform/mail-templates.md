# Mail templates

Filament classes:

- `App\Filament\Admin\Resources\MailTemplates\MailTemplateResource`

## When to use

Edit platform email templates used for demos, onboarding, invites, and system notifications.

## Prerequisites

- Admin permission to edit mail templates.
- Awareness of merge variables required by each template.

## Steps

1. Open **Mail templates**. Open the page and use Help for live UI.
2. Select a template; edit subject/body carefully.
3. Preview if available; send a test to an internal mailbox.
4. Publish and verify one real workflow email.

## Related pages

- [../tenants/demo-requests.md](../tenants/demo-requests.md) — demo outreach
- [../tenants/customer-onboarding.md](../tenants/customer-onboarding.md) — onboarding emails
- [admins.md](admins.md) — who can edit
- [activity-log.md](activity-log.md) — audit of template changes

## Notes

- Broken merge tags produce blank or failed emails — test after every change.
- Keep legal/compliance language reviewed before production edits.
