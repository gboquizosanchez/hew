<?php

declare(strict_types=1);

namespace Boquizo\Hew\Parser;

class SqlDumpParser
{
    /** @return array<string, ParsedTable> */
    public function parse(string $sql): array
    {
        $tables = [];

        if (! preg_match_all(
            '/CREATE TABLE `([^`]+)` \((.*?)\)\s*(?:ENGINE|DEFAULT CHARSET)/s',
            $sql,
            $matches,
            PREG_SET_ORDER,
        )) {
            return [];
        }

        foreach ($matches as $m) {
            $tables[$m[1]] = $this->parseBody($m[1], $m[2]);
        }

        return $tables;
    }

    private function parseBody(string $tableName, string $body): ParsedTable
    {
        $table = new ParsedTable($tableName);

        $autoIncrements = [];
        $primaryKeys = [];
        $fkMap = [];        // colName => [table, tail]
        $singleUniques = [];
        $singleIndexes = [];

        foreach (explode("\n", $body) as $rawLine) {
            $line = trim($rawLine, " \t\r,");
            if ($line === '') {
                continue;
            }

            if (str_starts_with($line, 'PRIMARY KEY')) {
                preg_match_all('/`([^`]+)`/', $line, $pkm);
                $primaryKeys = $pkm[1] ?? [];
                continue;
            }

            if (str_starts_with($line, 'UNIQUE KEY')) {
                if (preg_match('/UNIQUE KEY `[^`]*` \((.+)\)/', $line, $m)) {
                    $cols = $this->keyColumns($m[1]);
                    if (count($cols) === 1) {
                        $singleUniques[] = $cols[0];
                    } else {
                        $table->uniqueConstraints[] = $cols;
                    }
                }
                continue;
            }

            if (preg_match('/^(?:FULLTEXT |SPATIAL )?KEY `[^`]*` \((.+)\)/', $line, $m)) {
                $cols = $this->keyColumns($m[1]);
                if (count($cols) > 1) {
                    $table->indexConstraints[] = $cols;
                }
                continue;
            }

            if (preg_match('/FOREIGN KEY \(`([^`]+)`\) REFERENCES `([^`]+)` \(`[^`]+`\)(.*)/', $line, $m)) {
                $fkMap[$m[1]] = ['table' => $m[2], 'tail' => $m[3]];
                continue;
            }

            if (str_starts_with($line, 'CONSTRAINT') || str_starts_with($line, 'KEY ')) {
                continue;
            }

            if (! preg_match('/^`([^`]+)`\s+(.+)$/', $line, $m)) {
                continue;
            }

            $colName = $m[1];
            $def = $m[2];

            [$type, $modifiers] = $this->parseColDef($colName, $def);

            if ($modifiers['_auto'] ?? false) {
                $autoIncrements[] = $colName;
                unset($modifiers['_auto']);
            }

            $table->addColumn(new ParsedColumn($colName, $type, $modifiers));
        }

        // Auto-increment + PK → id type
        foreach ($autoIncrements as $colName) {
            if (in_array($colName, $primaryKeys, true) && isset($table->columns[$colName])) {
                $table->columns[$colName] = new ParsedColumn($colName, 'id', []);
            }
        }

        // Single-col unique → column modifier
        foreach ($singleUniques as $colName) {
            if (isset($table->columns[$colName])) {
                $col = $table->columns[$colName];
                $table->columns[$colName] = new ParsedColumn($colName, $col->type, $col->modifiers + ['unique' => true]);
            }
        }

        // Single-col index → column modifier (skip if already unique)
        foreach ($singleIndexes as $colName) {
            if (isset($table->columns[$colName]) && ! ($table->columns[$colName]->modifiers['unique'] ?? false)) {
                $col = $table->columns[$colName];
                $table->columns[$colName] = new ParsedColumn($colName, $col->type, $col->modifiers + ['index' => true]);
            }
        }

        // Foreign key references
        foreach ($fkMap as $colName => ['table' => $refTable, 'tail' => $tail]) {
            if (! isset($table->columns[$colName])) {
                continue;
            }
            $col = $table->columns[$colName];
            $mods = $col->modifiers + ['references' => $refTable];
            if (preg_match('/ON DELETE CASCADE/i', $tail)) {
                $mods['onDelete'] = 'cascade';
            } elseif (preg_match('/ON DELETE SET NULL/i', $tail)) {
                $mods['onDelete'] = 'null';
            } elseif (preg_match('/ON DELETE RESTRICT/i', $tail)) {
                $mods['onDelete'] = 'restrict';
            }
            $table->columns[$colName] = new ParsedColumn($colName, $col->type, $mods);
        }

        return $table;
    }

