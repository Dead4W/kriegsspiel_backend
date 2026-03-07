<?php

namespace App\Socket\Callbacks;

use App\Models\Connection;
use App\Socket\Actions\GetOtherListenersAction;
use OpenSwoole\WebSocket\Server;

class SocketOnCloseCallback extends AbstractSocketCallback
{

    protected function sentryTransactionName(...$args): string
    {
        return 'socket.close';
    }

    protected function sentryTransactionOp(...$args): string
    {
        return 'socket.close';
    }

    protected function sentryConfigureScope(...$args): void
    {
        parent::sentryConfigureScope(...$args);

        $fd = $args[1] ?? null;

        \Sentry\configureScope(static function (\Sentry\State\Scope $scope) use ($fd): void {
            $scope->setTag('socket.event', 'Close');
            $scope->setContext('socket', [
                'fd' => $fd,
            ]);
        });
    }

    protected function run(...$args): void
    {
        /** @var Server $server */
        $server = $args[0];
        /** @var int $fd */
        $fd = $args[1];

        $currentConnection = Connection::query()
            ->where('id', $fd)
            ->first();

        if ($currentConnection) {
            $connectionIds = GetOtherListenersAction::run($currentConnection);
            foreach ($connectionIds as $connectionId) {
                $server->push($connectionId, json_encode([
                    'type' => 'closed_connection',
                    'meta' => [
                        'from' => $currentConnection->meta_from,
                    ],
                ]));
            }

            $currentConnection->delete();
        }

        $this->info("Disconnect: #{$fd}, total connections: " . Connection::count());
    }

}
