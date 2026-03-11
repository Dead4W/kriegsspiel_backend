<?php

namespace App\Http\Controllers;

use App\Models\Connection;
use App\Models\Room;
use App\Models\RoomChat;
use App\Models\Snapshot;
use App\Models\User;
use Illuminate\Http\Response;

class MetricsController extends Controller
{
    /**
     * Return metrics in Prometheus exposition format.
     */
    public function __invoke(): Response
    {
        $metrics = $this->collectMetrics();

        return response($metrics, 200, [
            'Content-Type' => 'text/plain; version=0.0.4; charset=utf-8',
        ]);
    }

    private function collectMetrics(): string
    {
        $lines = [];

        // WebSocket connections
        $connectionsCount = Connection::count();
        $lines[] = "kriegsspiel_websocket_connections_current {$connectionsCount}";

        $connectionsByTeam = Connection::query()
            ->selectRaw('team, count(*) as count')
            ->groupBy('team')
            ->get();

        foreach ($connectionsByTeam as $row) {
            $team = $this->escapeLabelValue($row->team->value);
            $lines[] = "kriegsspiel_websocket_connections_by_team{team=\"{$team}\"} {$row->count}";
        }

        $connectionsByRoom = Connection::query()
            ->selectRaw('room_id, count(*) as count')
            ->groupBy('room_id')
            ->get();

        foreach ($connectionsByRoom as $row) {
            $lines[] = "kriegsspiel_websocket_connections_by_room{room_id=\"{$row->room_id}\"} {$row->count}";
        }

        // Rooms
        $lines[] = 'kriegsspiel_rooms_total ' . Room::count();

        $activeRoomsCount = Room::query()
            ->whereIn('id', Connection::query()->select('room_id')->distinct())
            ->count();
        $lines[] = "kriegsspiel_rooms_active {$activeRoomsCount}";

        $roomsByStage = Room::query()
            ->selectRaw('stage, count(*) as count')
            ->groupBy('stage')
            ->get();

        foreach ($roomsByStage as $row) {
            $stage = $this->escapeLabelValue($row->stage);
            $lines[] = "kriegsspiel_rooms_by_stage{stage=\"{$stage}\"} {$row->count}";
        }

        // Users & content
        $lines[] = 'kriegsspiel_users_total ' . User::count();
        $lines[] = 'kriegsspiel_snapshots_total ' . Snapshot::count();
        $lines[] = 'kriegsspiel_chats_total ' . RoomChat::count();

        return implode("\n", $lines) . "\n";
    }

    private function escapeLabelValue(string $value): string
    {
        return str_replace(['\\', "\n", '"'], ['\\\\', '\\n', '\\"'], $value);
    }
}
