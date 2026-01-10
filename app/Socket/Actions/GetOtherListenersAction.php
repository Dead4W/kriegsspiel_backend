<?php

namespace App\Socket\Actions;

use App\Enums\ConnectionClientTypeEnum;
use App\Models\Connection;
use Illuminate\Support\Collection;
use OpenSwoole\Server;

class GetOtherListenersAction
{

    public static function run(Connection $connection, ?array $forceTeams = null): Collection {
        return Connection::query()
            ->where('id', '!=', $connection->id)
            ->where('room_id', $connection->room_id)
            ->whereIn('team', $forceTeams ?? [$connection->team])
            ->pluck('id');
    }

}
