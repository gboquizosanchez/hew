<?php

use Boquizo\Hew\Exceptions\DuplicateTableException;
use Boquizo\Hew\Schema\Column;
use Boquizo\Hew\Schema\Schema;
use Boquizo\Hew\Schema\Table;

it('defines an empty schema', function (): void {
    expect(Schema::define([])->getTables())->toBe([]);
});

it('defines a schema with a single table', function (): void {
    $schema = Schema::define([
        Table::make('users')->columns([Column::id()]),
    ]);
    expect($schema->hasTable('users'))->toBeTrue();
});

it('defines a schema with multiple tables', function (): void {
    $schema = Schema::define([
        Table::make('users')->columns([Column::id()]),
        Table::make('posts')->columns([Column::id()]),
    ]);
    expect($schema->getTables())->toHaveCount(2);
});

it('retrieves a table by name', function (): void {
    $table = Table::make('users')->columns([Column::id()]);
    expect(Schema::define([$table])->getTable('users'))->toBe($table);
});

it('returns null for a missing table', function (): void {
    expect(Schema::define([])->getTable('missing'))->toBeNull();
});

it('throws DuplicateTableException for duplicate table names', function (): void {
    expect(static fn (): \Boquizo\Hew\Schema\Schema => Schema::define([
        Table::make('users')->columns([Column::id()]),
        Table::make('users')->columns([Column::string('name')]),
    ]))->toThrow(DuplicateTableException::class, 'users');
});
