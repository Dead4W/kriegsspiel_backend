<?php

namespace App\Socket\Actions;

use OpenSwoole\Server;

class SocketErrorAction
{

    public static function run(Server $server, int $connectionId, string $error) {
        $server->push($connectionId, "ERROR: $error");
        $server->close($connectionId);
    }

}
