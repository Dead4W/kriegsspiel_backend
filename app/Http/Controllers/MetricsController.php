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
        $lines[] = "kriegsspiel.websocket_connections_current {$connectionsCount}";

        return implode("\n", $lines) . "\n";
    }
}
