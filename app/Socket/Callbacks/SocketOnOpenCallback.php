<?php

namespace App\Socket\Callbacks;

use App\Enums\ConnectionClientTypeEnum;
use App\Models\Connection;
use App\Models\Session;
use App\Socket\Actions\GetOtherListenersAction;
use App\Socket\Actions\SocketErrorAction;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use OpenSwoole\Http\Request;
use OpenSwoole\WebSocket\Server;

class SocketOnOpenCallback extends AbstractSocketCallback
{

    public function __invoke(Server $server, Request $request) {
        $this->info("Received connection");

        $connectionId = $request->fd;
        $roomUuid = $request->get['room_id'] ?? '';
        $password = $request->get['password'] ?? '';
        $key = $request->get['key'];

        $room = \App\Models\Room::query()
            ->where('uuid', $roomUuid)
            ->first();

        if ($room === null) {
            $this->warning('Bad new connection "room_id", disconnecting...');
            SocketErrorAction::run($server, $connectionId, "Room not found!");
            return;
        }

        // проверка пароля (если установлен)
        if ($room->password && !$room->password != $password) {
            $this->warning('Bad new connection "password", disconnecting...');
            SocketErrorAction::run($server, $connectionId, "Room password incorrect!");
            return;
        }

        $team = null;
        if ($key === $room->admin_key) {
            $team = $request->get['team'];
        } if ($key === $room->red_key) {
            $team = \App\Enums\TeamEnum::RED->value;
        } if ($key === $room->blue_key) {
            $team = \App\Enums\TeamEnum::BLUE->value;
        }

        if (!$team) {
            $this->warning('Bad new connection "key", disconnecting...');
            SocketErrorAction::run($server, $connectionId, "Team key incorrect!");
            return;
        }

        try {
            $teamEnum = \App\Enums\TeamEnum::from($team);
        } catch (\ValueError) {
            $this->warning('Bad new connection "team", disconnecting...');
            SocketErrorAction::run($server, $connectionId, "Wrong team key '$team'!");
            return;
        }

        $currentConnection = new Connection();
        $currentConnection->id = $connectionId;
        $currentConnection->room_id = $room->id;
        $currentConnection->team = $team;
        $currentConnection->last_message_at = Carbon::now();
        $currentConnection->save();

        \App\Models\RoomMap::query()
            ->where('room_id', $room->id)
            ->where('team', $team)
            ->firstOrCreate([
                'room_id' => $room->id,
                'team' => $team
            ]);

        $connectionsCount = Connection::count();

        $this->info("Connection <{$currentConnection->id}> open by {$currentConnection->name}. Total connections: {$connectionsCount}");

        $messages = [];
        foreach ($this->getAllMessages($currentConnection, $room->id) as $message) {
            $messages[] = $message;
            if (count($messages) >= 100) {
                $server->push($currentConnection->id, json_encode([
                    'type' => 'messages',
                    'messages' => $messages,
                ]));
                $messages = [];
            }
        }

        if (count($messages)) {
            $server->push($currentConnection->id, json_encode([
                'type' => 'messages',
                'messages' => $messages,
            ]));
        }
    }

    protected function getAllMessages(Connection $currentConnection, int $roomId): \Generator {
        $team = $currentConnection->team;
        if ($team === \App\Enums\TeamEnum::SPECTATOR) {
            $team = \App\Enums\TeamEnum::ADMIN;
        }

        $room = \App\Models\Room::query()
            ->where('id', $roomId)
            ->firstOrFail();

        yield [
            'type' => 'room',
            'data' => [
                'uuid' => $room->uuid,
                'stage' => $room->stage,
                'options' => $room->options,
                'name' => $room->name,
                'weather' => $room->weather,
                'ingame_time' => $room->ingame_time->format('Y-m-d H:i:s'),
            ],
        ];

        $roomMap = \App\Models\RoomMap::query()
            ->where('room_id', $roomId)
            ->where('team', $team)
            ->firstOrFail();
        $roomMapUnits = $roomMap->units;

        foreach ($roomMapUnits as $unitData) {
            yield [
                'type' => 'unit',
                'data' => $unitData,
            ];
        }

        foreach ($roomMap->paint as $paint) {
            yield [
                'type' => 'paint_add',
                'data' => $paint,
            ];
        }

//        foreach ($roomMap->logs as $log) {
//            yield [
//                'type' => 'log',
//                'data' => $log,
//            ];
//        }

        $chatMessages = \App\Models\RoomChat::query()
            ->when(
                $team !== \App\Enums\TeamEnum::ADMIN,
                fn ($query) => $query
                    ->where('team', $team)
                    ->where(function ($query) {
                        $query
                            ->where('author_team', '!=', \App\Enums\TeamEnum::ADMIN)
                            ->orWhere('delivered', '1');
                    })
            )
            ->where('room_id', $roomId)
            ->orderBy('ingame_time', 'asc')
            ->lazy(100);

        /** @var \App\Models\RoomChat $chatMessage */
        foreach ($chatMessages as $chatMessage) {
            yield [
                'type' => 'chat',
                'data' => [
                    'id' => $chatMessage->uuid,
                    'author' => $chatMessage->author,
                    'author_team' => $chatMessage->author_team,
                    'status' => $chatMessage->status,
                    'team' => $chatMessage->team,
                    'text' => $chatMessage->data,
                    'time' => $chatMessage->ingame_time->format('Y-m-d H:i:s'),
                    'unitIds' => $chatMessage->unitIds,
                ],
                'meta' => [
                    'ignore' => true,
                ]
            ];
        }
    }
}
