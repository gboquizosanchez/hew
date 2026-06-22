<div align="center">

<img src="https://raw.githubusercontent.com/twitter/twemoji/master/assets/svg/2692.svg" width="100" alt="hew">

# `gboquizosanchez/hew`

**Schema as source of truth for Laravel migrations**

[![Latest Stable Version](https://img.shields.io/packagist/v/gboquizosanchez/hew.svg)](https://packagist.org/packages/gboquizosanchez/hew)
[![Total Downloads](https://img.shields.io/packagist/dt/gboquizosanchez/hew.svg)](https://packagist.org/packages/gboquizosanchez/hew)
[![PHP](https://img.shields.io/badge/PHP-%5E8.1-777BB4?logo=php&logoColor=white)](https://packagist.org/packages/gboquizosanchez/hew)
[![Laravel](https://img.shields.io/badge/Laravel-10%20%7C%2011%20%7C%2012%20%7C%2013-FF2D20?logo=laravel&logoColor=white)](https://packagist.org/packages/gboquizosanchez/hew)
[![License: MIT](https://img.shields.io/badge/License-MIT-22C55E.svg)](LICENSE.md)
[![PHPStan](https://img.shields.io/badge/PHPStan-Level%2010-blue)](https://phpstan.org/)
[![Tests](https://img.shields.io/badge/Tests-Pest%20v4-9C27B0)](https://pestphp.com/)

---

*Where is the `avatar` column defined? In migration number 47.*
*hew makes that a one-line answer.*

</div>

---

## Why hew?

Laravel migrations are append-only by design — great for versioning, painful for understanding the current state.

| Problem                                 | Without hew                      | With hew                                     |
|-----------------------------------------|----------------------------------|----------------------------------------------|
| "Does `users` have an `avatar` column?" | ❌ Read through N migrations     | ✅ One glance at `schema.php`                |
| Adding a column to an existing table    | ❌ Write migration by hand       | ✅ Add it to the schema, run `hew:sync`      |
| Reviewing the full database structure   | ❌ Mental model from git history | ✅ Single file, fluent API                   |
| CI check for uncommitted schema drift   | ❌ No built-in mechanism         | ✅ `hew:diff` exits `1` when changes pending |
| Accidental destructive migration        | ❌ Easy to do                    | ✅ Impossible — hew never emits `DROP`       |

---

## 🚀 Features

- **Single source of truth**: `database/schema.php` is the canonical description of your database.
- **Additive-only**: hew never emits `DROP COLUMN`, `RENAME COLUMN`, or `DROP TABLE`. Removed columns produce warnings, not data loss.
- **Fluent column API**: Chainable modifiers — `->nullable()`, `->unique()`, `->default()`, `->references()` and more.
- **Relation metadata**: Declare `hasMany`, `belongsTo`, `belongsToMany` directly on the table definition. `belongsToMany` auto-generates the pivot table.
- **Import from existing migrations**: `hew:import` generates `schema.php` from an existing migration history — handles `Schema::create`, `Schema::table`, `dropColumn`, `renameColumn`, `->change()`, and old-style FK declarations.
- **Import from a SQL dump**: `hew:import-sql` generates `schema.php` directly from a `mysqldump --no-data` file — useful for projects without Laravel migrations.
- **CI-friendly**: `hew:diff` exits `1` when changes are pending, making it trivially embeddable in pipelines.
- **Zero runtime footprint**: `--dev` only. No production dependency.

---

## 📦 Installation

```bash
composer require --dev gboquizosanchez/hew
```

Register the service provider manually. In **Laravel 11+**, add it to `bootstrap/providers.php`:

```php
return [
    // ...
    \Boquizo\Hew\HewServiceProvider::class,
];
```

In **Laravel 10**, add it to the `providers` array in `config/app.php`:

```php
'providers' => [
    // ...
    \Boquizo\Hew\HewServiceProvider::class,
],
```

**New project** — create `database/schema.php` from scratch (see [Define your schema](#define-your-schema) below).

**Existing project with migrations** — generate `schema.php` from your migration history:

```bash
php artisan hew:import
```

**Existing project without migrations** (or as a double-check against the live DB) — generate `schema.php` from a SQL dump:

```bash
mysqldump --no-data my_database > schema.sql
php artisan hew:import-sql schema.sql
```

---

## 🔧 Usage

### Define your schema

```php
<?php
// database/schema.php

use Boquizo\Hew\Schema\Column;
use Boquizo\Hew\Schema\Schema;
use Boquizo\Hew\Schema\Table;

return Schema::define([

    Table::make('users')
        ->columns([
            Column::id(),
            Column::string('name'),
            Column::string('email')->unique(),
            Column::string('password')->hidden(),
            Column::timestamps(),
        ])
        ->hasMany('posts')
        ->belongsToMany('roles'),        // auto-generates role_user pivot

    Table::make('posts')
        ->columns([
            Column::id(),
            Column::foreignId('user_id')->references('users'),
            Column::string('title'),
            Column::text('body'),
            Column::boolean('is_published')->default(false),
            Column::decimal('reading_fee', 10, 2)->nullable(),
            Column::timestamps(),
        ])
        ->belongsTo('users'),

]);
```

### Check what would change

```bash
php artisan hew:diff
```

```
  New table: posts
  + id
  + user_id, foreignId, -> users
  + title, string
  + body, text
  + is_published, boolean
  + reading_fee, decimal, 10,2, nullable
  + created_at, updated_at

  No destructive changes will be applied automatically.
```

### Generate the migrations

```bash
php artisan hew:sync
```

---

## 📋 Commands

### `hew:diff`

Shows pending changes without writing any files. Exit code `0` if clean, `1` if changes are pending — useful in CI to detect uncommitted schema drift.

| Flag     | Description                        |
|----------|------------------------------------|
| `--path` | Path to a non-default `schema.php` |

### `hew:sync`

Runs the diff and generates migration files.

| Flag        | Description                                             |
|-------------|---------------------------------------------------------|
| `--force`   | Skip the `[Y/n]` confirmation prompt                    |
| `--dry-run` | Alias for `hew:diff` — show diff only, generate nothing |
| `--path`    | Path to a non-default `schema.php`                      |

### `hew:import`

Reads existing migrations and generates `database/schema.php`. The reverse of `hew:sync` — useful for bootstrapping hew on an existing project.

Handles `Schema::create`, `Schema::table` (add, drop, rename, change columns), old-style `->foreign()->references()->on()` FK declarations, and `Schema::drop`/`dropIfExists`. Files using `DB::statement` (raw SQL) are flagged as warnings. Unsupported column types produce `// TODO` comments.

| Flag       | Description                                                       |
|------------|-------------------------------------------------------------------|
| `--path`   | Path to migrations directory (default: `database/migrations`)     |
| `--output` | Output path for `schema.php` (default: `database/schema.php`)     |

### `hew:import-sql`

Generates `database/schema.php` directly from a `mysqldump --no-data` SQL file. Useful for legacy projects without Laravel migrations, or as a sanity-check against the live database state.

```bash
mysqldump --no-data my_database > schema.sql
php artisan hew:import-sql schema.sql
```

Type mappings follow Laravel conventions: `tinyint(1)` → `boolean`, `varchar(255)` → `string`, `char(n)` → `string` with length, `deleted_at nullable timestamp` → `softDeletes`, `created_at` + `updated_at` → `timestamps()`. Foreign keys from `CONSTRAINT … FOREIGN KEY` blocks are resolved to `->references()`.

| Argument | Description              |
|----------|--------------------------|
| `file`   | Path to the SQL dump     |

| Flag       | Description                                                   |
|------------|---------------------------------------------------------------|
| `--output` | Output path for `schema.php` (default: `database/schema.php`) |

---

## 🗂️ Column reference

### Types

| Method                              | Blueprint equivalent                                               |
|-------------------------------------|--------------------------------------------------------------------|
| `Column::id()`                      | `$table->id()`                                                     |
| `Column::string('name')`            | `$table->string('name')`                                           |
| `Column::string('name', 100)`       | `$table->string('name', 100)`                                      |
| `Column::text('body')`              | `$table->text('body')`                                             |
| `Column::integer('count')`          | `$table->integer('count')`                                         |
| `Column::bigInteger('amount')`      | `$table->bigInteger('amount')`                                     |
| `Column::decimal('total', 10, 2)`   | `$table->decimal('total', 10, 2)` — precision and scale required   |
| `Column::float('ratio')`            | `$table->float('ratio')`                                           |
| `Column::boolean('active')`         | `$table->boolean('active')`                                        |
| `Column::json('settings')`          | `$table->json('settings')`                                         |
| `Column::timestamp('paid_at')`      | `$table->timestamp('paid_at')`                                     |
| `Column::timestamps()`              | `$table->timestamps()` — shortcut for `created_at` + `updated_at` |
| `Column::softDeletes()`             | `$table->softDeletes()` — shortcut for `deleted_at`               |
| `Column::date('birth_date')`        | `$table->date('birth_date')`                                       |
| `Column::time('starts_at')`         | `$table->time('starts_at')`                                        |
| `Column::foreignId('user_id')`      | `$table->foreignId('user_id')`                                     |
| `Column::uuid('token')`             | `$table->uuid('token')`                                            |
| `Column::ulid('id')`                | `$table->ulid('id')`                                               |
| `Column::morphs('commentable')`     | `$table->morphs('commentable')`                                    |
| `Column::rememberToken()`           | `$table->rememberToken()`                                          |

> **Note:** `Column::enum()` is not supported and throws
> `UnsupportedColumnTypeException`. Use
> `Column::string()->cast(YourEnum::class)` instead —
> hew stores it as `VARCHAR` in the database.

### Modifiers

| Modifier                | Blueprint effect            | Notes                                                     |
|-------------------------|-----------------------------|-----------------------------------------------------------|
| `->nullable()`          | `->nullable()`              |                                                           |
| `->default($value)`     | `->default($value)`         |                                                           |
| `->primary()`           | `->primary()`               |                                                           |
| `->unique()`            | `->unique()`                |                                                           |
| `->index()`             | `->index()`                 |                                                           |
| `->unsigned()`          | `->unsigned()`              | Use on `integer` columns                                  |
| `->big()`               | `bigInteger` / `longText`   | Size modifier — also `->tiny()`, `->small()`, `->medium()`, `->long()` |
| `->references('table')` | `->constrained('table')`    | Use on `foreignId` / `uuid` / `ulid` columns              |
| `->references()`        | `->constrained()`           | Auto-infers table name from column name (`user_id` → `users`) |
| `->foreign()`           | `foreignId` / `foreignUuid` | Converts `id`→`foreignId`, `uuid`→`foreignUuid`           |
| `->useCurrent()`        | `->useCurrent()`            | Use on `timestamp` columns                                |
| `->cascadeOnDelete()`   | `->cascadeOnDelete()`       | Use after `->references()`                                |
| `->nullOnDelete()`      | `->nullOnDelete()`          | Use after `->references()`                                |
| `->restrictOnDelete()`  | `->restrictOnDelete()`      | Use after `->references()`                                |
| `->hidden()`            | *(model only)*              | Marks column as `$hidden` in the generated Eloquent model |
| `->cast(MyEnum::class)` | *(model only)*              | Adds an Eloquent `$cast` entry                            |
| `->morphs()`            | `uuidMorphs` / `morphs`     | Converts `uuid` column to `uuidMorphs`                    |
| `->uuid()`              | `nullableUuidMorphs`        | Converts `morphs` to `uuidMorphs`                         |

---

## 🔗 Table reference

```php
Table::make('users')
    ->columns([...])               // required — array of Column instances
    ->hasMany('posts')             // metadata only
    ->hasOne('profile')            // metadata only
    ->belongsTo('companies')       // warns if 'company_id' foreignId column is missing
    ->belongsToMany('roles')       // auto-generates pivot table role_user if absent
```

All relation methods are **metadata only** — they don't generate columns or migrations by themselves.

`belongsToMany('roles')` on `users` automatically generates a `role_user` pivot table (alphabetical) with the two `foreignId` columns when none exists.

---

## 🔒 Safety guarantees

hew never emits `DROP COLUMN`, `RENAME COLUMN`, or `DROP TABLE`.

| Situation                           | What hew does                      |
|-------------------------------------|------------------------------------|
| Column in schema, not in migrations | Generates migration to add it      |
| Column in migrations, not in schema | **Warning only** — no migration    |
| Column type changed                 | **Warning only** — no migration    |
| Table in schema, not in migrations  | Generates `create_table` migration |

When you see a warning, write that migration by hand. The cost is one extra file. The benefit is `hew:sync` can never destroy your data.

---

## 🧪 Testing

```bash
composer test
```

This package uses [Pest v4](https://pestphp.com/) with the Laravel plugin. Static analysis runs via [PHPStan](https://phpstan.org/) at level 10.

### Troubleshooting

If you encounter issues:

1. **Check the logs** — Laravel logs may contain helpful error messages.
2. **Verify requirements** — Ensure PHP ^8.1 and Laravel 10–13 are met.
3. **Clear cache** — Run `php artisan config:clear` and `php artisan cache:clear`.
4. **`// TODO` lines in generated schema** — The column type is not natively supported by hew (e.g. `geometry`). Fill it in manually or open an issue.
5. **Open an issue** — [Report bugs or request features](https://github.com/gboquizosanchez/hew/issues/new).

---

## Contributing

Contributions are welcome! Please feel free to:

- 🐛 **Report bugs** via [GitHub Issues](https://github.com/gboquizosanchez/hew/issues/new)
- 💡 **Suggest features** or improvements
- 🔧 **Submit pull requests** with bug fixes or enhancements
- 📖 **Improve documentation** or examples

Please make sure all tests pass and the code follows PSR-12 before submitting a PR.

---

## Credits

- **Author**: [Germán Boquizo Sánchez](mailto:gboquizo@gestazion.com)
- **Contributors**: [View all contributors](../../contributors)

---

## 📄 License

This package is open-source software licensed under the [MIT License](LICENSE.md).

---

<div align="center">

Made with ❤️ for the PHP · Laravel community

</div>
