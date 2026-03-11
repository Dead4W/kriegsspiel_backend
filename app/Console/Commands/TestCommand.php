<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class TestCommand extends Command
{
    public $signature = 'tst';

    public $description = 'Test command';

    public function handle() {
        $snapshot = \App\Models\Snapshot::query()
            ->where('id', 9)
            ->first();
        var_dump($snapshot->units);
    }

}
