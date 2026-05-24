<?php

namespace App\Services;

use App\Enums\TeamEnum;
use App\Models\Connection;
use App\Models\Room;
use App\Models\RoomMap;
use App\Models\RoomUser;

class RoomOptionsService
{
    public const KEY_MAX_PLAYERS_PER_TEAM = 'maxPlayersPerTeam';
    public const KEY_LOBBY_SLOTS = 'lobbySlots';
    public const KEY_TEAM_BRIEFING = 'teamBriefing';
    public const KEY_PER_TEAM_SETTINGS = 'perTeamSettings';
    public const KEY_TEAM_UNIT_LIMITS = 'teamUnitLimits';
    public const KEY_TEAM_SPAWN_ZONES = 'teamSpawnZones';
    public const KEY_RED_TEAM_NAME = 'redTeamName';
    public const KEY_BLUE_TEAM_NAME = 'blueTeamName';

    /**
     * Hide enemy-team tactical options for player responses.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function sanitizeOptionsForTeam(array $options, TeamEnum|string|null $team): array
    {
        $teamValue = $team instanceof TeamEnum ? $team->value : (string) $team;
        if (!in_array($teamValue, [TeamEnum::RED->value, TeamEnum::BLUE->value], true)) {
            return $options;
        }

        $sanitized = $options;
        if (array_key_exists(self::KEY_TEAM_BRIEFING, $sanitized)) {
            $briefing = $this->normalizeTeamBriefing($sanitized[self::KEY_TEAM_BRIEFING]);
            $sanitized[self::KEY_TEAM_BRIEFING] = [
                $teamValue => $briefing[$teamValue] ?? '',
            ];
        }
        if (array_key_exists(self::KEY_PER_TEAM_SETTINGS, $sanitized)) {
            $perTeamSettings = is_array($sanitized[self::KEY_PER_TEAM_SETTINGS])
                ? $sanitized[self::KEY_PER_TEAM_SETTINGS]
                : [];
            $sanitized[self::KEY_PER_TEAM_SETTINGS] = $this->sanitizePerTeamSettingsForTeam(
                $perTeamSettings,
                $teamValue
            );
        }

        if (array_key_exists(self::KEY_TEAM_UNIT_LIMITS, $sanitized)) {
            $limits = $this->normalizeTeamUnitLimits($sanitized[self::KEY_TEAM_UNIT_LIMITS]);
            $sanitized[self::KEY_TEAM_UNIT_LIMITS] = [
                $teamValue => $limits[$teamValue] ?? [],
            ];
        }

        if (array_key_exists(self::KEY_TEAM_SPAWN_ZONES, $sanitized)) {
            $zones = $this->normalizeTeamSpawnZones($sanitized[self::KEY_TEAM_SPAWN_ZONES]);
            $sanitized[self::KEY_TEAM_SPAWN_ZONES] = [
                $teamValue => $zones[$teamValue] ?? [],
            ];
        }

        if (array_key_exists(self::KEY_LOBBY_SLOTS, $sanitized)) {
            $slots = $this->normalizeLobbySlots($sanitized[self::KEY_LOBBY_SLOTS]);
            $sanitized[self::KEY_LOBBY_SLOTS] = array_values(array_filter(
                $slots,
                fn (array $slot) => ($slot['team'] ?? null) === $teamValue
            ));
        }

        return $sanitized;
    }

    /**
     * @return array<string, mixed>
     */
    public function normalizeAdminPatch(array $patch): array
    {
        $normalized = [];

        if (array_key_exists(self::KEY_RED_TEAM_NAME, $patch)) {
            $normalized[self::KEY_RED_TEAM_NAME] = trim((string) $patch[self::KEY_RED_TEAM_NAME]);
        }
        if (array_key_exists(self::KEY_BLUE_TEAM_NAME, $patch)) {
            $normalized[self::KEY_BLUE_TEAM_NAME] = trim((string) $patch[self::KEY_BLUE_TEAM_NAME]);
        }

        if (array_key_exists(self::KEY_MAX_PLAYERS_PER_TEAM, $patch)) {
            $normalized[self::KEY_MAX_PLAYERS_PER_TEAM] = $this->normalizePositiveIntOrNull(
                $patch[self::KEY_MAX_PLAYERS_PER_TEAM]
            );
        }
        if (array_key_exists(self::KEY_LOBBY_SLOTS, $patch)) {
            $normalized[self::KEY_LOBBY_SLOTS] = $this->normalizeLobbySlots(
                $patch[self::KEY_LOBBY_SLOTS]
            );
        }

        if (array_key_exists(self::KEY_TEAM_BRIEFING, $patch)) {
            $normalized[self::KEY_TEAM_BRIEFING] = $this->normalizeTeamBriefing(
                $patch[self::KEY_TEAM_BRIEFING]
            );
        }

        if (array_key_exists(self::KEY_TEAM_UNIT_LIMITS, $patch)) {
            $normalized[self::KEY_TEAM_UNIT_LIMITS] = $this->normalizeTeamUnitLimits(
                $patch[self::KEY_TEAM_UNIT_LIMITS]
            );
        }

        if (array_key_exists(self::KEY_TEAM_SPAWN_ZONES, $patch)) {
            $normalized[self::KEY_TEAM_SPAWN_ZONES] = $this->normalizeTeamSpawnZones(
                $patch[self::KEY_TEAM_SPAWN_ZONES]
            );
        }

        return $normalized;
    }

