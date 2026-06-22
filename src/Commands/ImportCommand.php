<?php

declare(strict_types=1);

namespace Boquizo\Hew\Commands;

use Boquizo\Hew\Generator\SchemaRenderer;
use Boquizo\Hew\Parser\MigrationParser;
use Boquizo\Hew\Parser\SqlDumpParser;
use Illuminate\Console\Command;

class ImportCommand extends Command
{
    protected $signature = 'hew:import
        {--path= : Path to migrations directory (default: database/migrations)}
        {--output= : Output path for schema.php (default: database/schema.php)}';

    protected $description = 'Generate schema.php from existing migrations';

    public function handle(): int
    {
        $migrationsPath = is_string($this->option('path'))
            ? $this->option('path')
            : database_path('migrations');

        $outputPath = is_string($this->option('output'))
            ? $this->option('output')
            : base_path('database/schema.php');

        if (! is_dir($migrationsPath)) {
            $this->error("Migrations directory not found: {$migrationsPath}");

            return self::FAILURE;
        }

        if (file_exists($outputPath) && ! $this->confirm('Overwrite existing schema.php?', true)) {
            $this->line('Aborted.');

            return self::SUCCESS;
        }

        // Seed from schema dump if present (Laravel artisan schema:dump pattern)
        $seed = [];
        $dumpPath = dirname($migrationsPath).'/schema/mysql-schema.dump';
        if (file_exists($dumpPath)) {
            $seed = (new SqlDumpParser)->parse((string) file_get_contents($dumpPath));
            $this->line("  Using schema dump: {$dumpPath} (".count($seed).' tables)');
        }

        $parser = new MigrationParser($seed, base_path());
        $tables = $parser->parse($migrationsPath);

        $content = (new SchemaRenderer)->render($tables, $parser->getExternalUseStatements());

        file_put_contents($outputPath, $content);

        $colCount = array_sum(array_map(static fn ($t): int => count($t->columns), $tables));
        $this->info(sprintf('  Imported %d tables, %d columns.', count($tables), $colCount));

        foreach ($parser->getUnparseable() as $entry) {
            $this->warn("  ⚠ Could not parse: {$entry}");
        }

        $todoCount = substr_count($content, '// TODO');
        if ($todoCount > 0) {
            $this->warn("  ⚠ {$todoCount} TODO item(s) in generated schema — search for \"// TODO\"");
        }

        $this->info("  Schema written to {$outputPath}");

        return self::SUCCESS;
    }
}
