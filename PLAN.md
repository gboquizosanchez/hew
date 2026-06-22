# hew — Implementation Plan

## Project context

- `hew` — Laravel schema-as-code library at `/home/cheke/sites/hew`
- Test project: `/home/cheke/sites/scorefest` (vendor at `vendor/gboquizosanchez/hew`)
- Target project: `/home/cheke/sites/loyalty-platform` (358 migrations, 2018–2025)
- PHP 8.1 target
- Vendor sync pattern: `cp src/**/*.php scorefest/vendor/gboquizosanchez/hew/src/**/*.php`

## Key architecture

- `Column` — thin static factory class (returns `ColumnDef` instances)
- `ColumnDef` — data class with all properties + instance modifiers (src/Schema/ColumnDef.php)
- `Table` — schema table definition (src/Schema/Table.php)
- `MigrationParser` — parses migration files → `ParsedTable[]` (src/Parser/MigrationParser.php)
- `SchemaRenderer` — converts `ParsedTable[]` → schema.php text (src/Generator/SchemaRenderer.php)
- `MigrationGenerator` — converts `Table`/`ColumnDef` → migration PHP (src/Generator/MigrationGenerator.php)
- `SchemaDiff` — compares desired schema vs parsed migrations (src/Diff/SchemaDiff.php)

## Phase 1 — COMPLETE ✅

- [x] `bigIncrements` → `Column::id()` in renderer
- [x] `bigInteger` → `Column::integer()->big()` in renderer + generator
- [x] `morphs` factory + `->uuid()` / `->morphs()` instance modifiers on ColumnDef
- [x] `nullableMorphs` → `Column::morphs()->nullable()` in renderer
- [x] `uuidMorphs` → `Column::uuid()->morphs()` in renderer
- [x] Class constants resolved: `const TABLE = 'foo'` + `self::TABLE` → `'foo'`
- [x] Variable table names resolved: `$table = 'foo'` in Schema::create/table calls
- [x] Filename fallback: `create_users_table.php` → `users` for unresolved names
- [x] `Column`/`ColumnDef` split to allow `->uuid()` and `->morphs()` as instance modifiers
- [ ] Tests for morphs (pending — add before or after Phase 2)

## Phase 2 — Old FK style (COMPLETE ✅)

Many loyalty-platform migrations use the old separate FK declaration:

```php
$table->unsignedBigInteger('recruiter_id')->index()->nullable();
$table->foreign('recruiter_id')->references('id')->on('users');
```

**Task**: Post-process pass in `MigrationParser::processUpBody()` after the column loop.

### Implementation plan

In `MigrationParser::processUpBody()`, after parsing all columns:

1. Regex to find `->foreign('col')->references('id')->on('table')` lines:
   ```
   /\$\w+\s*->\s*foreign\s*\(\s*['"]([^'"]+)['"]\s*\)\s*->\s*references\s*\(\s*['"][^'"]*['"]\s*\)\s*->\s*on\s*\(\s*['"]([^'"]+)['"]\s*\)/
   ```
2. For each match: find the already-parsed column by name in `$this->tables[$tableName]->columns`
3. Set `modifiers['references'] = $tableName` and extract `onDelete` from tail if present
4. Also handle `->onDelete('cascade')` style (old style) vs `->cascadeOnDelete()` (new style)

**Old onDelete patterns to handle:**
- `->onDelete('cascade')` → `modifiers['onDelete'] = 'cascade'`
- `->onDelete('set null')` → `modifiers['onDelete'] = 'null'`
- `->onDelete('restrict')` → `modifiers['onDelete'] = 'restrict'`

**Edge cases:**
- FK may be on a separate line from column definition
- Column may already have `unsigned` modifier from `unsignedBigInteger` type

## Phase 3 — Alter migrations (COMPLETE ✅)

### Overview

Currently `hew:import` only reads `Schema::create()`. Must also process `Schema::table()` to add/remove/modify columns, building final state per table.

### Implementation plan

**Step 1: Sort all migrations by timestamp** (already done via `sort($files)` in parser)

**Step 2: Parse `Schema::table()` blocks**

In `MigrationParser::parseFile()`, detect `Schema::table('name', ...)` and dispatch to new `processAlterBody()`.

**Step 3: `processAlterBody(string $tableName, string $body, string $filename)`**

Handle these operations:

| Pattern | Action |
|---------|--------|
| New column definition (no `->change()`) | `addColumn()` to existing table |
| `->dropColumn('name')` or `->dropColumn(['a','b'])` | Remove from `$this->tables[$tableName]->columns` |
| `->renameColumn('old', 'new')` | Rename key in columns array |
| `colDef->change()` | Replace existing column definition |
| `->index()` / `->unique()` on existing col | Update modifiers on existing column |
| Index-only migrations (no col changes) | Skip silently |

