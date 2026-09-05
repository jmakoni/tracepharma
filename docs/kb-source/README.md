# Knowledge base source (author here)

Markdown authored for Guava Filament Help panels. Published by:

```bash
php scripts/sync-knowledge-base-docs.php
```

| Path | Panel |
|------|--------|
| `app/{group}/*.md` | Tenant `/help` → `docs/knowledge-base/en/{group}/` |
| `admin/{group}/*.md` | Admin `/help` → `docs/admin-knowledge-base/en/{group}/` |

Floor SOP screenshots still live under [`docs/workflows/`](../workflows/) and sync into `workflows/`.

Do not put `KnowledgeBasePlugin` on the main App/Admin panels — only Companion + dedicated help panels.