    /**
     * @return array{red: string, blue: string}
     */
    public function getTeamBriefing(Room $room): array
    {
        return $this->normalizeTeamBriefing($room->options[self::KEY_TEAM_BRIEFING] ?? []);
    }

    /**
     * @return array{red: array<string, int|null>, blue: array<string, int|null>}
     */
    public function getTeamUnitLimits(Room $room): array
    {
        return $this->normalizeTeamUnitLimits($room->options[self::KEY_TEAM_UNIT_LIMITS] ?? []);
    }

    /**
     * @return array{red: array<int, array<int, array{x: float, y: float}>>, blue: array<int, array<int, array{x: float, y: float}>>}
     */
    public function getTeamSpawnZones(Room $room): array
    {
        return $this->normalizeTeamSpawnZones($room->options[self::KEY_TEAM_SPAWN_ZONES] ?? []);
    }

    public function getMaxPlayersPerTeam(Room $room): ?int
    {
        return $this->normalizePositiveIntOrNull($room->options[self::KEY_MAX_PLAYERS_PER_TEAM] ?? null) ?? 0;
    }

    /**
     * @return array<int, array{id: int, team: string|null, spawn: array{from: array{x: float, y: float}, to: array{x: float, y: float}}|null}>
     */
    public function getLobbySlots(Room $room): array
    {
        return $this->normalizeLobbySlots($room->options[self::KEY_LOBBY_SLOTS] ?? []);
    }

