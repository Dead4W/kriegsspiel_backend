<?php

namespace App\Socket\Callbacks;

use App\Enums\TeamEnum;
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
            $connectionIds = GetOtherListenersAction::run($currentConnection, [TeamEnum::ADMIN, TeamEnum::SPECTATOR]);
            foreach ($connectionIds as $connectionId) {
                $server->push($connectionId, json_encode([
                    'type' => 'messages',
                    'messages' => [
                        [
                            'type' => 'connection_close',
                            'data' => [
                                'id' => $currentConnection->id,
                            ],
                        ]
                    ],
                ]));
            }

            $currentConnection->delete();
        }

        $this->info("Disconnect: #{$fd}, total connections: " . Connection::count());
    }

}
