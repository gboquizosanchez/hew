<?php

use Boquizo\Hew\Diff\SchemaDiff;
use Boquizo\Hew\Exceptions\MigrationAlreadyExistsException;
use Boquizo\Hew\Generator\MigrationGenerator;
use Boquizo\Hew\Parser\ParsedColumn;
use Boquizo\Hew\Parser\ParsedTable;
use Boquizo\Hew\Schema\Column;
use Boquizo\Hew\Schema\Schema;
use Boquizo\Hew\Schema\Table;

$outputDir = '';
$stubsPath = dirname(__DIR__, 2).'/stubs';

beforeEach(function () use (&$outputDir): void {
    $outputDir = sys_get_temp_dir().'/hew_gen_'.uniqid();
    mkdir($outputDir);
});

afterEach(function () use (&$outputDir): void {
    array_map('unlink', glob($outputDir.'/*') ?: []);
    @rmdir($outputDir);
});

function gen(string $outputDir, string $stubsPath, string $date = '2024_01_01'): MigrationGenerator
{
    return new MigrationGenerator($outputDir, $stubsPath, $date);
}

function schemaDiff(Schema $desired, array $current = []): SchemaDiff
{
    return new SchemaDiff($desired, $current);
}

function parsedTableGen(string $name, array $columns = []): ParsedTable
{
    $t = new ParsedTable($name);
    foreach ($columns as $col => $type) {
        $t->addColumn(new ParsedColumn($col, $type));
    }

    return $t;
}

it('generates a create migration for a new table', function () use (&$outputDir, $stubsPath): void {
    $diff = schemaDiff(Schema::define([
        Table::make('orders')->columns([
            Column::id(),
            Column::string('reference')->unique(),
            Column::decimal('total', 10, 2),
            Column::timestamps(),
        ]),
    ]));

    $files = gen($outputDir, $stubsPath)->generate($diff);
    $content = (string) file_get_contents($files[0]);

    expect($files)->toHaveCount(1)
        ->and($content)->toContain("Schema::create('orders'")
        ->and($content)->toContain('$table->id()')
        ->and($content)->toContain("\$table->string('reference')")
        ->and($content)->toContain("\$table->decimal('total', 10, 2)")
        ->and($content)->toContain('$table->timestamps()');
});

it('generates an add_columns migration for new columns', function () use (&$outputDir, $stubsPath): void {
    $diff = schemaDiff(
        Schema::define([Table::make('users')->columns([Column::id(), Column::string('avatar')->nullable()])]),
        ['users' => parsedTableGen('users', ['id' => 'id'])],
    );

    $files = gen($outputDir, $stubsPath)->generate($diff);
    $content = (string) file_get_contents($files[0]);

    expect($files)->toHaveCount(1)
        ->and($content)->toContain("Schema::table('users'")
        ->and($content)->toContain("\$table->string('avatar')->nullable()")
        ->and($content)->toContain('dropColumn');
});

it('generates valid PHP syntax', function () use (&$outputDir, $stubsPath): void {
    $diff = schemaDiff(Schema::define([
        Table::make('products')->columns([
            Column::id(),
            Column::string('name'),
            Column::decimal('price', 8, 2),
            Column::boolean('active')->default(true),
            Column::timestamps(),
        ]),
    ]));

    $files = gen($outputDir, $stubsPath)->generate($diff);
    $output = shell_exec('php -l '.escapeshellarg($files[0]).' 2>&1');

    expect($output)->toContain('No syntax errors');
});

it('throws MigrationAlreadyExistsException when all filename slots are taken', function () use (&$outputDir, $stubsPath): void {
    $diff = schemaDiff(Schema::define([Table::make('users')->columns([Column::id()])]));

    file_put_contents($outputDir.'/2024_01_01_000000_create_users_table.php', '<?php');
    for ($i = 2; $i <= 10; $i++) {
        file_put_contents($outputDir.'/2024_01_01_000000_create_users_table_'.$i.'.php', '<?php');
    }

    expect(static fn (): array => gen($outputDir, $stubsPath)->generate($diff))
        ->toThrow(MigrationAlreadyExistsException::class);
});

