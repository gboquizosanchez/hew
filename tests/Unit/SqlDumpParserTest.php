<?php

use Boquizo\Hew\Generator\SchemaRenderer;
use Boquizo\Hew\Parser\SqlDumpParser;

function sqlTable(string $body): string
{
    return "CREATE TABLE `test` (\n{$body}\n) ENGINE=InnoDB;\n";
}

it('parses a simple table from SQL dump', function (): void {
    $sql = sqlTable(<<<'SQL'
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `email` varchar(100) NOT NULL,
  PRIMARY KEY (`id`)
SQL);

    $tables = (new SqlDumpParser)->parse($sql);

    expect($tables)->toHaveKey('test')
        ->and($tables['test']->columns['id']->type)->toBe('id')
        ->and($tables['test']->columns['name']->type)->toBe('string')
        ->and($tables['test']->columns['email']->modifiers['length'])->toBe(100);
});

it('maps tinyint(1) to boolean', function (): void {
    $tables = (new SqlDumpParser)->parse(sqlTable(
        '`id` bigint unsigned NOT NULL AUTO_INCREMENT,'."\n".
        '`active` tinyint(1) NOT NULL DEFAULT \'1\','."\n".
        'PRIMARY KEY (`id`)',
    ));

    expect($tables['test']->columns['active']->type)->toBe('boolean');
});

it('maps char(n) to string with length, not uuid', function (): void {
    $tables = (new SqlDumpParser)->parse(sqlTable(
        '`id` bigint unsigned NOT NULL AUTO_INCREMENT,'."\n".
        '`code` char(36) NOT NULL,'."\n".
        'PRIMARY KEY (`id`)',
    ));

    $col = $tables['test']->columns['code'];
    expect($col->type)->toBe('string')
        ->and($col->modifiers['length'])->toBe(36);
});

it('maps deleted_at nullable timestamp to softDeletes', function (): void {
    $tables = (new SqlDumpParser)->parse(sqlTable(
        '`id` bigint unsigned NOT NULL AUTO_INCREMENT,'."\n".
        '`deleted_at` timestamp NULL DEFAULT NULL,'."\n".
        'PRIMARY KEY (`id`)',
    ));

    expect($tables['test']->columns['deleted_at']->type)->toBe('softDeletes');
});

it('maps remember_token to rememberToken', function (): void {
    $tables = (new SqlDumpParser)->parse(sqlTable(
        '`id` bigint unsigned NOT NULL AUTO_INCREMENT,'."\n".
        '`remember_token` varchar(100) DEFAULT NULL,'."\n".
        'PRIMARY KEY (`id`)',
    ));

    expect($tables['test']->columns['remember_token']->type)->toBe('rememberToken');
});

it('applies foreign key references from CONSTRAINT lines', function (): void {
    $sql = sqlTable(<<<'SQL'
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
SQL);

    $tables = (new SqlDumpParser)->parse($sql);
    $col = $tables['test']->columns['user_id'];

    expect($col->modifiers['references'])->toBe('users')
        ->and($col->modifiers['onDelete'])->toBe('cascade');
});

it('collects multi-column unique constraints', function (): void {
    $sql = sqlTable(<<<'SQL'
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `a` varchar(255) NOT NULL,
  `b` varchar(255) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ab` (`a`,`b`)
SQL);

    $tables = (new SqlDumpParser)->parse($sql);

    expect($tables['test']->uniqueConstraints)->toBe([['a', 'b']]);
});

it('renders a SQL-parsed table through SchemaRenderer', function (): void {
    $sql = sqlTable(<<<'SQL'
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
SQL);

    $tables = (new SqlDumpParser)->parse($sql);
    $output = (new SchemaRenderer)->render($tables);

    expect($output)
        ->toContain('Column::id()')
        ->toContain("Column::string('name')")
        ->toContain('Column::timestamps()')
        ->toContain('Column::softDeletes()');
});
