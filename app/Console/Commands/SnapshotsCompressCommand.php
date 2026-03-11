<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class SnapshotsCompressCommand extends Command
{
    public $signature = 'snapshots:compress';

    public $description = 'Compress old snapshots';

    public function handle(): void
    {
        $query = \App\Models\Snapshot::query()
            ->where('created_at', '<', \Carbon\Carbon::now()->subDays(3))
            ->where('data_type', \App\Enums\DataTypeEnum::JSON->value);

        $progressBar = $this->output->createProgressBar($query->count());
        $progressBar->start();

        $query->each(function (\App\Models\Snapshot $snapshot) use ($progressBar) {
            $snapshot->data_raw = gzcompress(json_encode(['units' => $snapshot->units, 'paint' => $snapshot->paint, 'logs' => $snapshot->logs]));
            $snapshot->units = [];
            $snapshot->paint = [];
            $snapshot->logs = [];
            $snapshot->data_type = \App\Enums\DataTypeEnum::GZ_COMPRESSED;
            $snapshot->save();
            $progressBar->advance();
        });

        $progressBar->finish();
        $this->newLine();
    }

}
