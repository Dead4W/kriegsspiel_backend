<?php

namespace App\Socket\Callbacks;


use App\Models\Connection;
use App\Models\Session;
use OpenSwoole\WebSocket\Server;

class SocketOnStartCallback extends AbstractSocketCallback
{

    public function __invoke(Server $server) {
        $this->info("Removing old connections...");
        Connection::query()
            ->withoutGlobalScopes()
            ->delete();
        $this->info("WebSocket Server is started at {$server->host}:{$server->port}");
    }
}
