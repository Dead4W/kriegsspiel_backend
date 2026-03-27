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

        foreach ($roomMapsOtherTeam as $roomMapOtherTeam) {
            $otherRoomMapItems = RoomMapItem::query()
                ->where('room_map_id', $roomMapOtherTeam->id)
                ->where('type', RoomMapItemsService::TYPE_UNIT)
                ->lazyById(100);

            foreach ($otherRoomMapItems as $otherRoomMapItem) {
                $unit = $otherRoomMapItem['data'] ?? [];
                if (!is_array($unit)) continue;
                $unitTeam = $unit['team'] ?? null;
                $unitId = $unit['id'] ?? null;
                if (!$unitTeam || $unitTeam !== $team->value || !$unitId) continue;
                $copyKeys = ['id', 'type', 'team', 'pos', 'label', 'envState', 'hp', 'ammo', 'messagesLinked'];
                $copyUnit = [];
                foreach ($copyKeys as $key) {
                    $copyUnit[$key] = $unit[$key] ?? null;
                }
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
