<?php

namespace App\Services;

use App\Enums\TeamEnum;
use App\Models\RoomMap;
use App\Models\RoomMapItem;

class RoomMapItemsService
{
    public const TYPE_UNIT = 'unit';
    public const TYPE_PAINT = 'paint';
    public const TYPE_LOG = 'log';

    public const UNIT_COPY_KEYS = [
        'id',
        'type',
        'team',
        'pos',
        'label',
        'envState',
        'hp',
        'ammo',
        'messagesLinked',
    ];

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

    /**
     * Builds the umpire-side copy of a team unit, or null when the unit must not be copied.
     */
    public function buildUnitCopy(array $unit, TeamEnum $team, ?int $roomMapUserId): ?array
    {
        $unitTeam = $unit['team'] ?? null;
        $unitId = $unit['id'] ?? null;
        if (!$unitTeam || $unitTeam !== $team->value || !$unitId) {
            return null;
        }
        if (($unit['type'] ?? null) === 'messenger') {
            return null;
        }

        $copyUnit = [];
        foreach (self::UNIT_COPY_KEYS as $key) {
            $copyUnit[$key] = $unit[$key] ?? null;
        }
        $copyUnit['roomMapUserId'] = $roomMapUserId;

        return $copyUnit;
    }
}
