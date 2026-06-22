<?php

use Boquizo\Hew\Generator\SchemaRenderer;
use Boquizo\Hew\Parser\ParsedColumn;
use Boquizo\Hew\Parser\ParsedTable;

function table(string $name, array $columns): ParsedTable
{
    $t = new ParsedTable($name);
    foreach ($columns as $col) {
        $t->addColumn($col);
    }

    return $t;
}

function col(string $name, string $type, array $modifiers = []): ParsedColumn
{
    return new ParsedColumn($name, $type, $modifiers);
}

it('renders a simple table with string columns', function (): void {
    $output = (new SchemaRenderer)->render([
        table('users', [col('id', 'id'), col('name', 'string')]),
    ]);

    expect($output)
        ->toContain("Table::make('users')")
        ->toContain('Column::id()')
        ->toContain("Column::string('name')");
});

it('collapses created_at + updated_at into timestamps()', function (): void {
    $output = (new SchemaRenderer)->render([
        table('users', [
            col('created_at', 'timestamp'),
            col('updated_at', 'timestamp'),
        ]),
    ]);

    expect($output)
        ->toContain('Column::timestamps()')
        ->not->toContain("Column::timestamp('created_at')")
        ->not->toContain("Column::timestamp('updated_at')");
});

it('does not collapse timestamps when they have modifiers', function (): void {
    $output = (new SchemaRenderer)->render([
        table('events', [
            col('created_at', 'timestamp', ['useCurrent' => true]),
            col('updated_at', 'timestamp'),
        ]),
    ]);

    expect($output)
        ->not->toContain('Column::timestamps()')
        ->toContain("Column::timestamp('created_at')")
        ->toContain('->useCurrent()');
});

it('emits nullable modifier', function (): void {
    $output = (new SchemaRenderer)->render([
        table('t', [col('bio', 'text', ['nullable' => true])]),
    ]);

    expect($output)->toContain("Column::text('bio')->nullable()");
});

it('emits unique modifier', function (): void {
    $output = (new SchemaRenderer)->render([
        table('t', [col('email', 'string', ['unique' => true])]),
    ]);

    expect($output)->toContain("Column::string('email')->unique()");
});

it('emits references modifier when not auto-resolvable', function (): void {
    $output = (new SchemaRenderer)->render([
        table('t', [col('author_id', 'foreignId', ['references' => 'users'])]),
    ]);

    expect($output)->toContain("Column::id('author_id')->foreign()->references('users')");
});

it('emits references() without arg when auto-resolvable', function (): void {
    $output = (new SchemaRenderer)->render([
        table('t', [col('user_id', 'foreignId', ['references' => 'users'])]),
    ]);

    expect($output)
        ->toContain("Column::id('user_id')->foreign()->references()")
        ->not->toContain("->references('users')");
});

it('emits cascadeOnDelete after references', function (): void {
    $output = (new SchemaRenderer)->render([
        table('t', [col('author_id', 'foreignId', ['references' => 'users', 'onDelete' => 'cascade'])]),
    ]);

    expect($output)->toContain("->references('users')->cascadeOnDelete()");
});

it('emits nullOnDelete', function (): void {
    $output = (new SchemaRenderer)->render([
        table('t', [col('user_id', 'foreignId', ['references' => 'users', 'onDelete' => 'null'])]),
    ]);

    expect($output)->toContain('->nullOnDelete()');
});

it('emits useCurrent modifier', function (): void {
    $output = (new SchemaRenderer)->render([
        table('t', [col('created_at', 'timestamp', ['useCurrent' => true])]),
    ]);

    expect($output)->toContain("Column::timestamp('created_at')->useCurrent()");
});

it('serialises boolean default false', function (): void {
    $output = (new SchemaRenderer)->render([
        table('t', [col('active', 'boolean', ['default' => 'false'])]),
    ]);

    expect($output)->toContain('->default(false)');
});

