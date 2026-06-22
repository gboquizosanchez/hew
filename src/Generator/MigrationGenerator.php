<?php

declare(strict_types=1);

namespace Boquizo\Hew\Generator;

use Boquizo\Hew\Diff\SchemaDiff;
use Boquizo\Hew\Exceptions\MigrationAlreadyExistsException;
use Boquizo\Hew\Parser\ParsedColumn;
use Boquizo\Hew\Parser\ParsedTable;
use Boquizo\Hew\Schema\ColumnDef;
use Boquizo\Hew\Schema\Table;
use Illuminate\Support\Str;

class MigrationGenerator
{
    private string $createStub;

    private string $addColumnsStub;

    private string $dropTableStub;

    private string $dropColumnsStub;

    private string $modifyColumnsStub;

    public function __construct(
        private readonly string $migrationsPath,
        private readonly string $stubsPath,
        private readonly ?string $datePrefix = null,
    ) {
        $this->createStub = (string) file_get_contents($this->stubsPath.'/create_table.stub');
        $this->addColumnsStub = (string) file_get_contents($this->stubsPath.'/add_columns.stub');
        $this->dropTableStub = (string) file_get_contents($this->stubsPath.'/drop_table.stub');
        $this->dropColumnsStub = (string) file_get_contents($this->stubsPath.'/drop_columns.stub');
        $this->modifyColumnsStub = (string) file_get_contents($this->stubsPath.'/modify_columns.stub');
    }

    /**
     * Generate migration files from a diff.
     *
     * @return string[] list of generated file paths
     *
     * @throws MigrationAlreadyExistsException
     */
    public function generate(SchemaDiff $diff): array
    {
        $generated = [];
        $seq = 0;

        // Destructive first so FKs are gone before new structure lands
        foreach ($diff->droppedColumns as $tableName => $cols) {
            $filename = $this->filename('drop_from', $tableName, $seq++);
            $path = $this->migrationsPath.'/'.$filename;
            $this->writeAtomic($path, $this->renderDropColumns($tableName, $cols));
            $generated[] = $path;
        }

        foreach ($diff->droppedTables as $tableName) {
            $filename = $this->filename('drop', $tableName, $seq++);
            $path = $this->migrationsPath.'/'.$filename;
            $this->writeAtomic($path, $this->renderDropTable($tableName, $diff->droppedTablesParsed[$tableName]));
            $generated[] = $path;
        }

        foreach ($diff->modifiedColumns as $tableName => $columns) {
            $filename = $this->filename('modify', $tableName, $seq++);
            $path = $this->migrationsPath.'/'.$filename;
            $this->writeAtomic($path, $this->renderModifyColumns($tableName, $columns, $diff->originalModifiedColumns[$tableName] ?? []));
            $generated[] = $path;
        }

        foreach ($diff->newTables as $tableName => $table) {
            $filename = $this->filename('create', $tableName, $seq++);
            $path = $this->migrationsPath.'/'.$filename;
            $this->writeAtomic($path, $this->renderCreate($table));
            $generated[] = $path;
        }

        foreach ($diff->newColumns as $tableName => $columns) {
            $filename = $this->filename('add_to', $tableName, $seq++);
            $path = $this->migrationsPath.'/'.$filename;
            $this->writeAtomic($path, $this->renderAddColumns($tableName, $columns));
            $generated[] = $path;
        }

        return $generated;
    }

    private function renderCreate(Table $table): string
    {
        $lines = [];
        foreach ($table->getColumns() as $col) {
            $lines[] = '            '.$this->columnToBlueprint($col).';';
        }
        foreach ($table->getUniqueConstraints() as $cols) {
            $lines[] = "            \$table->unique(['".(implode("', '", $cols))."']);";
        }
        foreach ($table->getIndexConstraints() as $cols) {
            $lines[] = "            \$table->index(['".(implode("', '", $cols))."']);";
        }

        return str_replace(
            ['{{ table }}', '{{ columns }}'],
            [$table->name, implode("\n", $lines)],
            $this->createStub,
        );
    }

