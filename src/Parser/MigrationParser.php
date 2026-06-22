<?php

declare(strict_types=1);

namespace Boquizo\Hew\Parser;

class MigrationParser
{
    /** @var array<string, ParsedTable> */
    private array $tables;

    private string $projectRoot;

    /** @param array<string, ParsedTable> $seed Initial table state (e.g. from a schema dump) */
    public function __construct(array $seed = [], ?string $projectRoot = null)
    {
        $this->tables = $seed;
        $this->projectRoot = $projectRoot ?? dirname(__DIR__, 2);
    }

    /** @var string[] */
    private array $unparseable = [];

    /** @var string[] */
    private array $destructiveOps = [];

    /** @var string[] column types that map to Blueprint methods */
    private const COLUMN_TYPES = [
        // Incrementing IDs
        'id', 'bigIncrements', 'increments', 'tinyIncrements', 'smallIncrements',
        'mediumIncrements', 'integerIncrements',
        // Integers
        'integer', 'tinyInteger', 'smallInteger', 'mediumInteger', 'bigInteger',
        'unsignedInteger', 'unsignedTinyInteger', 'unsignedSmallInteger',
        'unsignedMediumInteger', 'unsignedBigInteger', 'unsignedDecimal',
        // Text
        'string', 'char', 'text', 'tinyText', 'mediumText', 'longText',
        // Numeric
        'decimal', 'float', 'double',
        // Boolean
        'boolean',
        // JSON
        'json', 'jsonb',
        // Date / Time
        'timestamp', 'timestampTz', 'timestamps', 'timestampsTz', 'nullableTimestamps',
        'dateTime', 'dateTimeTz', 'date', 'time', 'timeTz', 'year',
        // Soft deletes
        'softDeletes', 'softDeletesTz',
        // UUID / ULID
        'uuid', 'ulid', 'foreignUuid', 'foreignUlid',
        // Morphs
        'morphs', 'nullableMorphs', 'uuidMorphs', 'nullableUuidMorphs',
        'ulidMorphs', 'nullableUlidMorphs',
        // Special
        'foreignId', 'foreignIdFor', 'rememberToken',
        'binary', 'ipAddress', 'macAddress',
        'enum', 'set',
        // Spatial
        'geometry', 'point', 'lineString', 'polygon',
        'geometryCollection', 'multiPoint', 'multiLineString', 'multiPolygon', 'multiPolygonZ',
        'geography',
        // Modern (L11+)
        'vector', 'computed',
    ];

    /**
     * Parse all migrations in a directory.
     *
     * @param  string  $migrationsPath  Absolute path to database/migrations/
     * @return array<string, ParsedTable>
     */
    public function parse(string $migrationsPath): array
    {
        if (! is_dir($migrationsPath)) {
            return [];
        }

        $files = glob($migrationsPath.'/*.php') ?: [];
        sort($files);

        foreach ($files as $file) {
            $this->parseFile($file);
        }

        return $this->tables;
    }

    /** @return string[] */
    public function getUnparseable(): array
    {
        return $this->unparseable;
    }

    /** @return string[] */
    public function getDestructiveOps(): array
    {
        return $this->destructiveOps;
    }