**Step 4: Handle `Schema::drop`/`dropIfExists` in `up()`**

If migration calls `Schema::dropIfExists('table')` in `up()` (not just `down()`), remove table from `$this->tables`.

**Step 5: `->change()` detection**

In `extractModifiers()`, detect `->change()` at end of chain. Return a flag `['change' => true]` in modifiers. In `processAlterBody()`, if `change` flag set → replace existing column, don't add new one.

**Step 6: `->after('col')` ordering**

When adding a column with `->after('existing')` modifier: insert after the named column in the columns array (preserves column order for schema.php readability).

### ParsedTable changes needed

`ParsedTable::columns` is currently `array<string, ParsedColumn>`. Need:
- `removeColumn(string $name): void`
- `renameColumn(string $old, string $new): void`
- `replaceColumn(string $name, ParsedColumn $col): void`
- `insertAfter(string $after, ParsedColumn $col): void`

## Phase 4 — Edge cases (COMPLETE ✅)

- [x] `softDeletes` → `Column::softDeletes()` factory + renderer arm + generator arm
- [x] `Schema::drop`/`dropIfExists` in `up()` → remove table from accumulated state
- [x] Data-only migrations (no Schema:: calls) → already skipped silently ✅
- [x] Index-only migrations → silently skipped ✅

## Phase 5 — Tests + validation (COMPLETE ✅)

- [x] Tests for morphs round-trip (renderer + generator)
- [x] Tests for old FK style (Phase 2)
- [x] Tests for alter migrations: add column, drop column, rename column, change column
- [x] Run `hew:import` on loyalty-platform: 254 tables, 1966 columns, 0 TODOs, valid PHP
- [x] Fixed remaining unsupported types: `float` + `double` → `Column::float()`
- [x] 6 unparseable files all use `DB::statement` (raw SQL enum changes — expected)

## Current state of loyalty-platform migration survey

- 358 migrations total (2018–2025)
- 189 `Schema::create`, 271 with `Schema::table` (alter)
- 440 `self::TABLE` / const usages → Phase 1 fix ✅
- 161 purely alter migrations → Phase 3
- 135 `dropColumn` usages → Phase 3
- 51 `->change()` usages → Phase 3
- 27 `renameColumn` usages → Phase 3
- 14 morphs usages → Phase 1 fix ✅
- Old FK style (`->foreign()->references()->on()`) throughout → Phase 2
- `bigIncrements` in every old table → Phase 1 fix ✅

## Key file locations

```
src/Schema/Column.php          — static factory (thin)
src/Schema/ColumnDef.php       — data class + instance modifiers (NEW)
src/Schema/Table.php           — table definition
src/Parser/MigrationParser.php — parses .php migration files
src/Parser/ParsedTable.php     — mutable table state during parsing
src/Parser/ParsedColumn.php    — column data from parser
src/Generator/SchemaRenderer.php  — ParsedTable[] → schema.php
src/Generator/MigrationGenerator.php — ColumnDef/Table → migration PHP
src/Diff/SchemaDiff.php        — schema vs current migrations diff
tests/Unit/ColumnTest.php
tests/Unit/TableTest.php
tests/Unit/MigrationGeneratorTest.php
tests/Unit/SchemaRendererTest.php
```

## API reference (current)

```php
// Factories (Column::)
Column::id(string $name = 'id'): ColumnDef
Column::string(string $name, int $length = 0): ColumnDef
Column::text(string $name): ColumnDef
Column::integer(string $name): ColumnDef
Column::bigInteger(string $name): ColumnDef        // integer()->big()
Column::decimal(string $name, int $p, int $s): ColumnDef
Column::boolean(string $name): ColumnDef
Column::json(string $name): ColumnDef
Column::timestamp(string $name): ColumnDef
Column::timestamps(): ColumnDef                    // shortcut
Column::uuid(string $name): ColumnDef
Column::ulid(string $name): ColumnDef
Column::date(string $name): ColumnDef
Column::time(string $name): ColumnDef
Column::rememberToken(): ColumnDef
Column::morphs(string $name): ColumnDef

// Modifiers (ColumnDef->)
->nullable()
->default(value)
->primary()
->big() ->tiny() ->small() ->medium() ->long()   // size modifiers
->unsigned()
->unique()
->index()
->useCurrent()
->cascadeOnDelete() ->nullOnDelete() ->restrictOnDelete()
->hidden()
->cast(string $class)
->references(string $table = '')   // auto-resolves from col name if empty
->foreign()       // id→foreignId, uuid→foreignUuid, ulid→foreignUlid
->morphs()        // uuid→uuidMorphs, else→morphs
->uuid()          // morphs→uuidMorphs, nullableMorphs→nullableUuidMorphs
```
