<?php

namespace App\Socket\Callbacks;


use App\Models\Connection;
use App\Models\Session;
use OpenSwoole\WebSocket\Server;

class SocketOnStartCallback extends AbstractSocketCallback
{

    protected function sentryTransactionName(...$args): string
    {
        return 'socket.start';
    }

    protected function sentryTransactionOp(...$args): string
    {
        return 'socket.start';
    }

    protected function sentryConfigureScope(...$args): void
    {
        parent::sentryConfigureScope(...$args);

        /** @var Server $server */
        $server = $args[0];

        \Sentry\configureScope(static function (\Sentry\State\Scope $scope) use ($server): void {
            $scope->setTag('socket.event', 'Start');
            $scope->setContext('socket', [
                'host' => $server->host,
                'port' => $server->port,
            ]);
        });
    }

    protected function run(...$args): void
    {
        /** @var Server $server */
        $server = $args[0];

        $this->info("Removing old connections...");
        Connection::query()
            ->withoutGlobalScopes()
            ->delete();
        $this->info("WebSocket Server is started at {$server->host}:{$server->port}");
    }
}
