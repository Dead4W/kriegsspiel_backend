<?php

namespace App\Socket\Actions;

class SnapshotBoardAction
{
    public static function run(
        \App\Models\Room $room,
        \App\Models\RoomMap $roomMap,
    ): void {
        $roomMapItemsService = app(\App\Services\RoomMapItemsService::class);

        $snapshot = new \App\Models\Snapshot();
        $snapshot->room_map_id = $roomMap->id;
        $snapshot->units = $roomMapItemsService->getTypeData($roomMap, \App\Services\RoomMapItemsService::TYPE_UNIT);
        $snapshot->paint = $roomMapItemsService->getTypeData($roomMap, \App\Services\RoomMapItemsService::TYPE_PAINT);
        $snapshot->logs = [];
        $snapshot->ingame_time = $room->ingame_time;
        $snapshot->save();
    }
}