it('serialises boolean default true', function (): void {
    $output = (new SchemaRenderer)->render([
        table('t', [col('active', 'boolean', ['default' => 'true'])]),
    ]);

    expect($output)->toContain('->default(true)');
});

it('serialises numeric default', function (): void {
    $output = (new SchemaRenderer)->render([
        table('t', [col('count', 'integer', ['default' => '0'])]),
    ]);

    expect($output)->toContain('->default(0)');
});

it('serialises string default', function (): void {
    $output = (new SchemaRenderer)->render([
        table('t', [col('status', 'string', ['default' => "'draft'"])]),
    ]);

    expect($output)->toContain("->default('draft')");
});

it('emits PHP expression default as-is', function (): void {
    $output = (new SchemaRenderer)->render([
        table('t', [col('role', 'string', ['default' => 'Role::USER'])]),
    ]);

    expect($output)->toContain('->default(Role::USER)');
});

it('normalises longText to text()->long()', function (): void {
    $output = (new SchemaRenderer)->render([
        table('t', [col('body', 'longText')]),
    ]);

    expect($output)->toContain("Column::text('body')->long()");
});

it('normalises mediumText to text()->medium()', function (): void {
    $output = (new SchemaRenderer)->render([
        table('t', [col('body', 'mediumText')]),
    ]);

    expect($output)->toContain("Column::text('body')->medium()");
});

it('normalises tinyText to text()->tiny()', function (): void {
    $output = (new SchemaRenderer)->render([
        table('t', [col('body', 'tinyText')]),
    ]);

    expect($output)->toContain("Column::text('body')->tiny()");
});

it('normalises tinyInteger to integer()->tiny()', function (): void {
    $output = (new SchemaRenderer)->render([
        table('t', [col('score', 'tinyInteger')]),
    ]);

    expect($output)->toContain("Column::integer('score')->tiny()");
});

it('normalises smallInteger to integer()->small()', function (): void {
    $output = (new SchemaRenderer)->render([
        table('t', [col('rank', 'smallInteger')]),
    ]);

    expect($output)->toContain("Column::integer('rank')->small()");
});

it('normalises mediumInteger to integer()->medium()', function (): void {
    $output = (new SchemaRenderer)->render([
        table('t', [col('count', 'mediumInteger')]),
    ]);

    expect($output)->toContain("Column::integer('count')->medium()");
});

it('normalises unsignedTinyInteger to integer()->tiny()->unsigned()', function (): void {
    $output = (new SchemaRenderer)->render([
        table('t', [col('score', 'unsignedTinyInteger')]),
    ]);

    expect($output)->toContain("Column::integer('score')->tiny()->unsigned()");
});

it('normalises unsignedSmallInteger to integer()->small()->unsigned()', function (): void {
    $output = (new SchemaRenderer)->render([
        table('t', [col('rank', 'unsignedSmallInteger')]),
    ]);

    expect($output)->toContain("Column::integer('rank')->small()->unsigned()");
});

it('normalises unsignedBigInteger to integer()->big()->unsigned()', function (): void {
    $output = (new SchemaRenderer)->render([
        table('t', [col('views', 'unsignedBigInteger')]),
    ]);

    expect($output)->toContain("Column::integer('views')->big()->unsigned()");
});

it('normalises jsonb to json', function (): void {
    $output = (new SchemaRenderer)->render([
        table('t', [col('meta', 'jsonb')]),
    ]);

    expect($output)->toContain("Column::json('meta')");
});

it('normalises dateTime to timestamp', function (): void {
    $output = (new SchemaRenderer)->render([
        table('t', [col('sent_at', 'dateTime')]),
    ]);

    expect($output)->toContain("Column::timestamp('sent_at')");
});

it('emits uuid column', function (): void {
    $output = (new SchemaRenderer)->render([
        table('t', [col('token', 'uuid')]),
    ]);

    expect($output)->toContain("Column::uuid('token')");
});

it('emits ulid column', function (): void {
    $output = (new SchemaRenderer)->render([
        table('t', [col('token', 'ulid')]),
    ]);

    expect($output)->toContain("Column::ulid('token')");
});