    private function resolveClassConstants(string $content, string $filename): string
    {
        // 1. self::CONSTANT resolution
        if (preg_match_all('/const\s+([A-Z_]+)\s*=\s*[\'"]([^\'"]+)[\'"]\s*;/', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $content = preg_replace(
                    '/(?:self|static)::'.preg_quote($m[1], '/').'/',
                    "'{$m[2]}'",
                    $content,
                ) ?? $content;
            }
        }

        // 2. External class constants (e.g., Login::TYPE_LOGIN)
        $content = $this->resolveExternalClassConstants($content);

        // 3. Config::string('key') and config('key') calls
        $content = $this->resolveConfigCalls($content);

        // 4. $variable = 'string' → Schema replacement (after Config so $table = 'activity_log' works)
        if (preg_match_all('/(\$[a-zA-Z_][a-zA-Z0-9_]*)\s*=\s*[\'"]([^\'"]+)[\'"]\s*;/', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $content = preg_replace(
                    '/Schema\s*::\s*(?:connection\s*\([^)]*\)\s*->\s*)?(create|table)\s*\(\s*'.preg_quote($m[1], '/').'/',
                    "Schema::$1('{$m[2]}'",
                    $content,
                ) ?? $content;
            }
        }

        // 5. Fallback: try to resolve remaining $variable in Schema calls via ternary default or filename
        $content = $this->resolveUnresolvedSchemaVars($content, $filename);

        return $content;
    }

    private function resolveUnresolvedSchemaVars(string $content, string $filename): string
    {
        // Check if any Schema::create/table still has an unresolved variable
        $pattern = '/Schema\s*::\s*(?:connection\s*\([^)]*\)\s*->\s*)?(create|table)\s*\(\s*(\$[a-zA-Z_][a-zA-Z0-9_]*)/';
        if (! preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
            return $content;
        }

        $unresolved = [];
        foreach ($matches as $m) {
            $varName = $m[2];
            if (! in_array($varName, $unresolved, true)) {
                $unresolved[] = $varName;
            }
        }

        foreach ($unresolved as $varName) {
            // Try to find $var = ... ? ... : 'default' ternary with string fallback
            $escaped = preg_quote($varName, '/');
            if (preg_match('/'.$escaped.'\s*=\s*[^;]*\?\s*[^:]*:\s*[\'"]([^\'"]+)[\'"]/', $content, $tm)) {
                $content = preg_replace(
                    '/(Schema\s*::\s*(?:connection\s*\([^)]*\)\s*->\s*)?(?:create|table)\s*\(\s*)'.preg_quote($varName, '/').'/',
                    "$1'{$tm[1]}'",
                    $content,
                ) ?? $content;
                continue;
            }

            // Last resort: infer from filename
            $tableName = $this->tableNameFromFilename($filename);
            if ($tableName !== null) {
                $content = preg_replace(
                    '/(Schema\s*::\s*(?:connection\s*\([^)]*\)\s*->\s*)?(?:create|table)\s*\(\s*)'.preg_quote($varName, '/').'/',
                    "$1'{$tableName}'",
                    $content,
                ) ?? $content;
            }
        }

        return $content;
    }

