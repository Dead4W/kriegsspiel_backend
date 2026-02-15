<?php

namespace App\Socket\Actions;

class CopyBoardAction
{
    public static function run(
        \App\Models\Connection $connection,
        \App\Enums\TeamEnum $team,
        array &$units,
        array &$selfMessages,
    ): void {
        $roomMapOtherTeam = \App\Models\RoomMap::query()
            ->where('room_id', $connection->room_id)
            ->where('team', $team)
            ->first();

        if (!$roomMapOtherTeam) {
            return;
        }

        $otherTeamUnits = $roomMapOtherTeam->units;
        $otherTeamUnits = array_filter($otherTeamUnits, function ($unit) use ($team) {
            return $unit['team'] && $unit['team'] === $team->value;
        });
        foreach ($otherTeamUnits as $unitUuid => $unit) {
            $copyKeys = ['id', 'type', 'team', 'pos', 'label', 'envState', 'hp', 'ammo', 'messagesLinked'];
            $copyUnit = [];
            foreach ($copyKeys as $key) {
                $copyUnit[$key] = $unit[$key] ?? null;
            }
            $units[$unitUuid] = $copyUnit;
            $selfMessages[] = [
                'type' => 'unit',
                'data' => $copyUnit,
                'frames' => [],
            ];
        }
    }
}
