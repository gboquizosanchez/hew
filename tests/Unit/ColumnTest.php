<?php

use Boquizo\Hew\Exceptions\UnsupportedColumnTypeException;
use Boquizo\Hew\Schema\Column;

it('creates id column', function (): void {
    $col = Column::id();
    expect($col->name)->toBe('id')
        ->and($col->type)->toBe('id');
});

it('creates string column', function (): void {
    $col = Column::string('name');
    expect($col->name)->toBe('name')
        ->and($col->type)->toBe('string');
});

it('creates text column', function (): void {
    expect(Column::text('body')->type)->toBe('text');
});

it('creates integer column', function (): void {
    expect(Column::integer('count')->type)->toBe('integer');
});

it('creates bigInteger column', function (): void {
    expect(Column::bigInteger('amount')->type)->toBe('integer')
        ->and(Column::bigInteger('amount')->size)->toBe('big');
});

it('creates decimal column with precision and scale', function (): void {
    $col = Column::decimal('total', 10, 2);
    expect($col->type)->toBe('decimal')
        ->and($col->parameters)->toBe([10, 2]);
});

it('creates boolean column', function (): void {
    expect(Column::boolean('active')->type)->toBe('boolean');
});

it('creates json column', function (): void {
    expect(Column::json('settings')->type)->toBe('json');
});

it('creates timestamp column', function (): void {
    expect(Column::timestamp('paid_at')->type)->toBe('timestamp');
});

it('creates timestamps shortcut with two children', function (): void {
    $col = Column::timestamps();
    expect($col->isShortcut())->toBeTrue();

    $children = $col->children();
    expect($children)->toHaveCount(2)
        ->and($children[0]->name)->toBe('created_at')
        ->and($children[1]->name)->toBe('updated_at');
});

it('creates foreignId column via foreign()', function (): void {
    expect(Column::id('user_id')->foreign()->type)->toBe('foreignId');
});

it('creates foreignUuid column via foreign()', function (): void {
    expect(Column::uuid('festival_id')->foreign()->type)->toBe('foreignUuid');
});

it('throws UnsupportedColumnTypeException for enum', function (): void {
    expect(static fn () => Column::enum('status'))
        ->toThrow(UnsupportedColumnTypeException::class, 'Enum columns are not supported');
});

it('applies nullable modifier', function (): void {
    expect(Column::string('name')->nullable()->isNullable)->toBeTrue();
});

it('applies default modifier', function (): void {
    $col = Column::boolean('active')->default(true);
    expect($col->hasDefault)->toBeTrue()
        ->and($col->defaultValue)->toBeTrue();
});

it('applies unique modifier', function (): void {
    expect(Column::string('email')->unique()->isUnique)->toBeTrue();
});

it('applies unsigned modifier', function (): void {
    expect(Column::integer('count')->unsigned()->isUnsigned)->toBeTrue();
});

it('applies index modifier', function (): void {
    expect(Column::string('slug')->index()->hasIndex)->toBeTrue();
});

it('applies hidden modifier', function (): void {
    expect(Column::string('password')->hidden()->isHidden)->toBeTrue();
});

it('applies cast modifier', function (): void {
    expect(Column::string('status')->cast('App\\Enums\\Status')->castClass)
        ->toBe('App\\Enums\\Status');
});

it('applies references modifier', function (): void {
    expect(Column::id('user_id')->references('users')->referencesTable)
        ->toBe('users');
});

it('chains all modifiers', function (): void {
    $col = Column::string('email')
        ->nullable()
        ->unique()
        ->index()
        ->hidden()
        ->cast('App\\Casts\\Email');

    expect($col->isNullable)->toBeTrue()
        ->and($col->isUnique)->toBeTrue()
        ->and($col->hasIndex)->toBeTrue()
        ->and($col->isHidden)->toBeTrue()
        ->and($col->castClass)->toBe('App\\Casts\\Email');
});

it('creates uuid column', function (): void {
    $col = Column::uuid('token');
    expect($col->name)->toBe('token')
        ->and($col->type)->toBe('uuid');
});

it('creates ulid column', function (): void {
    $col = Column::ulid('token');
    expect($col->name)->toBe('token')
        ->and($col->type)->toBe('ulid');
});

it('creates date column', function (): void {
    $col = Column::date('born_on');
    expect($col->name)->toBe('born_on')
        ->and($col->type)->toBe('date');
});

it('creates time column', function (): void {
    $col = Column::time('starts_at');
    expect($col->name)->toBe('starts_at')
        ->and($col->type)->toBe('time');
});

it('applies useCurrent modifier', function (): void {
    expect(Column::timestamp('fired_at')->useCurrent()->useCurrent)->toBeTrue();
});

it('applies cascadeOnDelete modifier', function (): void {
    expect(Column::id('user_id')->cascadeOnDelete()->onDelete)->toBe('cascade');
});

it('applies nullOnDelete modifier', function (): void {
    expect(Column::id('user_id')->nullOnDelete()->onDelete)->toBe('null');
});

it('applies restrictOnDelete modifier', function (): void {
    expect(Column::id('user_id')->restrictOnDelete()->onDelete)->toBe('restrict');
});
