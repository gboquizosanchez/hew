<?php

declare(strict_types=1);

namespace Boquizo\Hew\Diff;

use Boquizo\Hew\Parser\ParsedColumn;
use Boquizo\Hew\Parser\ParsedTable;
use Boquizo\Hew\Schema\Column;
use Boquizo\Hew\Schema\ColumnDef;
use Boquizo\Hew\Schema\Schema;
use Boquizo\Hew\Schema\Table;

class SchemaDiff
{
    /** @var array<string, Table> */
    public readonly array $newTables;

    /** @var array<string, ColumnDef[]> */
    public readonly array $newColumns;

    /** @var string[] Tables present in migrations but absent in schema */
    public readonly array $droppedTables;

    /** @var array<string, ParsedColumn[]> Columns present in migrations but absent in schema */
    public readonly array $droppedColumns;

    /** @var array<string, ColumnDef[]> Columns whose type changed — desired state, rendered with ->change() */
    public readonly array $modifiedColumns;

    /** @var array<string, ParsedTable> Dropped tables with full definition for rollback */
    public readonly array $droppedTablesParsed;

    /** @var array<string, ParsedColumn[]> Original columns before modification for rollback */
    public readonly array $originalModifiedColumns;

    /** @var string[] */
    public readonly array $warnings;

    /**
     * @param  array<string, ParsedTable>  $current  Parsed state of existing migrations
     */
    public function __construct(Schema $desired, array $current)
    {
        $newTables = [];
        $newColumns = [];
        $droppedColumns = [];
        $modifiedColumns = [];
        $originalModifiedColumns = [];
        $warnings = [];

        $desiredTableNames = array_keys($desired->getTables());

        foreach ($desired->getTables() as $tableName => $table) {
            if (! isset($current[$tableName])) {
                $newTables[$tableName] = $table;

                continue;
            }

            $currentTable = $current[$tableName];
            $addedColumns = [];

            foreach ($table->getFlatColumns() as $column) {
                if (! isset($currentTable->columns[$column->name])) {
                    $addedColumns[] = $column;
                } else {
                    $currentType = $currentTable->columns[$column->name]->type;
                    if (! $this->typesCompatible($column->type, $currentType)) {
                        $modifiedColumns[$tableName][] = $column;
                        $originalModifiedColumns[$tableName][] = $currentTable->columns[$column->name];
                    }
                }
            }

            if ($addedColumns !== []) {
                $newColumns[$tableName] = $addedColumns;
            }

            // Columns present in migrations but absent in schema → drop migration
            foreach ($currentTable->columns as $colName => $parsedCol) {
                $exists = false;
                foreach ($table->getFlatColumns() as $schemaCol) {
                    if ($schemaCol->name === $colName) {
                        $exists = true;
                        break;
                    }
                }
                if (! $exists) {
                    $droppedColumns[$tableName][] = $parsedCol;
                }
            }
        }

        // Tables present in migrations but absent in schema → drop migration
        $droppedTables = [];
        $droppedTablesParsed = [];
        foreach (array_keys($current) as $tableName) {
            if (! in_array($tableName, $desiredTableNames, true)) {
                $droppedTables[] = $tableName;
                $droppedTablesParsed[$tableName] = $current[$tableName];
            }
        }

        // Detect missing pivot tables for belongsToMany
        foreach ($desired->getTables() as $tableName => $table) {
            foreach ($table->getPivotTableNames() as $pivotName) {
                if (! isset($current[$pivotName]) && ! isset($newTables[$pivotName])) {
                    $newTables[$pivotName] = $this->buildPivotTable($pivotName, $tableName, $table);
                }
            }
        }

        $this->newTables = $newTables;
        $this->newColumns = $newColumns;
        $this->droppedTables = $droppedTables;
        $this->droppedColumns = $droppedColumns;
        $this->modifiedColumns = $modifiedColumns;
        $this->droppedTablesParsed = $droppedTablesParsed;
        $this->originalModifiedColumns = $originalModifiedColumns;
        $this->warnings = $warnings;
    }

    public function hasChanges(): bool
    {
        return $this->newTables !== [] || $this->newColumns !== []
            || $this->droppedTables !== [] || $this->droppedColumns !== []
            || $this->modifiedColumns !== [];
    }

    public function isClean(): bool
    {
        return ! $this->hasChanges();
    }

    private function buildPivotTable(string $pivotName, string $ownerTable, Table $ownerDef): Table
    {
        // Derive the two foreignId column names from the pivot table name
        $parts = explode('_', $pivotName);
        // ponytail: naive pivot column derivation — covers standard alphabetical two-word pivots
        $col1 = Column::id($parts[0].'_id')->foreign();
        $col2 = Column::id(isset($parts[1]) ? $parts[1].'_id' : $ownerTable.'_id')->foreign();

        return Table::make($pivotName)->columns([$col1, $col2]);
    }

    /**
     * Check if a schema column type is compatible with a parsed migration column type.
     * Compatible means the types map to the same or equivalent Blueprint method.
     */
    private function typesCompatible(string $schemaType, string $parsedType): bool
    {
        if ($schemaType === $parsedType) {
            return true;
        }

        // Known aliases
        $aliases = [
            'id' => ['id', 'bigIncrements', 'unsignedBigInteger', 'foreignId'],
            'bigInteger' => ['bigInteger', 'bigIncrements'],
            'foreignId' => ['foreignId', 'unsignedBigInteger'],
            'text' => ['text', 'longText', 'mediumText', 'tinyText'],
            'integer' => ['integer', 'bigInteger', 'smallInteger', 'tinyInteger', 'mediumInteger', 'unsignedInteger', 'unsignedTinyInteger', 'unsignedSmallInteger', 'unsignedMediumInteger', 'unsignedBigInteger'],
            'timestamp' => ['timestamp', 'dateTime', 'dateTimeTz'],
            'morphs' => ['morphs', 'nullableMorphs'],
            'uuidMorphs' => ['uuidMorphs', 'nullableUuidMorphs'],
            'ulidMorphs' => ['ulidMorphs', 'nullableUlidMorphs'],
        ];

        foreach ($aliases as $group) {
            if (in_array($schemaType, $group, true) && in_array($parsedType, $group, true)) {
                return true;
            }
        }

        return false;
    }
}