    /** @param ColumnDef[] $columns */
    private function renderAddColumns(string $tableName, array $columns): string
    {
        $upLines = [];
        $downNames = [];
        foreach ($columns as $col) {
            $upLines[] = '            '.$this->columnToBlueprint($col).';';
            $downNames[] = "'{$col->name}'";
        }

        $downLine = '            $table->dropColumn(['.implode(', ', $downNames).']);';

        return str_replace(
            ['{{ table }}', '{{ columns }}', '{{ down_columns }}'],
            [$tableName, implode("\n", $upLines), $downLine],
            $this->addColumnsStub,
        );
    }

    private function renderDropTable(string $tableName, ParsedTable $parsedTable): string
    {
        $lines = [];
        foreach ($this->collapseTimestamps($parsedTable->columns) as $col) {
            $lines[] = '            '.$this->parsedColumnToBlueprint($col).';';
        }
        foreach ($parsedTable->uniqueConstraints as $cols) {
            $lines[] = "            \$table->unique(['".(implode("', '", $cols))."']);";
        }
        foreach ($parsedTable->indexConstraints as $cols) {
            $lines[] = "            \$table->index(['".(implode("', '", $cols))."']);";
        }

        return str_replace(
            ['{{ table }}', '{{ recreate_body }}'],
            [$tableName, implode("\n", $lines)],
            $this->dropTableStub,
        );
    }

    /** @param ParsedColumn[] $cols */
    private function renderDropColumns(string $tableName, array $cols): string
    {
        $dropLines = [];
        $fkCols = array_filter($cols, static fn (ParsedColumn $c): bool => isset($c->modifiers['references']));
        if ($fkCols !== []) {
            $fkNames = implode(', ', array_map(static fn (ParsedColumn $c): string => "'{$c->name}'", $fkCols));
            $dropLines[] = "            \$table->dropForeign([{$fkNames}]);";
        }
        $names = implode(', ', array_map(static fn (ParsedColumn $c): string => "'{$c->name}'", $cols));
        $dropLines[] = "            \$table->dropColumn([{$names}]);";

        $addLines = [];
        foreach ($cols as $col) {
            $addLines[] = '            '.$this->parsedColumnToBlueprint($col).';';
        }

        return str_replace(
            ['{{ table }}', '{{ drop_lines }}', '{{ add_lines }}'],
            [$tableName, implode("\n", $dropLines), implode("\n", $addLines)],
            $this->dropColumnsStub,
        );
    }

    /** @param ColumnDef[] $columns @param ParsedColumn[] $originalColumns */
    private function renderModifyColumns(string $tableName, array $columns, array $originalColumns = []): string
    {
        $lines = [];
        foreach ($columns as $col) {
            $lines[] = '            '.$this->columnToBlueprint($col).'->change();';
        }

        $reverseLines = [];
        foreach ($originalColumns as $col) {
            $reverseLines[] = '            '.$this->parsedColumnToBlueprint($col).'->change();';
        }

        return str_replace(
            ['{{ table }}', '{{ columns }}', '{{ reverse_columns }}'],
            [$tableName, implode("\n", $lines), implode("\n", $reverseLines)],
            $this->modifyColumnsStub,
        );
    }