    public function canJoinTeam(Room $room, TeamEnum $team, ?int $userId): bool
    {
        if (!in_array($team, [TeamEnum::RED, TeamEnum::BLUE], true)) {
            return true;
        }
        if (!$userId) {
            return false;
        }

        $lobbySlots = $this->getLobbySlots($room);
        $hasLobbySlots = count($lobbySlots) > 0;
        $limit = $this->getMaxPlayersPerTeam($room);
        if ($hasLobbySlots) {
            $teamValue = $team->value;
            $limit = count(array_filter(
                $lobbySlots,
                fn (array $slot) => ($slot['team'] ?? null) === $teamValue
            ));
        }
        if ($limit === null) {
            return true;
        }
        if ($limit <= 0) {
            return false;
        }

        $isPlayerRoomMap = (bool) ($room->options['isPlayerRoomMap'] ?? false);
        if ($hasLobbySlots) {
            if ($isPlayerRoomMap) {
                $alreadyInTeam = RoomMap::query()
                    ->where('room_id', $room->id)
                    ->where('team', $team)
                    ->where('user_id', $userId)
                    ->exists();
                if ($alreadyInTeam) {
                    return true;
                }

                $currentCount = RoomMap::query()
                    ->where('room_id', $room->id)
                    ->where('team', $team)
                    ->whereNotNull('user_id')
                    ->distinct('user_id')
                    ->count('user_id');
            } else {
                $alreadyInTeam = Connection::query()
                    ->where('room_id', $room->id)
                    ->where('team', $team)
                    ->where('room_map_user_id', $userId)
                    ->exists();
                if ($alreadyInTeam) {
                    return true;
                }

                $currentCount = Connection::query()
                    ->where('room_id', $room->id)
                    ->where('team', $team)
                    ->whereNotNull('room_map_user_id')
                    ->distinct('room_map_user_id')
                    ->count('room_map_user_id');
            }
        } else {
            $alreadyInTeam = RoomUser::query()
                ->where('room_id', $room->id)
                ->where('team', $team)
                ->where('user_id', $userId)
                ->exists();
            if ($alreadyInTeam) {
                return true;
            }

            $currentCount = RoomUser::query()
                ->where('room_id', $room->id)
                ->where('team', $team)
                ->distinct('user_id')
                ->count('user_id');
        }

        return $currentCount < $limit;
    }

