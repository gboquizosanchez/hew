<?php

declare(strict_types=1);

namespace Boquizo\Hew\Commands;

use Boquizo\Hew\Diff\SchemaDiff;
use Boquizo\Hew\Generator\MigrationGenerator;
use Boquizo\Hew\Parser\MigrationParser;
use Boquizo\Hew\Schema\Schema;
use Illuminate\Console\Command;

class SyncCommand extends Command
{
    protected $signature = 'hew:sync
        {--force : Skip confirmation prompt}
        {--dry-run : Show diff without generating files}
        {--path= : Path to schema.php (default: database/schema.php)}';

    protected $description = 'Generate migrations from schema.php';

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

        if ($this->option('dry-run')) {
            return $this->call('hew:diff', ['--path' => $schemaPath]);
        }

        /** @var Schema $schema */
        $schema = require $schemaPath;
        $migrationsPath = database_path('migrations');

        $parser = new MigrationParser;
        $current = $parser->parse($migrationsPath);
        $diff = new SchemaDiff($schema, $current);

        if ($diff->isClean()) {
            $this->info('Nothing to sync.');

            return self::SUCCESS;
        }

        foreach ($diff->droppedColumns as $tableName => $cols) {
            $names = implode(', ', array_map(static fn ($c) => $c->name, $cols));
            $this->line("  Will drop columns from <fg=red>{$tableName}</>: {$names}");
        }
        foreach ($diff->droppedTables as $tableName) {
            $this->line("  Will drop table: <fg=red>{$tableName}</>");
        }
        foreach ($diff->modifiedColumns as $tableName => $cols) {
            $names = implode(', ', array_map(static fn ($c) => $c->name, $cols));
            $this->line("  Will modify columns in <fg=yellow>{$tableName}</>: {$names}");
        }
        foreach ($diff->newTables as $tableName => $table) {
            $this->line("  Will create table: <fg=green>{$tableName}</>");
        }
        foreach ($diff->newColumns as $tableName => $columns) {
            $names = implode(', ', array_map(static fn ($c) => $c->name, $columns));
            $this->line("  Will add columns to <fg=green>{$tableName}</>: {$names}");
        }

        if ($diff->warnings !== []) {
            $this->line('');
            $this->warn('  The following changes require manual intervention:');
            foreach ($diff->warnings as $warning) {
                $this->line('  ~ '.$warning);
            }
        }

        $this->line('');

        if (! $this->option('force') && ! $this->confirm('Generate migrations?', true)) {
            $this->line('Aborted.');

            return self::SUCCESS;
        }

        $generator = new MigrationGenerator($migrationsPath, $this->stubsPath());
        $files = $generator->generate($diff);

        $this->info('Generated migrations:');
        foreach ($files as $file) {
            $this->line('  '.basename($file));
        }

        return self::SUCCESS;
    }

    private function stubsPath(): string
    {
        return dirname(__DIR__, 2).'/stubs';
    }
}
