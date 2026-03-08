<?php

namespace App\Socket\Callbacks;

use App\Models\Connection;
use App\Socket\Actions\GetOtherListenersAction;
use OpenSwoole\WebSocket\Server;

class SocketOnCloseCallback extends AbstractSocketCallback
{

    public function __invoke(Server $server, int $fd) {
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
