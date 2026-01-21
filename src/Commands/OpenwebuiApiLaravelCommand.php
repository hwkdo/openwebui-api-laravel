<?php

namespace Hwkdo\OpenwebuiApiLaravel\Commands;

use Illuminate\Console\Command;

class OpenwebuiApiLaravelCommand extends Command
{
    public $signature = 'openwebui-api-laravel';

    public $description = 'My command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}
