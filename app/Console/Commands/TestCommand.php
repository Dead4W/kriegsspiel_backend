<?php

namespace App\Console\Commands;

use App\Enums\TeamEnum;
use App\Models\RoomMapItem;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class TestCommand extends Command
{
    public $signature = 'tst';

    public $description = 'Test command';

    public function handle() {
        $roomMap = \App\Models\RoomMap::query()
            ->where('id', 364)
            ->firstOrFail();
        $roomId = $roomMap->room_id;

        $adminRoomMap = \App\Models\RoomMap::query()
            ->where('room_id', $roomId)
            ->where('team', TeamEnum::ADMIN)
            ->firstOrFail();

        $roomMapItemsCnt = RoomMapItem::query()
            ->where(function (Builder $query) use ($roomMap, $adminRoomMap) {
                $query->where('room_map_id', $roomMap->id);
                if ($adminRoomMap->id !== $roomMap->id) {
                    $query->orWhere(function (Builder $query) use ($adminRoomMap) {
                        $query
                            ->where('room_map_id', $adminRoomMap->id)
                            ->where('shared', true);
                    });
                }
            })
            ->count();
        echo "TOTAL COUNT: $roomMapItemsCnt";
        sleep(5);

        $roomMapItems = RoomMapItem::query()
            ->where(function (Builder $query) use ($roomMap, $adminRoomMap) {
                $query->where('room_map_id', $roomMap->id);
                if ($adminRoomMap->id !== $roomMap->id) {
                    $query->orWhere(function (Builder $query) use ($adminRoomMap) {
                        $query
                            ->where('room_map_id', $adminRoomMap->id)
                            ->where('shared', true);
                    });
                }
            })
            ->orderBy('id')
            ->lazyById(100);

        $total = 0;
        foreach ($roomMapItems as $roomMapItem) {
            serialize($roomMapItem);
            $total++;
            echo "Total: $total\n";
        }

        echo "END TOTAL\n";
    }

}
