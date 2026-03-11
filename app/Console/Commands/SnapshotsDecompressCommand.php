<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class SnapshotsDecompressCommand extends Command
{
    public $signature = 'snapshots:decompress';

    public $description = 'Decompress snapshots';

    public function handle(): void
    {
        ini_set('memory_limit', '512M');

        $query = \App\Models\Snapshot::query()
            ->where('data_type', \App\Enums\DataTypeEnum::GZ_COMPRESSED->value);

        $progressBar = $this->output->createProgressBar($query->count());
        $progressBar->start();

        $query->chunk(1, function ($snapshots) use ($progressBar) {
            foreach ($snapshots as $snapshot) {
                $units = $snapshot->units;
                $paint = $snapshot->paint;
                $logs = $snapshot->logs;
                $snapshot->data_type = \App\Enums\DataTypeEnum::JSON;
                $snapshot->units = $units;
                $snapshot->paint = $paint;
                $snapshot->logs = $logs;
                $snapshot->data_raw = '';
                $snapshot->save();
                $progressBar->advance();
            }
        });

        $progressBar->finish();
        $this->newLine();
    }

}
