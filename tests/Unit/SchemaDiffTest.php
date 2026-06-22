<?php

use Boquizo\Hew\Diff\SchemaDiff;
use Boquizo\Hew\Parser\ParsedColumn;
use Boquizo\Hew\Parser\ParsedTable;
use Boquizo\Hew\Schema\Column;
use Boquizo\Hew\Schema\Schema;
use Boquizo\Hew\Schema\Table;

function makeDiff(Schema $desired, array $current = []): SchemaDiff
{
    return new SchemaDiff($desired, $current);
}

function parsedTable(string $name, array $columns = []): ParsedTable
{
    $t = new ParsedTable($name);
    foreach ($columns as $colName => $type) {
        $t->addColumn(new ParsedColumn($colName, $type));
    }

    return $t;
}

it('puts new tables into newTables', function (): void {
    $diff = makeDiff(Schema::define([Table::make('users')->columns([Column::id()])]));

    expect($diff->newTables)->toHaveKey('users')
        ->and($diff->hasChanges())->toBeTrue()
        ->and($diff->isClean())->toBeFalse();
});

it('puts new columns into newColumns', function (): void {
    $schema = Schema::define([
        Table::make('users')->columns([Column::id(), Column::string('avatar')]),
    ]);
    $diff = makeDiff($schema, ['users' => parsedTable('users', ['id' => 'id'])]);

    expect($diff->newColumns)->toHaveKey('users')
        ->and($diff->newColumns['users'][0]->name)->toBe('avatar')
        ->and($diff->newTables)->not->toHaveKey('users');
});

it('puts column removed from schema into droppedColumns', function (): void {
    $diff = makeDiff(
        Schema::define([Table::make('users')->columns([Column::id()])]),
        ['users' => parsedTable('users', ['id' => 'id', 'old_field' => 'string'])],
    );

    expect($diff->newColumns)->toBeEmpty()
        ->and($diff->warnings)->toBeEmpty()
        ->and($diff->droppedColumns)->toHaveKey('users')
        ->and($diff->droppedColumns['users'][0]->name)->toBe('old_field');
});

it('puts type change into modifiedColumns', function (): void {
    $diff = makeDiff(
        Schema::define([Table::make('users')->columns([Column::id(), Column::text('name')])]),
        ['users' => parsedTable('users', ['id' => 'id', 'name' => 'string'])],
    );

    expect($diff->newColumns)->toBeEmpty()
        ->and($diff->warnings)->toBeEmpty()
        ->and($diff->modifiedColumns)->toHaveKey('users')
        ->and($diff->modifiedColumns['users'][0]->name)->toBe('name')
        ->and($diff->modifiedColumns['users'][0]->type)->toBe('text');
});

it('reports clean when schema matches migrations', function (): void {
    $diff = makeDiff(
        Schema::define([Table::make('users')->columns([Column::id(), Column::string('email')])]),
        ['users' => parsedTable('users', ['id' => 'id', 'email' => 'string'])],
    );

    expect($diff->isClean())->toBeTrue()
        ->and($diff->hasChanges())->toBeFalse()
        ->and($diff->warnings)->toBeEmpty();
});

it('adds missing pivot table to newTables for belongsToMany', function (): void {
    $schema = Schema::define([
        Table::make('users')->columns([Column::id()])->belongsToMany('roles'),
        Table::make('roles')->columns([Column::id()]),
    ]);
    $diff = makeDiff($schema, [
        'users' => parsedTable('users', ['id' => 'id']),
        'roles' => parsedTable('roles', ['id' => 'id']),
    ]);

    expect($diff->newTables)->toHaveKey('role_user');
});

it('does not duplicate an existing pivot table', function (): void {
    $schema = Schema::define([
        Table::make('users')->columns([Column::id()])->belongsToMany('roles'),
        Table::make('roles')->columns([Column::id()]),
    ]);
    $diff = makeDiff($schema, [
        'users' => parsedTable('users', ['id' => 'id']),
        'roles' => parsedTable('roles', ['id' => 'id']),
        'role_user' => parsedTable('role_user', ['role_id' => 'foreignId', 'user_id' => 'foreignId']),
    ]);

    expect($diff->newTables)->not->toHaveKey('role_user');
});

it('puts table absent from schema into droppedTables', function (): void {
    $diff = makeDiff(
        Schema::define([Table::make('users')->columns([Column::id()])]),
        [
            'users' => parsedTable('users', ['id' => 'id']),
            'old_table' => parsedTable('old_table', ['id' => 'id']),
        ],
    );

    expect($diff->droppedTables)->toContain('old_table')
        ->and($diff->newTables)->toBeEmpty()
        ->and($diff->hasChanges())->toBeTrue();
});

it('detects timestamps shortcut columns as new', function (): void {
    $diff = makeDiff(
        Schema::define([Table::make('users')->columns([Column::id(), Column::timestamps()])]),
        ['users' => parsedTable('users', ['id' => 'id'])],
    );

    $names = array_map(static fn ($c) => $c->name, $diff->newColumns['users']);
    expect($names)->toContain('created_at')
        ->and($names)->toContain('updated_at');
});
