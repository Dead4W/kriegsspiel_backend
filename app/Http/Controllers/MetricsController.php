<?php

namespace App\Http\Controllers;

use App\Models\Connection;
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

        // Current WebSocket connections (gauge)
        $connectionsCount = Connection::count();
        $lines[] = '# HELP websocket_connections_current Current number of active WebSocket connections';
        $lines[] = '# TYPE websocket_connections_current gauge';
        $lines[] = "kriegsspiel.websocket_connections_current {$connectionsCount}";

        // Connections by team (optional breakdown)
        $connectionsByTeam = Connection::query()
            ->selectRaw('team, count(*) as count')
            ->groupBy('team')
            ->get();

        if ($connectionsByTeam->isNotEmpty()) {
            $lines[] = '';
            $lines[] = '# HELP websocket_connections_by_team Current WebSocket connections grouped by team';
            $lines[] = '# TYPE websocket_connections_by_team gauge';
            foreach ($connectionsByTeam as $row) {
                $team = $this->escapeLabelValue((string) $row->team);
                $lines[] = "websocket_connections_by_team{team=\"{$team}\"} {$row->count}";
            }
        }

        return implode("\n", $lines) . "\n";
    }

    private function escapeLabelValue(string $value): string
    {
        return str_replace(['\\', "\n", '"'], ['\\\\', '\\n', '\\"'], $value);
    }
}
