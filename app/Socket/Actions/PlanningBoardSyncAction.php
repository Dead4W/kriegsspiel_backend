<?php

namespace App\Socket\Actions;

use App\Enums\TeamEnum;
use App\Models\Connection;
use App\Models\Room;
use App\Models\RoomMap;
use App\Models\RoomMapItem;
use App\Services\RoomMapItemsService;
use Illuminate\Support\Collection;

/**
 * Keeps the player boards and the umpire board in sync while the room is in the planning stage.
 */
class PlanningBoardSyncAction
{
    public static function resolveUmpireRoomMap(Room $room, Connection $connection): ?RoomMap
    {
        if ($room->stage !== 'planning') {
            return null;
        }
        if (!in_array($connection->team, [TeamEnum::BLUE, TeamEnum::RED], true)) {
            return null;
        }

        return RoomMap::query()
            ->where('room_id', $room->id)
            ->where('team', TeamEnum::ADMIN)
            ->first();
    }

    public static function isUmpireSource(Connection $connection): bool
    {
        return $connection->team === TeamEnum::ADMIN;
    }

    public static function syncUnitToUmpire(
        Room $room,
        RoomMap $umpireRoomMap,
        RoomMap $sourceRoomMap,
        TeamEnum $team,
        array $unit,
        array $frames,
        array &$messagesByTeam,
    ): void {
        if ($room->stage !== 'planning') {
            return;
        }

        /** @var RoomMapItemsService $roomMapItemsService */
        $roomMapItemsService = app(RoomMapItemsService::class);
        $copyUnit = $roomMapItemsService->buildUnitCopy($unit, $team, $sourceRoomMap->user_id);
        if ($copyUnit === null) {
            return;
        }

        RoomMapItem::query()->updateOrCreate(
            [
                'room_map_id' => $umpireRoomMap->id,
                'type' => RoomMapItemsService::TYPE_UNIT,
                'item_id' => (string) $copyUnit['id'],
            ],
            [
                'data' => $copyUnit,
            ]
        );

        $messagesByTeam[TeamEnum::ADMIN->value][] = [
            'type' => 'unit',
            'data' => $copyUnit,
            'frames' => $frames,
        ];
    }

    public static function removeUnitsFromUmpire(
        Room $room,
        RoomMap $umpireRoomMap,
        RoomMap $sourceRoomMap,
        TeamEnum $team,
        array $unitIds,
        array &$messagesByTeam,
    ): void {
        if ($room->stage !== 'planning') {
            return;
        }

        $unitIds = self::normalizeUnitIds($unitIds);
        if (!$unitIds) {
            return;
        }

        $removableIds = self::loadUnits($umpireRoomMap->id, $unitIds)
            ->filter(function (RoomMapItem $item) use ($team, $sourceRoomMap): bool {
                $unit = $item->data;
                if (!self::isSyncableUnit($unit)) {
                    return false;
                }
                if (($unit['team'] ?? null) !== $team->value) {
                    return false;
                }
                if ($sourceRoomMap->user_id === null) {
                    return true;
                }

                return (int) ($unit['roomMapUserId'] ?? 0) === (int) $sourceRoomMap->user_id;
            })
            ->map(fn (RoomMapItem $item) => (string) $item->item_id)
            ->values()
            ->all();

        if (!$removableIds) {
            return;
        }

        self::deleteUnits($umpireRoomMap->id, $removableIds);

        $messagesByTeam[TeamEnum::ADMIN->value][] = [
            'type' => 'unit-remove',
            'data' => $removableIds,
        ];
    }