it('uses dropIfExists in down() for create migrations', function () use (&$outputDir, $stubsPath): void {
    $diff = schemaDiff(Schema::define([Table::make('invoices')->columns([Column::id()])]));

    $files = gen($outputDir, $stubsPath)->generate($diff);

    expect((string) file_get_contents($files[0]))->toContain("Schema::dropIfExists('invoices')");
});

it('uses dropColumn in down() for add_columns migrations', function () use (&$outputDir, $stubsPath): void {
    $diff = schemaDiff(
        Schema::define([Table::make('users')->columns([Column::id(), Column::string('bio')])]),
        ['users' => parsedTableGen('users', ['id' => 'id'])],
    );

    $files = gen($outputDir, $stubsPath)->generate($diff);
    $content = (string) file_get_contents($files[0]);

    expect($content)->toContain('dropColumn')
        ->and($content)->toContain("'bio'");
});

it('follows Laravel migration filename convention', function () use (&$outputDir, $stubsPath): void {
    $diff = schemaDiff(Schema::define([Table::make('payments')->columns([Column::id()])]));

    $files = gen($outputDir, $stubsPath)->generate($diff);

    expect(basename($files[0]))->toMatch('/^\d{4}_\d{2}_\d{2}_\d{6}_create_payments_table\.php$/');
});

it('generates constrained() without arg when name auto-resolves', function () use (&$outputDir, $stubsPath): void {
    $diff = schemaDiff(Schema::define([
        Table::make('posts')->columns([Column::id('user_id')->foreign()->references('users')]),
    ]));

    $files = gen($outputDir, $stubsPath)->generate($diff);

    expect((string) file_get_contents($files[0]))
        ->toContain("foreignId('user_id')->constrained()")
        ->not->toContain("constrained('users')");
});

it('generates constrained() with explicit table when name does not auto-resolve', function () use (&$outputDir, $stubsPath): void {
    $diff = schemaDiff(Schema::define([
        Table::make('posts')->columns([Column::id('author_id')->foreign()->references('users')]),
    ]));

    $files = gen($outputDir, $stubsPath)->generate($diff);

    expect((string) file_get_contents($files[0]))->toContain("foreignId('author_id')->constrained('users')");
});

it('generates uuid() in blueprint', function () use (&$outputDir, $stubsPath): void {
    $diff = schemaDiff(Schema::define([
        Table::make('tokens')->columns([Column::uuid('token')]),
    ]));

    $files = gen($outputDir, $stubsPath)->generate($diff);

    expect((string) file_get_contents($files[0]))->toContain("\$table->uuid('token')");
});

it('generates ulid() in blueprint', function () use (&$outputDir, $stubsPath): void {
    $diff = schemaDiff(Schema::define([
        Table::make('tokens')->columns([Column::ulid('id')]),
    ]));

    $files = gen($outputDir, $stubsPath)->generate($diff);

    expect((string) file_get_contents($files[0]))->toContain("\$table->ulid('id')");
});

it('generates useCurrent() in blueprint', function () use (&$outputDir, $stubsPath): void {
    $diff = schemaDiff(Schema::define([
        Table::make('events')->columns([Column::timestamp('fired_at')->useCurrent()]),
    ]));

    $files = gen($outputDir, $stubsPath)->generate($diff);

    expect((string) file_get_contents($files[0]))->toContain('->useCurrent()');
});

it('generates cascadeOnDelete() after constrained()', function () use (&$outputDir, $stubsPath): void {
    $diff = schemaDiff(Schema::define([
        Table::make('posts')->columns([
            Column::id('author_id')->foreign()->references('users')->cascadeOnDelete(),
        ]),
    ]));

    $files = gen($outputDir, $stubsPath)->generate($diff);

    expect((string) file_get_contents($files[0]))->toContain("constrained('users')->cascadeOnDelete()");
});

