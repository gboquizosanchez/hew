<?php

declare(strict_types=1);

namespace Boquizo\Hew\Schema;

use Illuminate\Support\Str;


class Table
{
    public readonly string $name;

    /** @var ColumnDef[] */
    private array $columns = [];

    /** @var array<int, string[]> */
    private array $uniqueConstraints = [];

    /** @var array<int, string[]> */
    private array $indexConstraints = [];

    /** @var string[] */
    private array $hasManyRelations = [];

    /** @var string[] */
    private array $hasOneRelations = [];

    /** @var string[] */
    private array $belongsToRelations = [];

    /** @var string[] */
    private array $belongsToManyRelations = [];

    private function __construct(string $name)
    {
        $this->name = $name;
    }

    public static function make(string $name): self
    {
        return new self($name);
    }

    /** @param ColumnDef[] $columns */
    public function columns(array $columns): self
    {
        $this->columns = $columns;

        return $this;
    }

    /** @param string[] $columns */
    public function unique(array $columns): self
    {
        $this->uniqueConstraints[] = $columns;

        return $this;
    }

    /** @param string[] $columns */
    public function index(array $columns): self
    {
        $this->indexConstraints[] = $columns;

        return $this;
    }

    /** @return array<int, string[]> */
    public function getUniqueConstraints(): array
    {
        return $this->uniqueConstraints;
    }

    /** @return array<int, string[]> */
    public function getIndexConstraints(): array
    {
        return $this->indexConstraints;
    }

    public function hasMany(string $related): self
    {
        $this->hasManyRelations[] = $related;

        return $this;
    }

    public function hasOne(string $related): self
    {
        $this->hasOneRelations[] = $related;

        return $this;
    }

    public function belongsTo(string $related): self
    {
        $this->belongsToRelations[] = $related;

        return $this;
    }

    public function belongsToMany(string $related): self
    {
        $this->belongsToManyRelations[] = $related;

        return $this;
    }

    /** @return ColumnDef[] */
    public function getColumns(): array
    {
        return $this->columns;
    }

    /** @return Column[] flattened, shortcuts expanded */
    public function getFlatColumns(): array
    {
        $flat = [];
        foreach ($this->columns as $col) {
            if ($col->isShortcut()) {
                foreach ($col->children() as $child) {
                    $flat[] = $child;
                }
            } else {
                $flat[] = $col;
            }
        }

        return $flat;
    }

    /** @return string[] */
    public function getHasManyRelations(): array
    {
        return $this->hasManyRelations;
    }

    /** @return string[] */
    public function getHasOneRelations(): array
    {
        return $this->hasOneRelations;
    }

    /** @return string[] */
    public function getBelongsToRelations(): array
    {
        return $this->belongsToRelations;
    }

    /** @return string[] */
    public function getBelongsToManyRelations(): array
    {
        return $this->belongsToManyRelations;
    }

    /** @return string[] pivot table names derived from belongsToMany */
    public function getPivotTableNames(): array
    {
        $pivots = [];
        foreach ($this->belongsToManyRelations as $related) {
            $parts = [$this->singularize($this->name), $this->singularize($related)];
            sort($parts);
            $pivots[] = implode('_', $parts);
        }

        return $pivots;
    }

    private function singularize(string $word): string
    {
        return Str::singular($word);
    }
}