    public static function syncUnitToPlayers(
        Room $room,
        array $unit,
        array $frames,
        array &$messagesByTeam,
        array &$messagesByTeamUser,
    ): void {
        if ($room->stage !== 'planning') {
            return;
        }
        if (!self::isSyncableUnit($unit) || !($unit['id'] ?? null)) {
            return;
        }
        $team = TeamEnum::tryFrom((string) ($unit['team'] ?? ''));
        if (!in_array($team, [TeamEnum::BLUE, TeamEnum::RED], true)) {
            return;
        }

        foreach (self::resolveTargetRoomMaps($room, $team, $unit) as $targetRoomMap) {
            $existingUnit = RoomMapItem::query()
                ->where('room_map_id', $targetRoomMap->id)
                ->where('type', RoomMapItemsService::TYPE_UNIT)
                ->where('item_id', (string) $unit['id'])
                ->first();

            $targetUnit = is_array($existingUnit?->data) ? $existingUnit->data : [];
            foreach (RoomMapItemsService::UNIT_COPY_KEYS as $key) {
                $targetUnit[$key] = $unit[$key] ?? null;
            }
            $targetUnit['roomMapUserId'] = $unit['roomMapUserId'] ?? $targetRoomMap->user_id;

            RoomMapItem::query()->updateOrCreate(
                [
                    'room_map_id' => $targetRoomMap->id,
                    'type' => RoomMapItemsService::TYPE_UNIT,
                    'item_id' => (string) $unit['id'],
                ],
                [
                    'data' => $targetUnit,
                ]
            );

            self::pushToRoomMap($targetRoomMap, $team, [
                'type' => 'unit',
                'data' => $targetUnit,
                'frames' => $frames,
            ], $messagesByTeam, $messagesByTeamUser);
        }
    }

    public static function removeUnitsFromPlayers(
        Room $room,
        array $unitIds,
        array &$messagesByTeam,
        array &$messagesByTeamUser,
    ): void {
        if ($room->stage !== 'planning') {
            return;
        }

        $unitIds = self::normalizeUnitIds($unitIds);
        if (!$unitIds) {
            return;
        }

        $playerRoomMaps = RoomMap::query()
            ->where('room_id', $room->id)
            ->whereIn('team', [TeamEnum::BLUE, TeamEnum::RED])
            ->get();

        foreach ($playerRoomMaps as $playerRoomMap) {
            $removableIds = self::loadUnits($playerRoomMap->id, $unitIds)
                ->filter(fn (RoomMapItem $item) => self::isSyncableUnit($item->data))
                ->map(fn (RoomMapItem $item) => (string) $item->item_id)
                ->values()
                ->all();

            if (!$removableIds) {
                continue;
            }

            self::deleteUnits($playerRoomMap->id, $removableIds);

            self::pushToRoomMap($playerRoomMap, $playerRoomMap->team, [
                'type' => 'unit-remove',
                'data' => $removableIds,
            ], $messagesByTeam, $messagesByTeamUser);
        }
    }

    /**
     * With separate player boards a unit belongs to the board of its creator,
     * otherwise to every board of its team.
     */
    private static function resolveTargetRoomMaps(Room $room, TeamEnum $team, array $unit): Collection
    {
        $query = RoomMap::query()
            ->where('room_id', $room->id)
            ->where('team', $team);

        if ($room->options['isPlayerRoomMap'] ?? false) {
            $roomMapUserId = (int) ($unit['roomMapUserId'] ?? 0);
            if ($roomMapUserId <= 0) {
                return collect();
            }
            $query->where('user_id', $roomMapUserId);
        }

        return $query->get();
    }

    private static function pushToRoomMap(
        RoomMap $roomMap,
        TeamEnum $team,
        array $message,
        array &$messagesByTeam,
        array &$messagesByTeamUser,
    ): void {
        if ($roomMap->user_id) {
            $messagesByTeamUser[$team->value][$roomMap->user_id][] = $message;
        } else {
            $messagesByTeam[$team->value][] = $message;
        }
    }

    private static function isSyncableUnit(mixed $unit): bool
    {
        return is_array($unit) && ($unit['type'] ?? null) !== 'messenger';
    }

    private static function normalizeUnitIds(array $unitIds): array
    {
        return array_values(array_filter(array_map('strval', $unitIds), fn (string $id) => $id !== ''));
    }

    private static function loadUnits(int $roomMapId, array $unitIds): Collection
    {
        return RoomMapItem::query()
            ->where('room_map_id', $roomMapId)
            ->where('type', RoomMapItemsService::TYPE_UNIT)
            ->whereIn('item_id', $unitIds)
            ->get(['item_id', 'data']);
    }

    private static function deleteUnits(int $roomMapId, array $unitIds): void
    {
        RoomMapItem::query()
            ->where('room_map_id', $roomMapId)
            ->where('type', RoomMapItemsService::TYPE_UNIT)
            ->whereIn('item_id', $unitIds)
            ->delete();
    }
}
