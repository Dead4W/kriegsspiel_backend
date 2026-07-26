<?php

namespace App\Socket\Actions;

use App\Models\RoomMapItem;
use App\Services\RoomMapItemsService;

class CopyBoardAction
{
    public static function run(
        \App\Models\RoomMap $roomMap,
        \App\Enums\TeamEnum $team,
        array &$selfMessages,
    ): void {
        $roomMapsOtherTeam = \App\Models\RoomMap::query()
            ->where('room_id', $roomMap->room_id)
            ->where('team', $team)
            ->get();

        /** @var RoomMapItemsService $roomMapItemsService */
        $roomMapItemsService = app(RoomMapItemsService::class);

        foreach ($roomMapsOtherTeam as $roomMapOtherTeam) {
            $otherRoomMapItems = RoomMapItem::query()
                ->where('room_map_id', $roomMapOtherTeam->id)
                ->where('type', RoomMapItemsService::TYPE_UNIT)
                ->lazyById(100);

            foreach ($otherRoomMapItems as $otherRoomMapItem) {
                $unit = $otherRoomMapItem['data'] ?? [];
                if (!is_array($unit)) continue;
                $copyUnit = $roomMapItemsService->buildUnitCopy($unit, $team, $roomMapOtherTeam->user_id);
                if ($copyUnit === null) continue;
                $unitId = $copyUnit['id'];
                $selfMessages[] = [
                    'type' => 'unit',
                    'data' => $copyUnit,
                    'frames' => [],
                ];

                RoomMapItem::query()->updateOrCreate(
                    [
                        'room_map_id' => $roomMap->id,
                        'type' => RoomMapItemsService::TYPE_UNIT,
                        'item_id' => (string) $unitId,
                    ],
                    [
                        'data' => $copyUnit,
                    ]
                );
            }
        }
    }
}
