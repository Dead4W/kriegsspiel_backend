<?php

namespace App\Services;

use App\Enums\TeamEnum;
use App\Models\Room;
use App\Models\RoomMap;
use App\Models\RoomMapItem;
use App\Services\RoomMapItemsService;

class RoomUnitLimitsService
{
    public function canSpawnUnit(Room $room, array $unitData): bool
    {
        $team = (string) ($unitData['team'] ?? '');
        if (!in_array($team, [TeamEnum::RED->value, TeamEnum::BLUE->value], true)) {
            return true;
        }

        if ($room->stage === 'planning') {
            /** @var RoomOptionsService $roomOptionsService */
            $roomOptionsService = app(RoomOptionsService::class);
            if (!$roomOptionsService->isPlanningSpawnPointAllowed($room, $team, $unitData['pos'] ?? null)) {
                return false;
            }
        }

        $limits = $this->getTeamUnitLimits($room);
        $teamLimits = $limits[$team] ?? [];
        $unitType = (string) ($unitData['type'] ?? '');
        if ($unitType === '' || !array_key_exists($unitType, $teamLimits)) {
            return true;
        }

        $limit = $teamLimits[$unitType];
        if (!is_int($limit) || $limit <= 0) {
            return false;
        }

        $usage = $this->getUsageByTeamAndType($room);
        return (($usage[$team][$unitType] ?? 0) + 1) <= $limit;
    }

    public function buildUsagePayload(Room $room): array
    {
        $limits = $this->getTeamUnitLimits($room);
        $usage = $this->getUsageByTeamAndType($room);

        $payload = [
            TeamEnum::RED->value => [],
            TeamEnum::BLUE->value => [],
        ];
        foreach ([TeamEnum::RED->value, TeamEnum::BLUE->value] as $team) {
            $allTypes = array_unique(array_merge(
                array_keys($limits[$team] ?? []),
                array_keys($usage[$team] ?? []),
            ));
            foreach ($allTypes as $unitType) {
                $payload[$team][$unitType] = [
                    'used' => (int) ($usage[$team][$unitType] ?? 0),
                    'limit' => $limits[$team][$unitType] ?? null,
                ];
            }
        }

        return $payload;
    }

    /**
     * @return array{red: array<string, int|null>, blue: array<string, int|null>}
     */
    private function getTeamUnitLimits(Room $room): array
    {
        /** @var RoomOptionsService $roomOptionsService */
        $roomOptionsService = app(RoomOptionsService::class);
        return $roomOptionsService->getTeamUnitLimits($room);
    }

    /**
     * @return array{red: array<string, int>, blue: array<string, int>}
     */
    private function getUsageByTeamAndType(Room $room): array
    {
        $result = [
            TeamEnum::RED->value => [],
            TeamEnum::BLUE->value => [],
        ];

        $adminRoomMapId = (int) RoomMap::query()
            ->where('room_id', $room->id)
            ->where('team', TeamEnum::ADMIN)
            ->value('id');
        if ($adminRoomMapId <= 0) {
            return $result;
        }

        $units = RoomMapItem::query()
            ->where('room_map_id', $adminRoomMapId)
            ->where('type', RoomMapItemsService::TYPE_UNIT)
            ->get(['data']);

        foreach ($units as $unitItem) {
            $unitData = is_array($unitItem->data) ? $unitItem->data : [];
            $team = (string) ($unitData['team'] ?? '');
            $unitType = (string) ($unitData['type'] ?? '');
            if (
                !in_array($team, [TeamEnum::RED->value, TeamEnum::BLUE->value], true)
                || $unitType === ''
            ) {
                continue;
            }
            $result[$team][$unitType] = (int) ($result[$team][$unitType] ?? 0) + 1;
        }

        return $result;
    }
}
