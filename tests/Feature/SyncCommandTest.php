<?php

$migrationsPath = '';
$schemaPath = '';

beforeEach(function () use (&$migrationsPath, &$schemaPath): void {
    $migrationsPath = $this->app->databasePath('migrations');
    $schemaPath = $this->app->databasePath('schema.php');

    if (! is_dir($migrationsPath)) {
        mkdir($migrationsPath, 0755, true);
    }
});

afterEach(function () use (&$migrationsPath, &$schemaPath): void {
    foreach (glob($migrationsPath.'/*.php') ?: [] as $f) {
        unlink($f);
    }
    if (file_exists($schemaPath)) {
        unlink($schemaPath);
    }
});

function writeSchema(string $schemaPath, string $php): void
{
    file_put_contents($schemaPath, "<?php\n\n".$php);
}

it('exits with failure and shows error when schema file is missing', function (): void {
    $this->artisan('hew:sync', ['--path' => '/nonexistent/schema.php'])
        ->assertExitCode(1)
        ->expectsOutputToContain('not found');
});

it('outputs nothing to sync for a clean schema', function () use (&$schemaPath): void {
    writeSchema($schemaPath, 'return \Boquizo\Hew\Schema\Schema::define([]);');

    $this->artisan('hew:sync', ['--path' => $schemaPath, '--force' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('Nothing to sync');
});

it('generates a create migration for a new table', function () use (&$migrationsPath, &$schemaPath): void {
    writeSchema(
        $schemaPath,
        'use Boquizo\Hew\Schema\{Schema, Table, Column};'."\n".
        "return Schema::define([\n    Table::make('orders')->columns([Column::id(), Column::string('reference'), Column::timestamps()]),\n]);",
    );

    $this->artisan('hew:sync', ['--path' => $schemaPath, '--force' => true])
        ->assertExitCode(0);

    $files = glob($migrationsPath.'/*create_orders_table*.php') ?: [];
    expect($files)->toHaveCount(1)
        ->and((string) file_get_contents($files[0]))->toContain("Schema::create('orders'");
});

it('generates an add_columns migration for new columns', function () use (&$migrationsPath, &$schemaPath): void {
    $existing = <<<'PHP'
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('users', function (Blueprint $table) { $table->id(); });
    }
    public function down(): void { Schema::dropIfExists('users'); }
};
PHP;
    file_put_contents($migrationsPath.'/2024_01_01_000000_create_users_table.php', $existing);

    writeSchema(
        $schemaPath,
        'use Boquizo\Hew\Schema\{Schema, Table, Column};'."\n".
        "return Schema::define([\n    Table::make('users')->columns([Column::id(), Column::string('avatar')]),\n]);",
    );

    $this->artisan('hew:sync', ['--path' => $schemaPath, '--force' => true])
        ->assertExitCode(0);

    expect(glob($migrationsPath.'/*add_columns_to_users_table*.php') ?: [])->toHaveCount(1);
});

it('generates a drop_columns migration for columns absent from schema', function () use (&$migrationsPath, &$schemaPath): void {
    $existing = <<<'PHP'
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('users', function (Blueprint $table) {
            $table->id(); $table->string('old_field');
        });
    }
    public function down(): void { Schema::dropIfExists('users'); }
};
PHP;
    file_put_contents($migrationsPath.'/2024_01_01_000000_create_users_table.php', $existing);

    writeSchema(
        $schemaPath,
        'use Boquizo\Hew\Schema\{Schema, Table, Column};'."\n".
        "return Schema::define([\n    Table::make('users')->columns([Column::id()]),\n]);",
    );

    $this->artisan('hew:sync', ['--path' => $schemaPath, '--force' => true])
        ->assertExitCode(0);

    $files = glob($migrationsPath.'/*drop_columns_from_users_table*.php') ?: [];
    expect($files)->toHaveCount(1)
        ->and((string) file_get_contents($files[0]))->toContain("dropColumn(['old_field'])");
});

it('does not generate files with --dry-run', function () use (&$migrationsPath, &$schemaPath): void {
    writeSchema(
        $schemaPath,
        'use Boquizo\Hew\Schema\{Schema, Table, Column};'."\n".
        "return Schema::define([\n    Table::make('products')->columns([Column::id()]),\n]);",
    );

    $this->artisan('hew:sync', ['--path' => $schemaPath, '--dry-run' => true])
        ->assertExitCode(1);

    expect(glob($migrationsPath.'/*.php') ?: [])->toHaveCount(0);
});

it('skips confirmation with --force', function () use (&$migrationsPath, &$schemaPath): void {
    writeSchema(
        $schemaPath,
        'use Boquizo\Hew\Schema\{Schema, Table, Column};'."\n".
        "return Schema::define([\n    Table::make('tags')->columns([Column::id(), Column::string('name')]),\n]);",
    );

    $this->artisan('hew:sync', ['--path' => $schemaPath, '--force' => true])
        ->assertExitCode(0);

    expect(glob($migrationsPath.'/*create_tags_table*.php') ?: [])->toHaveCount(1);
});
