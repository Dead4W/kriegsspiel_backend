<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class SnapshotsCompressCommand extends Command
{
    public $signature = 'snapshots:compress';

    public $description = 'Compress old snapshots';

    public const COMPRESS_LEVEL = 6;

    public function handle(): void
    {
        ini_set('memory_limit', '512M');

        $query = \App\Models\Snapshot::query()
            ->where('created_at', '<', \Carbon\Carbon::now()->subDays(3))
            ->where('data_type', \App\Enums\DataTypeEnum::JSON->value);

        $progressBar = $this->output->createProgressBar($query->count());
        $progressBar->start();

        $query->chunk(1, function ($snapshots) use ($progressBar) {
            foreach ($snapshots as $snapshot) {
                $snapshot->data_raw = gzcompress(json_encode(['units' => $snapshot->units, 'paint' => $snapshot->paint, 'logs' => $snapshot->logs]), self::COMPRESS_LEVEL);
                $snapshot->units = [];
                $snapshot->paint = [];
                $snapshot->logs = [];
                $snapshot->data_type = \App\Enums\DataTypeEnum::GZ_COMPRESSED;
                $snapshot->save();
                $progressBar->advance();
            }
        });

        $progressBar->finish();
        $this->newLine();
    }

}
