# Guava knowledge base (published tree)

Published markdown for the tenant Filament panel id `knowledge-base` (`/help` on tenant domains).

**Do not edit these files by hand for long-lived content.** Author under:

| Source | Publishes to |
|--------|----------------|
| [`docs/workflows/`](../workflows/) | `en/workflows/` (+ `cbv-biz-steps`, `findings`) |
| [`docs/kb-source/app/`](../kb-source/app/) | `en/{compliance,integrations,master-data,settings,exceptions,operations}/` |

```bash
php scripts/sync-knowledge-base-docs.php
```

Admin published tree: [`docs/admin-knowledge-base/`](../admin-knowledge-base/) from `docs/kb-source/admin/`.

## Groups

- **Welcome / CBV / findings** — locale root
- **Operator workflows** — receive, ship, pack, disposition, verify
- **Exceptions and EPCIS** — exception cases, inbound EPCIS, VRS
- **Compliance** — quarantine, recall, inspection, reports
- **Integrations** — health, connections, tokens, packs
- **Master data** — products, partners, sites
- **Settings** — hub, users, labeling, onboarding
- **Operations extras** — on-hand, jobs, outbound EPCIS, activity log
