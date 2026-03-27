<?php

namespace App\Services;

use App\Models\RoomMap;
use App\Models\RoomMapItem;

class RoomMapItemsService
{
    public const TYPE_UNIT = 'unit';
    public const TYPE_PAINT = 'paint';
    public const TYPE_LOG = 'log';

    public function getTypeData(RoomMap $roomMap, string $type, array $fallback = []): array
    {
        $items = RoomMapItem::query()
            ->where('room_map_id', $roomMap->id)
            ->where('type', $type)
            ->get();

        if ($items->isEmpty()) {
            return $fallback;
        }

        $result = [];
        foreach ($items as $item) {
            $result[$item->item_id] = $item->data ?? [];
        }

        return $result;
    }
}