    private function columnToBlueprint(ColumnDef $col): string
    {
        $chain = match ($col->type) {
            'id' => '$table->id()',
            'timestamps' => '$table->timestamps()',
            'softDeletes' => '$table->softDeletes()',
            'rememberToken' => '$table->rememberToken()',
            'morphs' => $col->isNullable
                ? sprintf('$table->nullableMorphs(\'%s\')', $col->name)
                : sprintf('$table->morphs(\'%s\')', $col->name),
            'uuidMorphs' => $col->isNullable
                ? sprintf('$table->nullableUuidMorphs(\'%s\')', $col->name)
                : sprintf('$table->uuidMorphs(\'%s\')', $col->name),
            'ulidMorphs' => $col->isNullable
                ? sprintf('$table->nullableUlidMorphs(\'%s\')', $col->name)
                : sprintf('$table->ulidMorphs(\'%s\')', $col->name),
            'decimal' => sprintf(
                '$table->decimal(\'%s\', %d, %d)',
                $col->name,
                (int) ($col->parameters[0] ?? 10),
                (int) ($col->parameters[1] ?? 2),
            ),
            'integer' => sprintf('$table->%s(\'%s\')', match (true) {
                $col->isUnsigned && $col->size === 'big' => 'unsignedBigInteger',
                $col->isUnsigned && $col->size === 'tiny' => 'unsignedTinyInteger',
                $col->isUnsigned && $col->size === 'small' => 'unsignedSmallInteger',
                $col->isUnsigned && $col->size === 'medium' => 'unsignedMediumInteger',
                $col->isUnsigned => 'unsignedInteger',
                $col->size === 'big' => 'bigInteger',
                $col->size === 'tiny' => 'tinyInteger',
                $col->size === 'small' => 'smallInteger',
                $col->size === 'medium' => 'mediumInteger',
                default => 'integer',
            }, $col->name),
            'text' => sprintf('$table->%s(\'%s\')', match ($col->size) {
                'long' => 'longText',
                'medium' => 'mediumText',
                'tiny' => 'tinyText',
                default => 'text',
            }, $col->name),
            'string', 'char' => isset($col->parameters[0])
                ? sprintf('$table->%s(\'%s\', %d)', $col->type, $col->name, (int) $col->parameters[0])
                : sprintf('$table->%s(\'%s\')', $col->type, $col->name),
            default => sprintf('$table->%s(\'%s\')', $col->type, $col->name),
        };

        if ($col->isNullable && !in_array($col->type, ['morphs', 'uuidMorphs'], true)) {
            $chain .= '->nullable()';
        }

        if (in_array($col->type, ['foreignId', 'foreignUuid', 'foreignUlid'], true) && $col->referencesTable !== null) {
            $autoTable = str_ends_with($col->name, '_id')
                ? Str::plural(substr($col->name, 0, -3))
                : null;
            $chain .= $col->referencesTable === $autoTable
                ? '->constrained()'
                : sprintf('->constrained(\'%s\')', $col->referencesTable);
            if ($col->onDelete !== null) {
                $chain .= match ($col->onDelete) {
                    'cascade' => '->cascadeOnDelete()',
                    'null' => '->nullOnDelete()',
                    'restrict' => '->restrictOnDelete()',
                    default => '',
                };
            }
            if ($col->onUpdate !== null) {
                $chain .= match ($col->onUpdate) {
                    'cascade' => '->cascadeOnUpdate()',
                    'null' => '->nullOnUpdate()',
                    'restrict' => '->restrictOnUpdate()',
                    default => '',
                };
            }
        }

        if ($col->hasDefault) {
            $chain .= '->default('.$this->renderDefault($col->defaultValue).')';
        }

        if ($col->isPrimary) {
            $chain .= '->primary()';
        }

        if ($col->isUnique) {
            $chain .= '->unique()';
        }

        if ($col->hasIndex) {
            $chain .= '->index()';
        }

        if ($col->useCurrent) {
            $chain .= '->useCurrent()';
        }

        return $chain;
    }

    private function renderDefault(string|int|float|bool|null $value): string
    {
        if (is_bool($value)) {
            if ($value) {
                return 'true';
            }
            return 'false';
        }

        if ($value === null) {
            return 'null';
        }

        if (is_string($value)) {
            return "'".addslashes($value)."'";
        }

        return (string) $value;
    }