it('generates unsignedTinyInteger for integer()->tiny()->unsigned()', function () use (&$outputDir, $stubsPath): void {
    $diff = schemaDiff(Schema::define([
        Table::make('ratings')->columns([Column::integer('score')->tiny()->unsigned()]),
    ]));

    $files = gen($outputDir, $stubsPath)->generate($diff);

    expect((string) file_get_contents($files[0]))->toContain("\$table->unsignedTinyInteger('score')");
});

it('generates tinyInteger for integer()->tiny()', function () use (&$outputDir, $stubsPath): void {
    $diff = schemaDiff(Schema::define([
        Table::make('t')->columns([Column::integer('score')->tiny()]),
    ]));

    $files = gen($outputDir, $stubsPath)->generate($diff);

    expect((string) file_get_contents($files[0]))->toContain("\$table->tinyInteger('score')");
});

it('generates smallInteger for integer()->small()', function () use (&$outputDir, $stubsPath): void {
    $diff = schemaDiff(Schema::define([
        Table::make('t')->columns([Column::integer('rank')->small()]),
    ]));

    $files = gen($outputDir, $stubsPath)->generate($diff);

    expect((string) file_get_contents($files[0]))->toContain("\$table->smallInteger('rank')");
});

it('generates longText for text()->long()', function () use (&$outputDir, $stubsPath): void {
    $diff = schemaDiff(Schema::define([
        Table::make('t')->columns([Column::text('body')->long()]),
    ]));

    $files = gen($outputDir, $stubsPath)->generate($diff);

    expect((string) file_get_contents($files[0]))->toContain("\$table->longText('body')");
});

it('generates tinyText for text()->tiny()', function () use (&$outputDir, $stubsPath): void {
    $diff = schemaDiff(Schema::define([
        Table::make('t')->columns([Column::text('hint')->tiny()]),
    ]));

    $files = gen($outputDir, $stubsPath)->generate($diff);

    expect((string) file_get_contents($files[0]))->toContain("\$table->tinyText('hint')");
});

it('generates nullOnDelete() after constrained()', function () use (&$outputDir, $stubsPath): void {
    $diff = schemaDiff(Schema::define([
        Table::make('comments')->columns([
            Column::id('user_id')->foreign()->references('users')->nullOnDelete(),
        ]),
    ]));

    $files = gen($outputDir, $stubsPath)->generate($diff);

    expect((string) file_get_contents($files[0]))->toContain('->nullOnDelete()');
});

it('generates softDeletes() in migration', function () use (&$outputDir, $stubsPath): void {
    $diff = schemaDiff(Schema::define([
        Table::make('posts')->columns([Column::id(), Column::softDeletes()]),
    ]));
    $files = gen($outputDir, $stubsPath)->generate($diff);

    expect((string) file_get_contents($files[0]))->toContain('$table->softDeletes()');
});

it('generates morphs() columns in migration', function () use (&$outputDir, $stubsPath): void {
    $diff = schemaDiff(Schema::define([
        Table::make('comments')->columns([
            Column::id(),
            Column::morphs('commentable'),
        ]),
    ]));
    $files = gen($outputDir, $stubsPath)->generate($diff);

    expect((string) file_get_contents($files[0]))
        ->toContain("\$table->morphs('commentable')");
});

it('generates nullableMorphs() and uuidMorphs() in migration', function () use (&$outputDir, $stubsPath): void {
    $diff = schemaDiff(Schema::define([
        Table::make('activities')->columns([
            Column::id(),
            Column::morphs('subject')->nullable(),
            Column::uuid('trackable')->morphs(),
        ]),
    ]));
    $files = gen($outputDir, $stubsPath)->generate($diff);
    $content = (string) file_get_contents($files[0]);

    expect($content)
        ->toContain("\$table->nullableMorphs('subject')")
        ->toContain("\$table->uuidMorphs('trackable')");
});

