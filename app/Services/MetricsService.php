<?php

namespace App\Services;

use Illuminate\Support\Facades\Redis;

class MetricsService
{
    private const PREFIX = 'kriegsspiel_metrics:';

    public function incrementMessageCount(): void
    {
        Redis::incr(self::PREFIX . 'websocket_messages_total');
    }

    public function addMessageDuration(float $seconds): void
    {
        Redis::incrByFloat(self::PREFIX . 'websocket_message_duration_seconds_total', $seconds);
    }

    public function incrementErrorCount(): void
    {
        Redis::incr(self::PREFIX . 'websocket_messages_errors_total');
    }

    public function getMessageCount(): int
    {
        $value = (int) (Redis::get(self::PREFIX . 'websocket_messages_total') ?: 0);
        Redis::set(self::PREFIX . 'websocket_messages_total', 0);

        return $value;
    }

    public function getMessageDurationTotal(): float
    {
        $value = (float) (Redis::get(self::PREFIX . 'websocket_message_duration_seconds_total') ?: 0);
        Redis::set(self::PREFIX . 'websocket_message_duration_seconds_total', 0);

        return $value;
    }

    public function getErrorCount(): int
    {
        $value = (int) (Redis::get(self::PREFIX . 'websocket_messages_errors_total') ?: 0);
        Redis::set(self::PREFIX . 'websocket_messages_errors_total', 0);

        return $value;
    }
}