    private function parsedColumnToBlueprint(ParsedColumn $col): string
    {
        $m = $col->modifiers;
        $type = $col->type;

        // Handle shortcut types
        if ($type === 'timestamps') {
            return '$table->timestamps()';
        }
        if ($type === 'softDeletes') {
            return '$table->softDeletes()';
        }
        if ($type === 'rememberToken') {
            return '$table->rememberToken()';
        }

        // Handle id/bigIncrements
        if ($type === 'id' || $type === 'bigIncrements') {
            return $col->name === 'id' ? '$table->id()' : sprintf('$table->id(\'%s\')', $col->name);
        }

        // Handle morphs types
        if (in_array($type, ['morphs', 'nullableMorphs', 'uuidMorphs', 'nullableUuidMorphs', 'ulidMorphs', 'nullableUlidMorphs'], true)) {
            $chain = match ($type) {
                'morphs' => sprintf('$table->morphs(\'%s\')', $col->name),
                'nullableMorphs' => sprintf('$table->nullableMorphs(\'%s\')', $col->name),
                'uuidMorphs' => sprintf('$table->uuidMorphs(\'%s\')', $col->name),
                'nullableUuidMorphs' => sprintf('$table->nullableUuidMorphs(\'%s\')', $col->name),
                'ulidMorphs' => sprintf('$table->ulidMorphs(\'%s\')', $col->name),
                'nullableUlidMorphs' => sprintf('$table->nullableUlidMorphs(\'%s\')', $col->name),
            };
            return $chain;
        }

        // Handle string/char with length
        if (in_array($type, ['string', 'char'], true) && isset($m['length'])) {
            $chain = sprintf('$table->%s(\'%s\', %d)', $type, $col->name, $m['length']);
        } else {
            $chain = sprintf('$table->%s(\'%s\')', $type, $col->name);
        }

        // Apply modifiers
        if (!empty($m['primary'])) {
            $chain .= '->primary()';
        }
        if (!empty($m['nullable'])) {
            $chain .= '->nullable()';
        }
        if (!empty($m['unique'])) {
            $chain .= '->unique()';
        }
        if (!empty($m['unsigned'])) {
            $chain .= '->unsigned()';
        }
        if (!empty($m['index'])) {
            $chain .= '->index()';
        }
        if (!empty($m['useCurrent'])) {
            $chain .= '->useCurrent()';
        }
        if (isset($m['default']) && is_scalar($m['default'])) {
            $chain .= '->default('.$this->renderDefault((string) $m['default']).')';
        }
        if (isset($m['references']) && is_string($m['references'])) {
            $autoTable = str_ends_with($col->name, '_id')
                ? Str::plural(substr($col->name, 0, -3))
                : null;
            $chain .= $m['references'] === $autoTable
                ? '->constrained()'
                : sprintf('->constrained(\'%s\')', $m['references']);
        }
        if (isset($m['onDelete'])) {
            $chain .= match ($m['onDelete']) {
                'cascade' => '->cascadeOnDelete()',
                'null' => '->nullOnDelete()',
                'restrict' => '->restrictOnDelete()',
                default => '',
            };
        }
        if (isset($m['onUpdate'])) {
            $chain .= match ($m['onUpdate']) {
                'cascade' => '->cascadeOnUpdate()',
                'null' => '->nullOnUpdate()',
                'restrict' => '->restrictOnUpdate()',
                default => '',
            };
        }

        return $chain;
    }

    /**
     * @param array<string, ParsedColumn> $columns
     * @return ParsedColumn[]
     */
    private function collapseTimestamps(array $columns): array
    {
        $result = [];
        $cols = array_values($columns);
        $i = 0;

        while ($i < count($cols)) {
            $cur = $cols[$i];
            $next = $cols[$i + 1] ?? null;

            if (
                $cur->name === 'created_at'
                && $cur->type === 'timestamp'
                && $cur->modifiers === []
                && $next !== null
                && $next->name === 'updated_at'
                && $next->type === 'timestamp'
                && $next->modifiers === []
            ) {
                $result[] = new ParsedColumn('timestamps', 'timestamps');
                $i += 2;

                continue;
            }

            $result[] = $cur;
            $i++;
        }

        return $result;
    }

    /** @throws MigrationAlreadyExistsException */
    private function writeAtomic(string $path, string $content): void
    {
        $fh = @fopen($path, 'xb');
        if ($fh === false) {
            throw new MigrationAlreadyExistsException("Migration already exists: {$path}");
        }
        fwrite($fh, $content);
        fclose($fh);
    }

    private function filename(string $action, string $table, int $sequence): string
    {
        $date = $this->datePrefix ?? date('Y_m_d');
        $time = str_pad((string) ($sequence % 1000000), 6, '0', STR_PAD_LEFT);
        $slug = match ($action) {
            'create' => "create_{$table}_table",
            'add_to' => "add_columns_to_{$table}_table",
            'drop_from' => "drop_columns_from_{$table}_table",
            'drop' => "drop_{$table}_table",
            'modify' => "modify_columns_in_{$table}_table",
            default => "{$action}_{$table}_table",
        };

        $base = "{$date}_{$time}_{$slug}.php";

        // Append suffix if name collision exists
        if (! file_exists($this->migrationsPath.'/'.$base)) {
            return $base;
        }

        $suffix = 2;
        while ($suffix <= 10 && file_exists($this->migrationsPath.'/'.rtrim($base, '.php')."_{$suffix}.php")) {
            $suffix++;
        }
        if ($suffix > 10) {
            throw new MigrationAlreadyExistsException(
                "Too many migrations for {$table} on this date. Use --path or rename existing files.",
            );
        }

        return rtrim($base, '.php')."_{$suffix}.php";
    }
}
