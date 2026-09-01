# Deploy runbook — Universal Email + Client Portal outbound

## Prerequisites

- Code deployed to target env (`tracepharma-stage`, `tracepharma-prod`, or demo `/dpool/tracepharma`)
- App user can run artisan as `www-data` (stage/prod)

## 1. Migrate tenant DBs

```bash
# Stage
cd /var/www/html/tracepharma-stage && sudo -u www-data php artisan tenants:migrate --force

# Prod
cd /var/www/html/tracepharma-prod && sudo -u www-data php artisan tenants:migrate --force

# Demo / source
cd /dpool/tracepharma && php artisan tenants:migrate --force
```

Migrations of note:
- `2026_09_01_030800_add_system_flags_to_outbound_connections`
- `2026_09_01_031000_create_portal_organizations_and_users_tables`
- `2026_09_01_031100_create_portal_publications_table`

## 2. Seed system outbound templates

```bash
php artisan tenants:seed-outbound-templates
# optional: --tenants=<uuid>
```

Creates inactive rows:
- `system_key=email_attachment` (Email EPCIS attachment)
- `system_key=client_portal` (Client portal)

Also runs automatically on **new** tenant create via `SeedSystemOutboundTemplates` job.

## 3. Enable Email channel (per tenant)

1. Filament → Outbound Connections → open **Email (EPCIS attachment)**
2. Set To recipients, enable **Active**
3. Pin on a shipment **or** set as partner/global default (Email is never chosen by the B2B→Portal ladder)

## 4. Enable Client portal (per tenant)

1. Set tenant setting `features.client_portal_v2` = `true` (tenant `settings` JSON)
2. Outbound Connections → **Client portal** → Active (+ notify emails optional)
3. Master Data → Customer portal → **Invite to client portal** (partner + email)
4. Buyer opens `https://{tenant-host}/client-portal/login` → OTP email → Shipments / Trace

## 5. Verify

- Ship with no B2B connection + portal active → document `transmission_status=sent`, `portal_publications` row
- Ship with HTTPS/SFTP/AS2 → B2B wins over portal
- Active Email alone does **not** auto-route unless pinned/default
- Integration Health shows last_sent / last_error on connections

## Rollback

1. Set `features.client_portal_v2` = `false` (client portal routes 404)
2. Deactivate system Email / Portal templates
3. Do **not** drop `portal_*` tables in an emergency

B2B HTTPS/SFTP/AS2 remains unaffected.