    /** @return array{string, array<string, mixed>} */
    private function parseColDef(string $colName, string $def): array
    {
        // Strip noise: collation, charset, comment
        $clean = preg_replace('/\s+COLLATE\s+\S+/i', '', $def) ?? $def;
        $clean = preg_replace('/\s+CHARACTER SET\s+\S+/i', '', $clean) ?? $clean;
        $clean = preg_replace("/\\s+COMMENT\\s+'(?:[^'\\\\]|\\\\.)*'/i", '', $clean) ?? $clean;
        $clean = trim($clean);

        $modifiers = [];

        if (preg_match('/\bNULL\b/i', $clean) && ! preg_match('/NOT NULL/i', $clean)) {
            $modifiers['nullable'] = true;
        }

        if (preg_match('/AUTO_INCREMENT/i', $clean)) {
            $modifiers['_auto'] = true;
        }

        if (preg_match("/DEFAULT\\s+('(?:[^'\\\\]|\\\\.)*'|\\S+)/i", $clean, $dm)) {
            $raw = $dm[1];
            if (! preg_match('/^(NULL|CURRENT_TIMESTAMP|NOW\(\))$/i', $raw)) {
                $modifiers['default'] = trim($raw, "'");
            }
        }

        $type = $this->mapType($clean);

        if ($type === 'string') {
            if (preg_match('/\((\d+)\)/', $clean, $lm) && (int) $lm[1] !== 255) {
                $modifiers['length'] = (int) $lm[1];
            }
        }

        if ($type === 'decimal') {
            if (preg_match('/\((\d+),\s*(\d+)\)/', $clean, $pm)) {
                $modifiers['precision'] = (int) $pm[1];
                $modifiers['scale'] = (int) $pm[2];
            }
        }

        // Heuristics
        if ($colName === 'deleted_at' && $type === 'timestamp' && ($modifiers['nullable'] ?? false)) {
            unset($modifiers['nullable']);

            return ['softDeletes', $modifiers];
        }
        if ($colName === 'remember_token' && $type === 'string') {
            return ['rememberToken', []];
        }
        // created_at / updated_at nullable timestamp → strip nullable so collapseTimestamps fires
        if (in_array($colName, ['created_at', 'updated_at'], true) && $type === 'timestamp') {
            unset($modifiers['nullable'], $modifiers['default']);

            return [$type, $modifiers];
        }

        return [$type, $modifiers];
    }

    private function mapType(string $def): string
    {
        $d = strtolower($def);

        if (str_starts_with($d, 'tinyint(1)')) {
            return 'boolean';
        }
        if (preg_match('/^bigint\b/', $d)) {
            return str_contains($d, 'unsigned') ? 'unsignedBigInteger' : 'bigInteger';
        }
        if (preg_match('/^mediumint\b/', $d)) {
            return str_contains($d, 'unsigned') ? 'unsignedMediumInteger' : 'mediumInteger';
        }
        if (preg_match('/^smallint\b/', $d)) {
            return str_contains($d, 'unsigned') ? 'unsignedSmallInteger' : 'smallInteger';
        }
        if (preg_match('/^tinyint\b/', $d)) {
            return str_contains($d, 'unsigned') ? 'unsignedTinyInteger' : 'tinyInteger';
        }
        if (preg_match('/^int\b/', $d)) {
            return str_contains($d, 'unsigned') ? 'unsignedInteger' : 'integer';
        }
        if (preg_match('/^(?:var)?char\b/', $d)) {
            return 'string';
        }
        if (preg_match('/^longtext\b/', $d)) {
            return 'longText';
        }
        if (preg_match('/^mediumtext\b/', $d)) {
            return 'mediumText';
        }
        if (preg_match('/^tinytext\b/', $d)) {
            return 'tinyText';
        }
        if (preg_match('/^text\b/', $d)) {
            return 'text';
        }
        if (preg_match('/^decimal\b/', $d)) {
            return 'decimal';
        }
        if (preg_match('/^(?:float|double)\b/', $d)) {
            return 'float';
        }
        if (preg_match('/^datetime\b/', $d)) {
            return 'dateTime';
        }
        if (preg_match('/^timestamp\b/', $d)) {
            return 'timestamp';
        }
        if (preg_match('/^date\b/', $d)) {
            return 'date';
        }
        if (preg_match('/^time\b/', $d)) {
            return 'time';
        }
        if (preg_match('/^uuid\b/', $d)) {
            return 'uuid';
        }
        if (preg_match('/^json\b/', $d)) {
            return 'json';
        }
        if (preg_match('/^bool/', $d)) {
            return 'boolean';
        }
        if (preg_match('/^enum\b/', $d)) {
            return 'string'; // values lost, schema uses string
        }

        return 'string'; // fallback
    }

    /** @return string[] */
    private function keyColumns(string $colList): array
    {
        preg_match_all('/`([^`]+)`/', $colList, $m);

        return $m[1];
    }
}
