<?php

declare(strict_types=1);

namespace Boquizo\Hew;

use Boquizo\Hew\Commands\DiffCommand;
use Boquizo\Hew\Commands\ImportCommand;
use Boquizo\Hew\Commands\ImportSqlCommand;
use Boquizo\Hew\Commands\SyncCommand;
use Illuminate\Support\ServiceProvider;

class HewServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->commands([
            DiffCommand::class,
            SyncCommand::class,
            ImportCommand::class,
            ImportSqlCommand::class,
        ]);
    }
}
