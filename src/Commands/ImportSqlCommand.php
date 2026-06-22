<?php

declare(strict_types=1);

namespace Boquizo\Hew\Commands;

use Boquizo\Hew\Generator\SchemaRenderer;
use Boquizo\Hew\Parser\SqlDumpParser;
use Illuminate\Console\Command;

class ImportSqlCommand extends Command
{
    protected $signature = 'hew:import-sql
        {file : Path to SQL dump file}
        {--output= : Output path for schema.php (default: database/schema.php)}';

    protected $description = 'Generate schema.php from a SQL dump (mysqldump format)';

    public function handle(): int
    {
        $file = (string) $this->argument('file');

        if (! file_exists($file)) {
            $this->error("File not found: {$file}");

            return self::FAILURE;
        }

        $outputPath = is_string($this->option('output'))
            ? $this->option('output')
            : base_path('database/schema.php');

        if (file_exists($outputPath) && ! $this->confirm('Overwrite existing schema.php?', true)) {
            $this->line('Aborted.');

            return self::SUCCESS;
        }

        $sql = (string) file_get_contents($file);
        $tables = (new SqlDumpParser)->parse($sql);

        if ($tables === []) {
            $this->error('No CREATE TABLE statements found in the SQL dump.');

            return self::FAILURE;
        }

        $content = (new SchemaRenderer)->render($tables);
        file_put_contents($outputPath, $content);

        $colCount = array_sum(array_map(static fn ($t): int => count($t->columns), $tables));
        $this->info(sprintf('  Imported %d tables, %d columns.', count($tables), $colCount));

        $todoCount = substr_count($content, '// TODO');
        if ($todoCount > 0) {
            $this->warn("  ⚠ {$todoCount} TODO item(s) in generated schema — search for \"// TODO\"");
        }

        $this->info("  Schema written to {$outputPath}");

        return self::SUCCESS;
    }
}
