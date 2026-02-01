<?php

namespace App\Socket\Actions;

class SnapshotBoardAction
{
    public static function run(
        \App\Models\Room $room,
        \App\Models\RoomMap $roomMap,
        array $units,
        array $logs,
        array $paint,
    ): void {
        $snapshot = new \App\Models\Snapshot();
        $snapshot->room_map_id = $roomMap->id;
        $snapshot->units = $units;
        $snapshot->paint = $paint;
        $snapshot->logs = $logs;
        $snapshot->ingame_time = $room->ingame_time;
        $snapshot->save();
    }
}
