<?php

use Boquizo\Hew\Schema\Column;
use Boquizo\Hew\Schema\Table;

it('creates table with name', function (): void {
    expect(Table::make('users')->name)->toBe('users');
});

it('stores columns', function (): void {
    $col = Column::string('name');
    expect(Table::make('users')->columns([$col])->getColumns())->toBe([$col]);
});

it('expands timestamps shortcut in flat columns', function (): void {
    $flat = Table::make('users')
        ->columns([Column::id(), Column::timestamps()])
        ->getFlatColumns();

    expect($flat)->toHaveCount(3)
        ->and($flat[0]->name)->toBe('id')
        ->and($flat[1]->name)->toBe('created_at')
        ->and($flat[2]->name)->toBe('updated_at');
});

it('hasMany is metadata and returns self', function (): void {
    expect(Table::make('users')->hasMany('posts')->name)->toBe('users');
});

it('hasOne is metadata and returns self', function (): void {
    expect(Table::make('users')->hasOne('profile')->name)->toBe('users');
});

it('belongsTo is metadata and returns self', function (): void {
    $table = Table::make('users')
        ->columns([Column::id()])
        ->belongsTo('companies');

    expect($table->name)->toBe('users');
});

it('generates alphabetical pivot table name for belongsToMany', function (): void {
    expect(Table::make('users')->belongsToMany('roles')->getPivotTableNames())
        ->toBe(['role_user']);
});

it('generates same pivot name regardless of table order', function (): void {
    expect(Table::make('roles')->belongsToMany('users')->getPivotTableNames())
        ->toBe(['role_user']);
});

it('chains multiple relations', function (): void {
    $table = Table::make('users')
        ->columns([Column::id()])
        ->hasMany('posts')
        ->hasOne('profile')
        ->belongsToMany('roles');

    expect($table->getPivotTableNames())->toBe(['role_user']);
});
