<?php

declare(strict_types=1);

namespace Boquizo\Hew\Generator;

use Boquizo\Hew\Parser\ParsedColumn;
use Boquizo\Hew\Parser\ParsedTable;
use Illuminate\Support\Str;

class SchemaRenderer
{
    /**
     * @param  array<string, ParsedTable>  $tables
     * @param  string[]  $extraUseStatements  Additional use statements (e.g. from external class constants)
     */
    public function render(array $tables, array $extraUseStatements = []): string
    {
        $hasManyIndex = $this->buildHasManyIndex($tables);

        $blocks = [];
        foreach ($tables as $table) {
            $blocks[] = $this->renderTable($table, $hasManyIndex[$table->name] ?? []);
        }

        $useBlock = "use Boquizo\\Hew\\Schema\\Column;\nuse Boquizo\\Hew\\Schema\\Schema;\nuse Boquizo\\Hew\\Schema\\Table;";
        foreach ($extraUseStatements as $fqcn) {
            $useBlock .= "\nuse {$fqcn};";
        }

        return "<?php\n\ndeclare(strict_types=1);\n\n{$useBlock}\n\nreturn Schema::define([\n\n"
            .implode("\n\n", $blocks)
            ."\n\n]);\n";
    }

    /**
     * @param  array<string, ParsedTable>  $tables
     * @return array<string, string[]>  referenced_table => [referencing_tables]
     */
    private function buildHasManyIndex(array $tables): array
    {
        $index = [];
        foreach ($tables as $table) {
            foreach ($table->columns as $col) {
                $ref = $col->modifiers['references'] ?? null;
                if (is_string($ref) && ! in_array($table->name, $index[$ref] ?? [], true)) {
                    $index[$ref][] = $table->name;
                }
            }
        }

        return $index;
    }

    /** @param string[] $hasMany */
    private function renderTable(ParsedTable $table, array $hasMany): string
    {
        $lines = [];
        foreach ($this->collapseTimestamps($table->columns) as $col) {
            $lines[] = '            '.$this->renderColumn($col).',';
        }

        $belongsTo = [];
        foreach ($table->columns as $col) {
            $ref = $col->modifiers['references'] ?? null;
            if (is_string($ref) && ! in_array($ref, $belongsTo, true)) {
                $belongsTo[] = $ref;
            }
        }

        $relChain = '';
        foreach ($belongsTo as $ref) {
            $relChain .= "\n        ->belongsTo('{$ref}')";
        }
        foreach ($hasMany as $other) {
            $relChain .= "\n        ->hasMany('{$other}')";
        }
        foreach ($table->uniqueConstraints as $cols) {
            $relChain .= "\n        ->unique(['".(implode("', '", $cols))."'])";
        }
        foreach ($table->indexConstraints as $cols) {
            $relChain .= "\n        ->index(['".(implode("', '", $cols))."'])";
        }

        return "    Table::make('{$table->name}')\n"
            ."        ->columns([\n"
            .implode("\n", $lines)."\n"
            ."        ])"
            .$relChain
            .",";
    }

    private function renderColumn(ParsedColumn $col): string
    {
        $factory = $this->columnFactory($col);
        if (str_starts_with($factory, '// TODO:')) {
            return $factory;
        }

        return $factory.$this->renderModifiers($col);
    }

