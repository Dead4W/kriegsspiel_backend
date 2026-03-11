<?php

namespace App\Services;

use Illuminate\Support\Facades\Redis;

class MetricsService
{
    private const PREFIX = 'kriegsspiel_metrics:';

    public function incrementMessageCount(): void
    {
        $this->safeRedis(fn () => Redis::incr(self::PREFIX . 'websocket_messages_total'));
    }

    public function addMessageDuration(float $seconds): void
    {
        $this->safeRedis(fn () => Redis::incrByFloat(self::PREFIX . 'websocket_message_duration_seconds_total', $seconds));
    }

    public function incrementErrorCount(): void
    {
        $this->safeRedis(fn () => Redis::incr(self::PREFIX . 'websocket_messages_errors_total'));
    }

    public function getMessageCount(): int
    {
        return (int) $this->safeRedis(fn () => Redis::get(self::PREFIX . 'websocket_messages_total') ?: 0);
    }

    public function getMessageDurationTotal(): float
    {
        return (float) $this->safeRedis(fn () => Redis::get(self::PREFIX . 'websocket_message_duration_seconds_total') ?: 0);
    }

    public function getErrorCount(): int
    {
        return (int) $this->safeRedis(fn () => Redis::get(self::PREFIX . 'websocket_messages_errors_total') ?: 0);
    }

    private function safeRedis(callable $fn)
    {
        return $fn();
    }
}