it('generates a drop_table migration for droppedTables', function () use (&$outputDir, $stubsPath): void {
    $diff = schemaDiff(
        Schema::define([Table::make('users')->columns([Column::id()])]),
        ['users' => parsedTableGen('users', ['id' => 'id']), 'old_table' => parsedTableGen('old_table', ['id' => 'id'])],
    );

    $files = gen($outputDir, $stubsPath)->generate($diff);
    $dropFile = array_values(array_filter($files, static fn ($f) => str_contains($f, 'drop_old_table')))[0] ?? null;

    expect($dropFile)->not->toBeNull()
        ->and((string) file_get_contents($dropFile))->toContain("Schema::dropIfExists('old_table')")
        ->toContain('RuntimeException');
});

it('generates a drop_columns migration for droppedColumns', function () use (&$outputDir, $stubsPath): void {
    $diff = schemaDiff(
        Schema::define([Table::make('users')->columns([Column::id()])]),
        ['users' => parsedTableGen('users', ['id' => 'id', 'old_col' => 'string'])],
    );

    $files = gen($outputDir, $stubsPath)->generate($diff);
    $content = (string) file_get_contents($files[0]);

    expect($files)->toHaveCount(1)
        ->and($content)->toContain("Schema::table('users'")
        ->toContain("dropColumn(['old_col'])")
        ->toContain('RuntimeException');
});

it('generates dropForeign before dropColumn for FK columns', function () use (&$outputDir, $stubsPath): void {
    $t = new ParsedTable('orders');
    $t->addColumn(new ParsedColumn('user_id', 'foreignId', ['references' => 'users']));
    $diff = schemaDiff(
        Schema::define([Table::make('orders')->columns([Column::id()])]),
        ['orders' => $t],
    );

    $files = gen($outputDir, $stubsPath)->generate($diff);
    $content = (string) file_get_contents($files[0]);

    expect($content)->toContain("dropForeign(['user_id'])")
        ->toContain("dropColumn(['user_id'])");

    // dropForeign must appear before dropColumn
    expect(strpos($content, 'dropForeign'))->toBeLessThan(strpos($content, 'dropColumn'));
});

it('generates a modify_columns migration with ->change()', function () use (&$outputDir, $stubsPath): void {
    $diff = schemaDiff(
        Schema::define([Table::make('users')->columns([Column::id(), Column::text('name')])]),
        ['users' => parsedTableGen('users', ['id' => 'id', 'name' => 'string'])],
    );

    $files = gen($outputDir, $stubsPath)->generate($diff);
    $content = (string) file_get_contents($files[0]);

    expect($files)->toHaveCount(1)
        ->and($content)->toContain("Schema::table('users'")
        ->toContain("\$table->text('name')->change()")
        ->toContain('RuntimeException');
});

it('emits destructive migrations before additive ones', function () use (&$outputDir, $stubsPath): void {
    $diff = schemaDiff(
        Schema::define([
            Table::make('users')->columns([Column::id()]),
            Table::make('posts')->columns([Column::id()]),
        ]),
        [
            'users' => parsedTableGen('users', ['id' => 'id', 'stale' => 'string']),
            'old_table' => parsedTableGen('old_table', ['id' => 'id']),
        ],
    );

    $files = gen($outputDir, $stubsPath)->generate($diff);
    $names = array_map('basename', $files);

    $dropIdx = array_search(array_values(array_filter($names, static fn ($n) => str_contains($n, 'drop')))[0] ?? '', $names);
    $createIdx = array_search(array_values(array_filter($names, static fn ($n) => str_contains($n, 'create_posts')))[0] ?? '', $names);

    expect($dropIdx)->toBeLessThan($createIdx);
});