    private function columnFactory(ParsedColumn $col): string
    {
        return match ($col->type) {
            'id' => $col->name === 'id' ? 'Column::id()' : "Column::id('{$col->name}')",
            'timestamps' => 'Column::timestamps()',
            'softDeletes' => 'Column::softDeletes()',
            'string', 'char' => isset($col->modifiers['length'])
                ? "Column::string('{$col->name}', length: {$col->modifiers['length']})"
                : "Column::string('{$col->name}')",
            'text' => "Column::text('{$col->name}')",
            'longText' => "Column::text('{$col->name}')->long()",
            'mediumText' => "Column::text('{$col->name}')->medium()",
            'tinyText' => "Column::text('{$col->name}')->tiny()",
            'integer' => "Column::integer('{$col->name}')",
            'smallInteger' => "Column::integer('{$col->name}')->small()",
            'tinyInteger' => "Column::integer('{$col->name}')->tiny()",
            'mediumInteger' => "Column::integer('{$col->name}')->medium()",
            'unsignedInteger' => "Column::integer('{$col->name}')->unsigned()",
            'unsignedMediumInteger' => "Column::integer('{$col->name}')->medium()->unsigned()",
            'unsignedSmallInteger' => "Column::integer('{$col->name}')->small()->unsigned()",
            'unsignedTinyInteger' => "Column::integer('{$col->name}')->tiny()->unsigned()",
            'bigIncrements' => $col->name === 'id' ? 'Column::id()' : "Column::id('{$col->name}')",
            'bigInteger' => "Column::integer('{$col->name}')->big()",
            'unsignedBigInteger' => "Column::integer('{$col->name}')->big()->unsigned()",
            'decimal' => isset($col->modifiers['precision'])
                ? sprintf("Column::decimal('%s', %d, %d)", $col->name, (int) $col->modifiers['precision'], (int) ($col->modifiers['scale'] ?? 2))
                : "Column::decimal('{$col->name}')",
            'float', 'double' => "Column::float('{$col->name}')",
            'boolean' => "Column::boolean('{$col->name}')",
            'json', 'jsonb' => "Column::json('{$col->name}')",
            'timestamp', 'timestampTz' => "Column::timestamp('{$col->name}')",
            'dateTime' => "Column::dateTime('{$col->name}')",
            'dateTimeTz' => "Column::dateTimeTz('{$col->name}')",
            'foreignId' => isset($col->modifiers['references'])
                ? "Column::id('{$col->name}')->foreign()"
                : "Column::id('{$col->name}')",
            'foreignUuid' => isset($col->modifiers['references'])
                ? "Column::uuid('{$col->name}')->foreign()"
                : "Column::uuid('{$col->name}')",
            'foreignUlid' => isset($col->modifiers['references'])
                ? "Column::ulid('{$col->name}')->foreign()"
                : "Column::ulid('{$col->name}')",
            'rememberToken' => 'Column::rememberToken()',
            'morphs' => "Column::morphs('{$col->name}')",
            'nullableMorphs' => "Column::morphs('{$col->name}')->nullable()",
            'uuidMorphs' => "Column::uuid('{$col->name}')->morphs()",
            'nullableUuidMorphs' => "Column::uuid('{$col->name}')->morphs()->nullable()",
            'ulidMorphs' => "Column::ulid('{$col->name}')->morphs()",
            'nullableUlidMorphs' => "Column::ulid('{$col->name}')->morphs()->nullable()",
            'uuid' => "Column::uuid('{$col->name}')",
            'ulid' => "Column::ulid('{$col->name}')",
            'date' => "Column::date('{$col->name}')",
            'time', 'timeTz' => "Column::time('{$col->name}')",
            'binary' => "Column::binary('{$col->name}')",
            'ipAddress' => "Column::ipAddress('{$col->name}')",
            'macAddress' => "Column::macAddress('{$col->name}')",
            'year' => "Column::year('{$col->name}')",
            default => "// TODO: unsupported type \"{$col->type}\" — add manually",
        };
    }

    private function renderModifiers(ParsedColumn $col): string
    {
        $m = $col->modifiers;
        $chain = '';

        if (! empty($m['primary'])) {
            $chain .= '->primary()';
        }
        if (! empty($m['nullable'])) {
            $chain .= '->nullable()';
        }

        if (isset($m['references']) && is_string($m['references'])) {
            $chain .= $m['references'] === $this->autoTable($col->name)
                ? '->references()'
                : "->references('{$m['references']}')";
        }
        if (isset($m['onDelete'])) {
            $chain .= match ($m['onDelete']) {
                'cascade' => '->cascadeOnDelete()',
                'null' => '->nullOnDelete()',
                'restrict' => '->restrictOnDelete()',
                default => '',
            };
        }
        if (isset($m['onUpdate']) && is_string($m['onUpdate'])) {
            $chain .= match ($m['onUpdate']) {
                'cascade' => '->cascadeOnUpdate()',
                'null' => '->nullOnUpdate()',
                'restrict' => '->restrictOnUpdate()',
                default => '',
            };
        }
        if (! empty($m['useCurrent'])) {
            $chain .= '->useCurrent()';
        }
        if (isset($m['default']) && is_scalar($m['default'])) {
            $chain .= '->default('.$this->renderDefault((string) $m['default']).')';
        }
        if (! empty($m['unique'])) {
            $chain .= '->unique()';
        }
        if (! empty($m['unsigned'])) {
            $chain .= '->unsigned()';
        }
        if (! empty($m['index'])) {
            $chain .= '->index()';
        }

        return $chain;
    }

    private function autoTable(string $colName): ?string
    {
        return str_ends_with($colName, '_id')
            ? Str::plural(substr($colName, 0, -3))
            : null;
    }

    private function renderDefault(string $raw): string
    {
        $v = trim($raw);
        if ($v === 'true') {
            return 'true';
        }
        if ($v === 'false') {
            return 'false';
        }
        if ($v === 'null') {
            return 'null';
        }
        if (is_numeric($v)) {
            return $v;
        }
        if (preg_match('/^[\'"](.*)[\'"]\s*$/s', $v, $m)) {
            return "'".addslashes($m[1])."'";
        }

        return $v; // PHP expression — emit as-is
    }

    /**
     * @param  array<string, ParsedColumn>  $columns
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
}