    private function resolveConfigCalls(string $content): string
    {
        // Config::string('key') or Config::get('key', 'default')
        $pattern = '/Config\s*::\s*(?:string|get|get)\s*\(\s*[\'"]([\w.]+)[\'"](?:\s*,\s*[\'"]([^\'"]*)[\'"])?\s*\)/';
        if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $key = $m[1];
                $default = $m[2] ?? null;
                $value = $this->resolveConfigValue($key, $default);
                if ($value !== null) {
                    $content = str_replace($m[0], "'{$value}'", $content);
                }
            }
        }

        // config('key') or config('key', 'default')
        $pattern2 = '/config\s*\(\s*[\'"]([\w.]+)[\'"](?:\s*,\s*[\'"]([^\'"]*)[\'"])?\s*\)/';
        if (preg_match_all($pattern2, $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $key = $m[1];
                $default = $m[2] ?? null;
                $value = $this->resolveConfigValue($key, $default);
                if ($value !== null) {
                    $content = str_replace($m[0], "'{$value}'", $content);
                }
            }
        }

        return $content;
    }

    private function resolveConfigValue(string $key, ?string $default = null): ?string
    {
        if (function_exists('config')) {
            $value = config($key, $default);
            return is_string($value) ? $value : null;
        }

        return $default;
    }

    private function resolveExternalClassConstants(string $content): string
    {
        // Find use statements: use Foo\Bar\ClassName;
        if (! preg_match_all('/use\s+([\w\\\\]+)\s*;/', $content, $useMatches, PREG_SET_ORDER)) {
            return $content;
        }

        $uses = [];
        foreach ($useMatches as $um) {
            $parts = explode('\\', $um[1]);
            $shortName = end($parts);
            $uses[$shortName] = $um[1];
        }

        // Find ClassName::CONSTANT patterns
        if (! preg_match_all('/([A-Z][A-Za-z0-9_]*)::([A-Z_]+)/', $content, $constMatches, PREG_SET_ORDER)) {
            return $content;
        }

        foreach ($constMatches as $cm) {
            $className = $cm[1];
            $constantName = $cm[2];

            if (! isset($uses[$className])) {
                continue;
            }

            $fqcn = $uses[$className];
            $value = $this->resolveConstantFromVendor($fqcn, $constantName);
            if ($value !== null) {
                $content = str_replace($cm[0], "'{$value}'", $content);
            }
        }

        return $content;
    }

    private function resolveConstantFromVendor(string $fqcn, string $constantName): ?string
    {
        $vendorPath = $this->projectRoot.'/vendor';
        $path = $vendorPath.'/'.str_replace('\\', '/', $fqcn).'.php';

        if (! file_exists($path)) {
            // Fallback: use autoloader to find the actual file
            if (class_exists($fqcn)) {
                $ref = new \ReflectionClass($fqcn);
                $path = $ref->getFileName();
            }
        }

        if ($path === null || ! file_exists($path)) {
            return null;
        }

        $content = file_get_contents($path);
        if (preg_match('/const\s+'.preg_quote($constantName, '/').'\s*=\s*[\'"]([^\'"]+)[\'"]\s*;/', $content, $m)) {
            return $m[1];
        }

        return null;
    }

    private function tableNameFromFilename(string $filename): ?string
    {
        // 2024_01_01_000000_create_users_table.php → users
        if (preg_match('/\d+_\d+_\d+_\d+_[a-z]+_([a-z_]+?)(?:_table)?\.php$/', $filename, $m)) {
            return $m[1];
        }

        return null;
    }

    private function parseFile(string $file): void
    {
        try {
            $content = (string) file_get_contents($file);
            $content = $this->resolveClassConstants($content, basename($file));

            $upBody = $this->extractUpBody($content);
            if ($upBody === null) {
                $this->unparseable[] = basename($file);

                return;
            }
            $this->processUpBody($upBody, basename($file));

        // Parse ALTER TABLE ... MODIFY from DB::statement calls
        // Only modify existing tables — don't create new ones from SQL statements
        $this->parseDbStatements($upBody, basename($file));
        } catch (\Throwable) {
            $this->unparseable[] = basename($file);
        }
    }

    private function extractUpBody(string $content): ?string
    {
        // Match the up() method body using token-aware brace counting
        if (! preg_match('/\bfunction\s+up\s*\(\s*\)\s*(?::\s*void\s*)?\{/', $content, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $start = $m[0][1] + strlen($m[0][0]) - 1; // position of opening brace

        return $this->extractBraceBlock($content, $start);
    }

    /**
     * Extract content inside the outermost braces starting at $offset (which must point at '{').
     */
    private function extractBraceBlock(string $content, int $offset): ?string
    {
        $depth = 0;
        $len = strlen($content);
        $start = $offset;
        for ($i = $offset; $i < $len; $i++) {
            $ch = $content[$i];
            if ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($content, $start + 1, $i - $start - 1);
                }
            }
        }

        return null;
    }

    private function processUpBody(string $upBody, string $filename): void
    {
        // Schema::drop / Schema::dropIfExists in up() → remove from accumulated state
        if (preg_match_all(
            '/Schema\s*::\s*(?:connection\s*\([^)]*\)\s*->\s*)?drop(?:IfExists)?\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/',
            $upBody,
            $dropMatches,
            PREG_SET_ORDER,
        )) {
            foreach ($dropMatches as $dm) {
                $dropped = $dm[1];
                unset($this->tables[$dropped]);
                $this->destructiveOps[] = "[{$filename}] Schema::drop('{$dropped}') — table removed";
            }
        }

        // Find Schema::create and Schema::table calls (including Schema::connection()->create/table)
        $pattern = '/Schema\s*::\s*(?:connection\s*\([^)]*\)\s*->\s*)?(create|table)\s*\(\s*[\'"]([^\'"]+)[\'"]/';
        if (! preg_match_all($pattern, $upBody, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            return;
        }

        foreach ($matches as $match) {
            $operation = $match[1][0];
            $tableName = $match[2][0];
            $callOffset = $match[0][1];

            // Find the Blueprint closure body: the last '{' before the next schema call or end
            $closureStart = $this->findClosureOpen($upBody, $callOffset + strlen($match[0][0]));
            if ($closureStart === null) {
                continue;
            }

            $closureBody = $this->extractBraceBlock($upBody, $closureStart);
            if ($closureBody === null) {
                continue;
            }

            if ($operation === 'create') {
                $this->tables[$tableName] = new ParsedTable($tableName);
                $this->parseClosureBody($closureBody, $tableName, $filename);
            } else {
                if (! isset($this->tables[$tableName])) {
                    $this->tables[$tableName] = new ParsedTable($tableName);
                }
                $this->processAlterBody($closureBody, $tableName, $filename);
            }
        }
    }

    private function findClosureOpen(string $body, int $fromOffset): ?int
    {
        $len = strlen($body);
        for ($i = $fromOffset; $i < $len; $i++) {
            if ($body[$i] === '{') {
                return $i;
            }
        }

        return null;
    }

    private function parseClosureBody(string $body, string $tableName, string $filename): void
    {
        // Table-level unique/index constraints: $table->unique(['col1', 'col2'])
        if (preg_match_all('/\$\w+\s*->\s*(unique|index)\s*\(\s*\[([^\]]+)\]/', $body, $constraintMatches, PREG_SET_ORDER)) {
            foreach ($constraintMatches as $cm) {
                $cols = array_map(
                    static fn (string $s): string => trim($s, " \t\n\r\"'"),
                    explode(',', $cm[2]),
                );
                $cols = array_filter($cols);
                if ($cm[1] === 'unique') {
                    $this->tables[$tableName]->uniqueConstraints[] = array_values($cols);
                } else {
                    $this->tables[$tableName]->indexConstraints[] = array_values($cols);
                }
            }
        }

        $typesPattern = implode('|', array_map('preg_quote', self::COLUMN_TYPES));

        foreach (explode(';', $body) as $stmt) {
            $stmt = trim($stmt);
            if ($stmt === '') {
                continue;
            }

            // foreignIdFor — class reference arg, not a string column name
            if (preg_match('/\$\w+\s*->\s*foreignIdFor\s*\(\s*([^)]+)\s*\)(.*)$/s', $stmt, $m)) {
                $resolved = $this->resolveForeignIdFor(trim($m[1]));
                if ($resolved === null) {
                    $this->unparseable[] = "{$filename} ({$tableName}: unresolvable foreignIdFor arg, skipped)";

                    continue;
                }
                $modifiers = $this->extractModifiers($m[2]);
                $modifiers['references'] = $resolved['table'];
                $col = new ParsedColumn($resolved['column'], 'foreignId', $modifiers);
                $this->placeColumn($tableName, $col, $modifiers);

                continue;
            }

            if (! preg_match('/\$\w+\s*->\s*('.$typesPattern.')\s*\(([^)]*)\)(.*)$/s', $stmt, $m)) {
                continue;
            }

            $type = $m[1];
            $args = $m[2];
            $tail = $m[3];

            // Normalise aliases before special-case handling
            if (in_array($type, ['enum', 'set'], true)) {
                $type = 'string';
            }
            if (in_array($type, ['timestampsTz', 'nullableTimestamps'], true)) {
                $type = 'timestamps';
            }
            if ($type === 'softDeletesTz') {
                $type = 'softDeletes';
            }
            if ($type === 'timestampTz') {
                $type = 'timestamp';
            }
            if (in_array($type, ['increments', 'tinyIncrements', 'smallIncrements', 'mediumIncrements', 'integerIncrements'], true)) {
                $type = 'bigIncrements';
            }

            if (in_array($type, ['timestamps', 'softDeletes', 'rememberToken'], true)) {
                if ($type === 'timestamps') {
                    $this->tables[$tableName]->addColumn(new ParsedColumn('created_at', 'timestamp'));
                    $this->tables[$tableName]->addColumn(new ParsedColumn('updated_at', 'timestamp'));
                } elseif ($type === 'softDeletes') {
                    $this->tables[$tableName]->addColumn(new ParsedColumn('deleted_at', 'softDeletes'));
                } else {
                    $this->tables[$tableName]->addColumn(new ParsedColumn('remember_token', 'rememberToken'));
                }

                continue;
            }

            if (in_array($type, ['id', 'bigIncrements'], true)) {
                $colName = $this->extractFirstStringArg($args) ?? 'id';
                $this->tables[$tableName]->addColumn(new ParsedColumn($colName, 'id'));

                continue;
            }

            $colName = $this->extractFirstStringArg($args);
            if ($colName === null) {
                continue;
            }

            $modifiers = $this->extractModifiers($tail);

            if (($type === 'string' || $type === 'char') && preg_match('/[\'"][^\'"]+[\'"],\s*(\d+)/', $args, $lenMatch)) {
                $modifiers['length'] = (int) $lenMatch[1];
            }

            if ($type === 'decimal' && preg_match_all('/\d+/', $args, $numMatches)) {
                // decimal('col', precision, scale) — skip first match (column name digits unlikely)
                $nums = array_values(array_filter(array_map('intval', $numMatches[0])));
                if (count($nums) >= 2) {
                    $modifiers['precision'] = $nums[0];
                    $modifiers['scale'] = $nums[1];
                } elseif (count($nums) === 1) {
                    $modifiers['precision'] = $nums[0];
                }
            }

            if (($modifiers['references'] ?? null) === '__infer__') {
                $modifiers['references'] = $this->inferTableFromColumn($colName);
            }

            $this->placeColumn($tableName, new ParsedColumn($colName, $type, $modifiers), $modifiers);
        }

        // Phase 2: stand-alone FK: $table->foreign('col')->references('id')->on('tbl')->onDelete('cascade')
        if (preg_match_all(
            '/\$\w+\s*->\s*foreign\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)\s*->\s*references\s*\([^)]*\)\s*->\s*on\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)([^;]*)/',
            $body,
            $fkMatches,
            PREG_SET_ORDER,
        )) {
            foreach ($fkMatches as $fk) {
                $colName = $fk[1];
                $refTable = $fk[2];
                $tail = $fk[3] ?? '';
                if (! isset($this->tables[$tableName]->columns[$colName])) {
                    continue;
                }
                $existing = $this->tables[$tableName]->columns[$colName];
                $mods = array_merge($existing->modifiers, ['references' => $refTable]);
                if (preg_match('/->onDelete\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', $tail, $odm)) {
                    $mods['onDelete'] = match ($odm[1]) {
                        'cascade' => 'cascade',
                        'set null' => 'null',
                        'restrict' => 'restrict',
                        default => $odm[1],
                    };
                }
                $this->tables[$tableName]->columns[$colName] = new ParsedColumn($colName, $existing->type, $mods);
            }
        }
    }

    private function processAlterBody(string $body, string $tableName, string $filename): void
    {
        // dropColumn: single or array form
        if (preg_match_all('/\$\w+\s*->\s*dropColumn\s*\(\s*([^)]+)\s*\)/', $body, $drops, PREG_SET_ORDER)) {
            foreach ($drops as $drop) {
                $arg = trim($drop[1]);
                if (str_starts_with($arg, '[')) {
                    preg_match_all('/[\'"]([^\'"]+)[\'"]/', $arg, $cols);
                    foreach ($cols[1] as $colName) {
                        $this->tables[$tableName]->removeColumn($colName);
                    }
                } else {
                    $this->tables[$tableName]->removeColumn(trim($arg, " '\""));
                }
            }
        }

        // renameColumn('old', 'new')
        if (preg_match_all(
            '/\$\w+\s*->\s*renameColumn\s*\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]\s*\)/',
            $body,
            $renames,
            PREG_SET_ORDER,
        )) {
            foreach ($renames as $rename) {
                $this->tables[$tableName]->renameColumn($rename[1], $rename[2]);
            }
        }

        // New columns and ->change() redefinitions (addColumn overwrites by name)
        $this->parseClosureBody($body, $tableName, $filename);
    }

    private function parseDbStatements(string $upBody, string $filename): void
    {
        // ALTER TABLE `table` MODIFY `column` TYPE NULL/NOT NULL
        $pattern = "/DB::statement\(\s*[\"'].*ALTER\s+TABLE\s+[\"'`]?(\w+)[\"'`]?\s+MODIFY\s+[\"'`]?(\w+)[\"'`]?\s+(\w+(?:\([^)]*\))?)\s+(NULL|NOT NULL)/i";
        if (! preg_match_all($pattern, $upBody, $matches, PREG_SET_ORDER)) {
            return;
        }

        foreach ($matches as $m) {
            $tableName = $m[1];
            $colName = $m[2];
            $sqlType = strtoupper($m[3]);
            $nullable = strtoupper($m[4]) === 'NULL';

            if (! isset($this->tables[$tableName])) {
                continue;
            }

            $type = $this->sqlTypeToBlueprintType($sqlType);
            $modifiers = [];
            if ($nullable) {
                $modifiers['nullable'] = true;
            }

            $this->tables[$tableName]->addColumn(new ParsedColumn($colName, $type, $modifiers));
        }
    }

    private function sqlTypeToBlueprintType(string $sqlType): string
    {
        // Strip size parameters for comparison
        $base = preg_replace('/\s*\([^)]*\)/', '', $sqlType) ?? $sqlType;

        return match ($base) {
            'TINYINT' => 'tinyInteger',
            'SMALLINT' => 'smallInteger',
            'MEDIUMINT' => 'mediumInteger',
            'BIGINT' => 'bigInteger',
            'INT', 'INTEGER' => 'integer',
            'TINYTEXT' => 'tinyText',
            'TEXT' => 'text',
            'MEDIUMTEXT' => 'mediumText',
            'LONGTEXT' => 'longText',
            'TINYBLOB' => 'binary',
            'BLOB', 'MEDIUMBLOB', 'LONGBLOB' => 'binary',
            'VARCHAR' => 'string',
            'CHAR' => 'char',
            'BOOLEAN', 'BOOL', 'TINYINT(1)' => 'boolean',
            'DATE' => 'date',
            'DATETIME' => 'dateTime',
            'TIMESTAMP' => 'timestamp',
            'TIME' => 'time',
            'DECIMAL', 'NUMERIC' => 'decimal',
            'FLOAT' => 'float',
            'DOUBLE' => 'float',
            'JSON', 'JSONB' => 'json',
            'UUID' => 'uuid',
            'BINARY', 'VARBINARY' => 'binary',
            default => strtolower($base),
        };
    }

    /** @param array<string, mixed> $modifiers */
    private function placeColumn(string $tableName, ParsedColumn $col, array $modifiers): void
    {
        if (isset($modifiers['after']) && is_string($modifiers['after'])) {
            $this->tables[$tableName]->insertAfter($modifiers['after'], $col);
        } else {
            $this->tables[$tableName]->addColumn($col);
        }
    }

    /** @return array<string, mixed> */
    private function extractModifiers(string $tail): array
    {
        $modifiers = [];

        if (preg_match('/->nullable\(\)/', $tail)) {
            $modifiers['nullable'] = true;
        }
        if (preg_match('/->primary\(\)/', $tail)) {
            $modifiers['primary'] = true;
        }
        if (preg_match('/->unique\(\)/', $tail)) {
            $modifiers['unique'] = true;
        }
        if (preg_match('/->unsigned\(\)/', $tail)) {
            $modifiers['unsigned'] = true;
        }
        if (preg_match('/->index\(\)/', $tail)) {
            $modifiers['index'] = true;
        }
        if (preg_match('/->useCurrent\(\)/', $tail)) {
            $modifiers['useCurrent'] = true;
        }
        if (preg_match('/->cascadeOnDelete\(\)/', $tail)) {
            $modifiers['onDelete'] = 'cascade';
        } elseif (preg_match('/->nullOnDelete\(\)/', $tail)) {
            $modifiers['onDelete'] = 'null';
        } elseif (preg_match('/->restrictOnDelete\(\)/', $tail)) {
            $modifiers['onDelete'] = 'restrict';
        }
        if (preg_match('/->cascadeOnUpdate\(\)/', $tail)) {
            $modifiers['onUpdate'] = 'cascade';
        } elseif (preg_match('/->nullOnUpdate\(\)/', $tail)) {
            $modifiers['onUpdate'] = 'null';
        } elseif (preg_match('/->restrictOnUpdate\(\)/', $tail)) {
            $modifiers['onUpdate'] = 'restrict';
        }

        // ->default(value) — handles one level of nested parens
        if (preg_match('/->default\(([^()]*(?:\([^()]*\))*[^()]*)\)/', $tail, $dm)) {
            $modifiers['default'] = trim($dm[1]);
        }

        // ->constrained('table') — explicit table name
        if (preg_match('/->constrained\([\'"]([^\'"]+)[\'"]\)/', $tail, $cm)) {
            $modifiers['references'] = $cm[1];
        } elseif (preg_match('/->constrained\(\)/', $tail)) {
            $modifiers['references'] = '__infer__';
        }

        // Old-style: ->references('id')->on('table')
        if (preg_match('/->references\([\'"][^\'"]*[\'"]\)\s*->\s*on\([\'"]([^\'"]+)[\'"]\)/', $tail, $om)) {
            $modifiers['references'] = $om[1];
        }

        // Old-style onDelete: ->onDelete('cascade'|'set null'|'restrict')
        if (preg_match('/->onDelete\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', $tail, $odm)) {
            $modifiers['onDelete'] = match ($odm[1]) {
                'cascade' => 'cascade',
                'set null' => 'null',
                'restrict' => 'restrict',
                default => $odm[1],
            };
        }

        if (preg_match('/->after\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', $tail, $am)) {
            $modifiers['after'] = $am[1];
        }

        return $modifiers;
    }

    /** @return array{column: string, table: string}|null */
    private function resolveForeignIdFor(string $classArg): ?array
    {
        if (! preg_match('/(?:\\\\?(?:\w+\\\\)*)(\w+)::class/', $classArg, $m)) {
            return null;
        }
        $snake = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $m[1]));

        return ['column' => $snake.'_id', 'table' => $snake.'s'];
    }

    private function inferTableFromColumn(string $colName): string
    {
        $base = preg_replace('/_id$/', '', $colName) ?? $colName;

        return $base.'s';
    }

    private function extractFirstStringArg(string $args): ?string
    {
        if (preg_match('/[\'"]([^\'"]+)[\'"]/', $args, $m)) {
            return $m[1];
        }

        return null;
    }
}
