<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class TestCommand extends Command
{
    public $signature = 'tst';

    public $description = 'Test command';

    public function handle() {
        $roomId = 6;
        $team = 'blue';
        $message = ['type' => 'copy_board', 'data' => $team];

        $roomMapOtherTeam = \App\Models\RoomMap::query()
            ->where('room_id', $roomId)
            ->where('team', $team)
            ->firstOrFail();

        $otherTeamUnits = $roomMapOtherTeam->units;
        $otherTeamUnits = array_filter($otherTeamUnits, function ($unit) use ($message) {
            return $unit['team'] && $unit['team'] === $message['data'];
        });
        var_dump($otherTeamUnits);
    }

}