it('emits date column', function (): void {
    $output = (new SchemaRenderer)->render([
        table('t', [col('born_on', 'date')]),
    ]);

    expect($output)->toContain("Column::date('born_on')");
});

it('emits time column', function (): void {
    $output = (new SchemaRenderer)->render([
        table('t', [col('starts_at', 'time')]),
    ]);

    expect($output)->toContain("Column::time('starts_at')");
});

it('normalises timeTz to time', function (): void {
    $output = (new SchemaRenderer)->render([
        table('t', [col('starts_at', 'timeTz')]),
    ]);

    expect($output)->toContain("Column::time('starts_at')");
});

it('emits TODO comment for unsupported type', function (): void {
    $output = (new SchemaRenderer)->render([
        table('t', [col('loc', 'geometry')]),
    ]);

    expect($output)->toContain('// TODO: unsupported type "geometry"');
});

it('emits no relations chain when table has no FK columns', function (): void {
    $output = (new SchemaRenderer)->render([
        table('users', [col('id', 'id')]),
    ]);

    expect($output)
        ->not->toContain('->belongsTo(')
        ->not->toContain('->hasMany(')
        ->not->toContain('// TODO: add relations');
});

it('infers belongsTo from references modifier', function (): void {
    $output = (new SchemaRenderer)->render([
        table('posts', [col('user_id', 'foreignId', ['references' => 'users'])]),
    ]);

    expect($output)->toContain("->belongsTo('users')");
});

it('infers hasMany on the referenced table', function (): void {
    $output = (new SchemaRenderer)->render([
        'users' => table('users', [col('id', 'id')]),
        'posts' => table('posts', [col('user_id', 'foreignId', ['references' => 'users'])]),
    ]);

    expect($output)->toContain("->hasMany('posts')");
});

it('deduplicates belongsTo when two FKs reference the same table', function (): void {
    $output = (new SchemaRenderer)->render([
        table('proposals', [
            col('user_id', 'foreignId', ['references' => 'users']),
            col('reviewed_by', 'foreignId', ['references' => 'users']),
        ]),
    ]);

    expect(substr_count($output, "->belongsTo('users')"))->toBe(1);
});

it('generates valid PHP syntax', function (): void {
    $output = (new SchemaRenderer)->render([
        table('users', [
            col('id', 'id'),
            col('email', 'string', ['unique' => true]),
            col('created_at', 'timestamp'),
            col('updated_at', 'timestamp'),
        ]),
    ]);

    $tmpFile = sys_get_temp_dir().'/hew_schema_test_'.uniqid().'.php';
    file_put_contents($tmpFile, $output);
    $result = shell_exec('php -l '.escapeshellarg($tmpFile).' 2>&1');
    unlink($tmpFile);

    expect($result)->toContain('No syntax errors');
});

it('counts TODO occurrences correctly', function (): void {
    $output = (new SchemaRenderer)->render([
        table('t', [col('loc', 'geometry'), col('shape', 'point')]),
    ]);

    expect(substr_count($output, '// TODO'))->toBeGreaterThanOrEqual(2);
});

it('renders softDeletes column as Column::softDeletes()', function (): void {
    $output = (new SchemaRenderer)->render([
        table('posts', [col('id', 'id'), col('deleted_at', 'softDeletes')]),
    ]);

    expect($output)->toContain('Column::softDeletes()');
});

it('renders morphs types correctly', function (): void {
    $output = (new SchemaRenderer)->render([
        table('comments', [
            col('id', 'id'),
            col('commentable', 'morphs'),
            col('nullable_rel', 'nullableMorphs'),
            col('uuid_rel', 'uuidMorphs'),
        ]),
    ]);

    expect($output)
        ->toContain("Column::morphs('commentable')")
        ->toContain("Column::morphs('nullable_rel')->nullable()")
        ->toContain("Column::uuid('uuid_rel')->morphs()");
});
