<?php

declare(strict_types=1);

namespace Boquizo\Hew\Parser;

class ParsedTable
{
    /** @var array<string, ParsedColumn> */
    public array $columns = [];

    /** @var array<int, string[]> */
    public array $uniqueConstraints = [];

    /** @var array<int, string[]> */
    public array $indexConstraints = [];

    public function __construct(public readonly string $name) {}

    public function addColumn(ParsedColumn $column): void
    {
        $this->columns[$column->name] = $column;
    }

    public function removeColumn(string $name): void
    {
        unset($this->columns[$name]);
    }

    public function renameColumn(string $old, string $new): void
    {
        if (! isset($this->columns[$old])) {
            return;
        }
        $col = $this->columns[$old];
        $renamed = new ParsedColumn($new, $col->type, $col->modifiers);
        $result = [];
        foreach ($this->columns as $key => $existing) {
            $result[$key === $old ? $new : $key] = $key === $old ? $renamed : $existing;
        }
        $this->columns = $result;
    }

    public function insertAfter(string $after, ParsedColumn $col): void
    {
        $result = [];
        $inserted = false;
        foreach ($this->columns as $key => $existing) {
            $result[$key] = $existing;
            if ($key === $after && ! $inserted) {
                $result[$col->name] = $col;
                $inserted = true;
            }
        }
        if (! $inserted) {
            $result[$col->name] = $col;
        }
        $this->columns = $result;
    }
}
