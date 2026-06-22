---
name: hew-sync-check
description: Use when asked to check schema/migration drift in a Laravel project, verify hew sync status, audit schema.php completeness, or investigate why hew:sync would generate unexpected migrations.
---

# hew-sync-check

Check the sync state between `database/schema.php` and existing migrations in a Laravel project using [hew](https://github.com/gboquizosanchez/hew).

## Detection (run first)

```bash
# Is hew present?
grep -q "gboquizosanchez/hew" composer.json && echo "hew installed" || echo "not a hew project"

# Does schema.php exist?
ls database/schema.php 2>/dev/null || echo "schema.php missing"
```

If either check fails, stop — nothing to sync.

## Run the diff

```bash
php artisan hew:diff
```

Exit code 0 = clean. Exit code 1 = changes pending (normal, not an error).

## Interpret the output

| Prefix | Meaning | Migration generated |
|--------|---------|---------------------|
| `New table` | table in schema, not in migrations | `create_{table}_table` |
| `New columns` | columns in schema, not in migrations | `add_columns_to_{table}` |
| `Drop table` | table in migrations, absent from schema | `drop_{table}_table` |
| `Drop columns` | columns in migrations, absent from schema | `drop_columns_from_{table}` |
| `Modify columns` | type changed in schema | `modify_columns_in_{table}` |
| `~` warnings | needs manual intervention | none generated |

Destructive migrations (`drop_*`, `modify_*`) have `throw RuntimeException` in `down()` — they are intentionally irreversible.

## Common gotchas

**Third-party package tables** (Spatie Health, Telescope, Horizon, etc.) exist in migrations but not in `schema.php`. They will show up as `Drop table` candidates. Do NOT add these to schema.php or run `hew:sync` on them — exclude by ignoring those entries in the diff output.

**Empty table definition in schema.php** — if a table's `->columns([])` is empty but the migration has columns, all existing columns appear as `Drop columns`. Fill in the schema.php columns before running sync.

**`DB::statement` migrations** — hew marks these as unparseable. Column changes made via raw SQL won't be reflected in the diff; treat those tables as manually managed.

## Generate migrations

```bash
# Preview only
php artisan hew:diff

# Generate (prompts for confirmation)
php artisan hew:sync

# Generate without prompt
php artisan hew:sync --force
```

## Reporting to the user

Report grouped by severity:
1. **Destructive** (red) — dropped tables/columns, type changes
2. **Additive** (green) — new tables/columns
3. **Untracked** — third-party tables appearing as drop candidates (inform, don't act)
4. **Warnings** — anything needing manual work