    /**
     * @return array{red?: array<string, mixed>, blue?: array<string, mixed>}
     */
    public function normalizePerTeamSettingsPatch(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $result = [];
        foreach ([TeamEnum::RED->value, TeamEnum::BLUE->value] as $team) {
            if (!array_key_exists($team, $value)) {
                continue;
            }
            $result[$team] = $this->normalizePerTeamSettingsTeamValue($value[$team]);
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $value
     * @return array{red?: array<string, mixed>, blue?: array<string, mixed>}
     */
    public function sanitizePerTeamSettingsPatchForTeam(array $value, TeamEnum|string|null $team): array
    {
        $teamValue = $team instanceof TeamEnum ? $team->value : (string) $team;
        $normalized = $this->normalizePerTeamSettingsPatch($value);
        if (!in_array($teamValue, [TeamEnum::RED->value, TeamEnum::BLUE->value], true)) {
            return $normalized;
        }
        return [
            $teamValue => $normalized[$teamValue] ?? [],
        ];
    }

    /**
     * @return array{red: array<int, array{from: array{x: float, y: float}, to: array{x: float, y: float}}>, blue: array<int, array{from: array{x: float, y: float}, to: array{x: float, y: float}}>}
     */
    public function getPlanningSpawnRectsFromPerTeamSettings(Room $room): array
    {
        $result = [
            TeamEnum::RED->value => [],
            TeamEnum::BLUE->value => [],
        ];

        $perTeamSettings = is_array($room->options[self::KEY_PER_TEAM_SETTINGS] ?? null)
            ? $room->options[self::KEY_PER_TEAM_SETTINGS]
            : [];

        foreach ([TeamEnum::RED->value, TeamEnum::BLUE->value] as $team) {
            $teamSettings = is_array($perTeamSettings[$team] ?? null)
                ? $perTeamSettings[$team]
                : [];
            $result[$team] = $this->normalizePlanningSpawnRects($teamSettings['spawns'] ?? null);
        }

        return $result;
    }

    public function isPlanningSpawnPointAllowed(Room $room, TeamEnum|string|null $team, mixed $pos): bool
    {
        $teamValue = $team instanceof TeamEnum ? $team->value : (string) $team;
        if (!in_array($teamValue, [TeamEnum::RED->value, TeamEnum::BLUE->value], true)) {
            return true;
        }

        $point = $this->normalizePoint($pos);
        if ($point === null) {
            return false;
        }

        $allRects = $this->getPlanningSpawnRectsFromPerTeamSettings($room);
        $teamRects = $allRects[$teamValue] ?? [];
        if (!$teamRects) {
            return true;
        }

        foreach ($teamRects as $rect) {
            if (
                $point['x'] >= $rect['from']['x']
                && $point['x'] <= $rect['to']['x']
                && $point['y'] >= $rect['from']['y']
                && $point['y'] <= $rect['to']['y']
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param mixed $value
     * @return array<int, array{id: int, team: string|null, spawn: array{from: array{x: float, y: float}, to: array{x: float, y: float}}|null}>
     */
    private function normalizeLobbySlots(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $index => $slot) {
            if (!is_array($slot)) {
                continue;
            }
            $rawId = $slot['id'] ?? null;
            $id = is_numeric($rawId) ? max(1, (int) floor((float) $rawId)) : ((int) $index + 1);

            $rawTeam = $slot['team'] ?? null;
            $team = in_array($rawTeam, [TeamEnum::RED->value, TeamEnum::BLUE->value], true)
                ? (string) $rawTeam
                : null;

            $spawn = null;
            if (isset($slot['spawn'])) {
                $spawn = $this->normalizeLobbySlotSpawn($slot['spawn']);
            }

            $result[] = [
                'id' => $id,
                'team' => $team,
                'spawn' => $spawn,
            ];
        }

        return array_values($result);
    }

    /**
     * @param mixed $value
     * @return array{from: array{x: float, y: float}, to: array{x: float, y: float}}|null
     */
    private function normalizeLobbySlotSpawn(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }

        // New rectangle format: { from: {x,y}, to: {x,y} }
        $from = is_array($value['from'] ?? null) ? $value['from'] : null;
        $to = is_array($value['to'] ?? null) ? $value['to'] : null;
        if ($from !== null && $to !== null) {
            $fromX = $from['x'] ?? null;
            $fromY = $from['y'] ?? null;
            $toX = $to['x'] ?? null;
            $toY = $to['y'] ?? null;
            if (is_numeric($fromX) && is_numeric($fromY) && is_numeric($toX) && is_numeric($toY)) {
                $left = min((float) $fromX, (float) $toX);
                $right = max((float) $fromX, (float) $toX);
                $top = min((float) $fromY, (float) $toY);
                $bottom = max((float) $fromY, (float) $toY);
                return [
                    'from' => [
                        'x' => $left,
                        'y' => $top,
                    ],
                    'to' => [
                        'x' => $right,
                        'y' => $bottom,
                    ],
                ];
            }
        }

        return null;
    }

    /**
     * @param mixed $value
     */
    private function normalizePositiveIntOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_string($value)) {
            $value = trim($value);
            if ($value === '') {
                return null;
            }
        }
        if (!is_numeric($value)) {
            return null;
        }

        $normalized = (int) floor((float) $value);
        if ($normalized <= 0) {
            return null;
        }
        return $normalized;
    }

    /**
     * @param mixed $value
     * @return array{red: string, blue: string}
     */
    private function normalizeTeamBriefing(mixed $value): array
    {
        $result = [
            TeamEnum::RED->value => '',
            TeamEnum::BLUE->value => '',
        ];
        if (!is_array($value)) {
            return $result;
        }

        foreach ([TeamEnum::RED->value, TeamEnum::BLUE->value] as $team) {
            if (array_key_exists($team, $value)) {
                $result[$team] = (string) $value[$team];
            }
        }

        return $result;
    }

    /**
     * @param mixed $value
     * @return array{red: array<string, int|null>, blue: array<string, int|null>}
     */
    private function normalizeTeamUnitLimits(mixed $value): array
    {
        $result = [
            TeamEnum::RED->value => [],
            TeamEnum::BLUE->value => [],
        ];
        if (!is_array($value)) {
            return $result;
        }

        foreach ([TeamEnum::RED->value, TeamEnum::BLUE->value] as $team) {
            $teamLimits = $value[$team] ?? null;
            if (!is_array($teamLimits)) {
                continue;
            }
            foreach ($teamLimits as $unitType => $limit) {
                $result[$team][(string) $unitType] = $this->normalizePositiveIntOrNull($limit);
            }
        }

        return $result;
    }

    /**
     * @param mixed $value
     * @return array{red: array<int, array<int, array{x: float, y: float}>>, blue: array<int, array<int, array{x: float, y: float}>>}
     */
    private function normalizeTeamSpawnZones(mixed $value): array
    {
        $result = [
            TeamEnum::RED->value => [],
            TeamEnum::BLUE->value => [],
        ];
        if (!is_array($value)) {
            return $result;
        }

        foreach ([TeamEnum::RED->value, TeamEnum::BLUE->value] as $team) {
            $zones = $value[$team] ?? null;
            if (!is_array($zones)) {
                continue;
            }

            foreach ($zones as $zone) {
                if (!is_array($zone)) {
                    continue;
                }

                $points = [];
                foreach ($zone as $point) {
                    if (!is_array($point)) {
                        continue;
                    }
                    $x = isset($point['x']) && is_numeric($point['x']) ? (float) $point['x'] : null;
                    $y = isset($point['y']) && is_numeric($point['y']) ? (float) $point['y'] : null;
                    if ($x === null || $y === null) {
                        continue;
                    }
                    $points[] = ['x' => $x, 'y' => $y];
                }

                if (count($points) >= 3) {
                    $result[$team][] = $points;
                }
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $value
     * @return array<string, mixed>
     */
    private function sanitizePerTeamSettingsForTeam(array $value, string $teamValue): array
    {
        if (!in_array($teamValue, [TeamEnum::RED->value, TeamEnum::BLUE->value], true)) {
            return $this->normalizePerTeamSettingsPatch($value);
        }

        $normalized = $this->normalizePerTeamSettingsPatch($value);
        return [
            $teamValue => $normalized[$teamValue] ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizePerTeamSettingsTeamValue(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $key => $itemValue) {
            $normalizedKey = trim((string) $key);
            if ($normalizedKey === '') {
                continue;
            }
            $result[$normalizedKey] = $this->normalizePerTeamSettingsValue($itemValue);
        }

        return $result;
    }

    private function normalizePerTeamSettingsValue(mixed $value, int $depth = 0): mixed
    {
        if ($depth >= 6) {
            return null;
        }
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
            return $value;
        }
        if (!is_array($value)) {
            return (string) $value;
        }

        $isSequential = array_is_list($value);
        $result = [];
        foreach ($value as $key => $item) {
            if ($isSequential) {
                $result[] = $this->normalizePerTeamSettingsValue($item, $depth + 1);
                continue;
            }
            $normalizedKey = trim((string) $key);
            if ($normalizedKey === '') {
                continue;
            }
            $result[$normalizedKey] = $this->normalizePerTeamSettingsValue($item, $depth + 1);
        }

        return $result;
    }

    /**
     * @return array<int, array{from: array{x: float, y: float}, to: array{x: float, y: float}}>
     */
    private function normalizePlanningSpawnRects(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $item) {
            if (!is_array($item)) {
                continue;
            }
            $from = $this->normalizePoint($item['from'] ?? null);
            $to = $this->normalizePoint($item['to'] ?? null);
            if ($from === null || $to === null) {
                continue;
            }
            $result[] = [
                'from' => [
                    'x' => min($from['x'], $to['x']),
                    'y' => min($from['y'], $to['y']),
                ],
                'to' => [
                    'x' => max($from['x'], $to['x']),
                    'y' => max($from['y'], $to['y']),
                ],
            ];
        }

        return $result;
    }

    /**
     * @return array{x: float, y: float}|null
     */
    private function normalizePoint(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }
        if (!array_key_exists('x', $value) || !array_key_exists('y', $value)) {
            return null;
        }
        if (!is_numeric($value['x']) || !is_numeric($value['y'])) {
            return null;
        }

        return [
            'x' => (float) $value['x'],
            'y' => (float) $value['y'],
        ];
    }
}
