<?php

declare(strict_types=1);

namespace Boquizo\Hew\Schema;

use Boquizo\Hew\Exceptions\DuplicateTableException;

class Schema
{
    /** @var array<string, Table> */
    private array $tables;

    /** @param array<string, Table> $tables */
    private function __construct(array $tables)
    {
        $this->tables = $tables;
    }

    /**
     * @param  Table[]  $tables
     *
     * @throws DuplicateTableException
     */
    public static function define(array $tables): self
    {
        $seen = [];
        foreach ($tables as $table) {
            if (isset($seen[$table->name])) {
                throw new DuplicateTableException("Duplicate table name: '{$table->name}'");
            }
            $seen[$table->name] = true;
        }

        return new self($seen ? array_combine(
            array_map(static fn (Table $t): string => $t->name, $tables),
            $tables,
        ) : []);
    }

    /** @return array<string, Table> */
    public function getTables(): array
    {
        return $this->tables;
    }

    public function getTable(string $name): ?Table
    {
        return $this->tables[$name] ?? null;
    }

    public function hasTable(string $name): bool
    {
        return isset($this->tables[$name]);
    }
}
