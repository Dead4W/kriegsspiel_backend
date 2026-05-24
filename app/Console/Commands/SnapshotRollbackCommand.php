<?php

namespace App\Console\Commands;

use App\Models\RoomMap;
use App\Models\RoomMapItem;
use App\Models\Room;
use App\Models\Snapshot;
use App\Services\RoomMapItemsService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SnapshotRollbackCommand extends Command
{
    protected $signature = 'snapshots:rollback
        {roomMapId : Room map ID}
        {ingameTime : Snapshot ingame time in format "Y-m-d H:i:s"}';

    protected $description = 'Rollback room_map_items to snapshot by room_map_id and ingame_time';

    public function handle(): int
    {
        $roomMapId = (int) $this->argument('roomMapId');
        $ingameTimeRaw = (string) $this->argument('ingameTime');

        if ($roomMapId <= 0) {
            $this->error('roomMapId must be a positive integer.');
            return self::FAILURE;
        }

        $roomMap = RoomMap::query()->find($roomMapId);
        if (!$roomMap) {
            $this->error("RoomMap {$roomMapId} not found.");
            return self::FAILURE;
        }

        $ingameTime = $this->parseIngameTime($ingameTimeRaw);
        if (!$ingameTime) {
            $this->error('Invalid ingameTime format. Expected "Y-m-d H:i:s".');
            return self::FAILURE;
        }

        $snapshot = Snapshot::query()
            ->where('room_map_id', $roomMapId)
            ->where('ingame_time', $ingameTime->format('Y-m-d H:i:s'))
            ->first();

        if (!$snapshot) {
            $this->error("Snapshot not found for room_map_id={$roomMapId}, ingame_time={$ingameTime->format('Y-m-d H:i:s')}.");
            return self::FAILURE;
        }

        $now = now();

        DB::transaction(function () use ($snapshot, $roomMap, $now): void {
            $existingPaintShared = RoomMapItem::query()
                ->where('room_map_id', $roomMap->id)
                ->where('type', RoomMapItemsService::TYPE_PAINT)
                ->pluck('shared', 'item_id')
                ->all();

            RoomMapItem::query()
                ->where('room_map_id', $roomMap->id)
                ->whereIn('type', [
                    RoomMapItemsService::TYPE_UNIT,
                    RoomMapItemsService::TYPE_PAINT,
                    RoomMapItemsService::TYPE_LOG,
                ])
                ->delete();

            $insertRows = array_merge(
                $this->buildInsertRows($roomMap->id, RoomMapItemsService::TYPE_UNIT, $snapshot->units, $now),
                $this->buildInsertRows($roomMap->id, RoomMapItemsService::TYPE_PAINT, $snapshot->paint, $now, $existingPaintShared),
                $this->buildInsertRows($roomMap->id, RoomMapItemsService::TYPE_LOG, $snapshot->logs, $now)
            );

            foreach (array_chunk($insertRows, 500) as $chunk) {
                RoomMapItem::query()->insert($chunk);
            }

            $room = Room::query()->find($roomMap->room_id);
            if ($room) {
                $room->ingame_time = $snapshot->ingame_time;
                $room->save();
            }
        });

        $this->info("Rollback complete for room_map_id={$roomMapId}, ingame_time={$snapshot->ingame_time->format('Y-m-d H:i:s')}.");

        return self::SUCCESS;
    }

    private function parseIngameTime(string $raw): ?Carbon
    {
        try {
            return Carbon::createFromFormat('Y-m-d H:i:s', $raw);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, bool> $paintSharedByItemId
     * @return array<int, array<string, mixed>>
     */
    private function buildInsertRows(
        int $roomMapId,
        string $type,
        array $items,
        Carbon $now,
        array $paintSharedByItemId = []
    ): array {
        $rows = [];

        foreach ($this->normalizeItems($items) as $itemId => $itemData) {
            $rows[] = [
                'room_map_id' => $roomMapId,
                'type' => $type,
                'item_id' => $itemId,
                // Query Builder insert() does not apply Eloquent casts.
                'data' => json_encode($itemData, JSON_UNESCAPED_UNICODE),
                'shared' => $type === RoomMapItemsService::TYPE_PAINT
                    ? (bool) ($paintSharedByItemId[$itemId] ?? false)
                    : false,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function normalizeItems(array $items): array
    {
        $result = [];

        foreach ($items as $key => $value) {
            if (!is_array($value)) {
                continue;
            }

            $itemId = null;
            if (is_string($key) || is_int($key)) {
                $itemId = (string) $key;
            }
            if (($itemId === null || $itemId === '') && array_key_exists('id', $value)) {
                $itemId = (string) $value['id'];
            }
            if ($itemId === null || $itemId === '') {
                continue;
            }

            $result[$itemId] = $value;
        }

        return $result;
    }
}
