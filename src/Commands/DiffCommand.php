<?php

declare(strict_types=1);

namespace Boquizo\Hew\Commands;

use Boquizo\Hew\Diff\SchemaDiff;
use Boquizo\Hew\Parser\MigrationParser;
use Boquizo\Hew\Schema\ColumnDef;
use Boquizo\Hew\Schema\Schema;
use Illuminate\Console\Command;

class DiffCommand extends Command
{
    protected $signature = 'hew:diff
        {--path= : Path to schema.php (default: database/schema.php)}';

    protected $description = 'Show pending schema changes without generating migrations';

    public function handle(): int
    {
        $schemaPath = is_string($this->option('path'))
            ? $this->option('path')
            : base_path('database/schema.php');

        if (! file_exists($schemaPath)) {
            $this->error("Schema file not found: {$schemaPath}");
            $this->line('Create database/schema.php with Schema::define([...]) to get started.');

            return self::FAILURE;
        }

        /** @var Schema $schema */
        $schema = require $schemaPath;
        $parser = new MigrationParser;
        $current = $parser->parse(database_path('migrations'));

        $diff = new SchemaDiff($schema, $current);

        if ($diff->isClean()) {
            $this->info('Nothing to sync. Schema is up to date.');

            return self::SUCCESS;
        }

        foreach ($diff->newTables as $tableName => $table) {
            $this->line('');
            $this->info("  New table: {$tableName}");
            foreach ($table->getFlatColumns() as $col) {
                $this->line('  <fg=green>+</> '.$this->describeColumn($col));
            }
        }

        foreach ($diff->newColumns as $tableName => $columns) {
            $this->line('');
            $this->info("  New columns in {$tableName}:");
            foreach ($columns as $col) {
                $this->line('  <fg=green>+</> '.$this->describeColumn($col));
            }
        }

        foreach ($diff->droppedTables as $tableName) {
            $this->line('');
            $this->line("  <fg=red>Drop table: {$tableName}</>");
        }

        foreach ($diff->droppedColumns as $tableName => $cols) {
            $this->line('');
            $this->line("  Drop columns from {$tableName}:");
            foreach ($cols as $col) {
                $this->line("  <fg=red>-</> {$col->name} ({$col->type})");
            }
        }

        foreach ($diff->modifiedColumns as $tableName => $cols) {
            $this->line('');
            $this->line("  Modify columns in {$tableName}:");
            foreach ($cols as $col) {
                $this->line('  <fg=yellow>~</> '.$this->describeColumn($col));
            }
        }

        if ($diff->warnings !== []) {
            $this->line('');
            $this->warn('  Require manual intervention:');
            foreach ($diff->warnings as $warning) {
                $this->line('  <fg=yellow>~</> '.$warning);
            }
        }

        return self::FAILURE; // exit 1 when changes pending (useful for CI)
    }

    private function describeColumn(ColumnDef $col): string
    {
        $parts = [$col->name];
        if ($col->type !== 'id' && $col->type !== 'timestamps') {
            $parts[] = $col->type;
        }
        if ($col->isNullable) {
            $parts[] = 'nullable';
        }
        if ($col->isUnique) {
            $parts[] = 'unique';
        }
        if ($col->referencesTable !== null) {
            $parts[] = "-> {$col->referencesTable}";
        }
        if (! empty($col->parameters)) {
            $parts[] = implode(',', $col->parameters);
        }

        return implode(', ', $parts);
    }
}
