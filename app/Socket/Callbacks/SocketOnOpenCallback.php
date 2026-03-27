<?php

namespace App\Socket\Callbacks;

use App\Enums\ConnectionClientTypeEnum;
use App\Enums\TeamEnum;
use App\Models\Connection;
use App\Models\RoomMapItem;
use App\Models\Session;
use App\Models\UserToken;
use App\Services\RoomMapItemsService;
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
        $token = $request->get['token'] ?? null;
        $targetUserId = null;
        if (!$token) {
            $authHeader = $request->header['authorization'] ?? $request->header['Authorization'] ?? null;
            if ($authHeader && str_starts_with($authHeader, 'Bearer ')) {
                $token = substr($authHeader, 7);
            }
        }

        $userId = null;
        if ($token) {
            $userToken = UserToken::with('user')
                ->where('token', $token)
                ->first();
            if ($userToken?->user) {
                $userId = $userToken->user->id;
            }
        }

        if ($userId === null) {
            $this->warning('Bad new connection "token", disconnecting...');
            SocketErrorAction::run($server, $connectionId, "Token incorrect!");
            return;
        }

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
            $targetUserId = $request->get['user_id'] ?? null;
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

        $roomMapUserId = null;
        if (in_array($teamEnum, [TeamEnum::BLUE, TeamEnum::RED], true)) {
            if ($key === $room->admin_key) {
                $roomMapUserId = is_numeric($targetUserId) ? (int) $targetUserId : null;
            } else {
                $roomMapUserId = $userId;
            }
        }

        $currentConnection = new Connection();
        $currentConnection->id = $connectionId;
        $currentConnection->room_id = $room->id;
        $currentConnection->user_id = $userId;
        $currentConnection->room_map_user_id = $roomMapUserId;
        $currentConnection->team = $team;
        $currentConnection->last_message_at = Carbon::now();
        $currentConnection->save();

        $roomMap = \App\Models\RoomMap::getRoomMapForConnection($currentConnection);

        if (!$roomMap) {
            $this->warning('Not found roomMap, disconnecting...');
            SocketErrorAction::run($server, $connectionId, "Not found roomMap!");
            return;
        }

        $connectionsCount = Connection::count();

        $this->info("Connection <{$currentConnection->id}> open by {$currentConnection->name}. Total connections: {$connectionsCount}");
        $connectionIds = GetOtherListenersAction::run($currentConnection, [TeamEnum::ADMIN, TeamEnum::SPECTATOR]);
        foreach ($connectionIds as $connectionId) {
            $server->push($connectionId, json_encode([
                'type' => 'messages',
                'messages' => [
                    [
                        'type' => 'connection_new',
                        'data' => [
                            'id' => $currentConnection->id,
                            'team' => $currentConnection->team,
                            'user' => $currentConnection->user?->name,
                        ],
                    ]
                ],
            ]));
        }

        $messages = [];
        foreach ($this->getAllMessages($currentConnection, $roomMap) as $message) {
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

    protected function getAllMessages(Connection $currentConnection, \App\Models\RoomMap $roomMap): \Generator {
        $team = $currentConnection->team;
        $roomId = $currentConnection->room_id;
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

        if (in_array($currentConnection->team, [TeamEnum::ADMIN, TeamEnum::SPECTATOR])) {
            $connections = Connection::query()
                ->with('user')
                ->where('id', '!=', $currentConnection->id)
                ->where('room_id', $currentConnection->room_id)
                ->get();
            foreach ($connections as $connection) {
                yield [
                    'type' => 'connection_new',
                    'data' => [
                        'id' => $connection->id,
                        'team' => $connection->team,
                        'user' => $connection->user?->name,
                    ],
                ];
            }
        }

        $roomMapItems = RoomMapItem::query()
            ->where('room_map_id', $roomMap->id)
            ->lazyById(100);
        foreach ($roomMapItems as $roomMapItem) {
            $messageType = match($roomMapItem['type']) {
                RoomMapItemsService::TYPE_UNIT => 'unit',
                RoomMapItemsService::TYPE_PAINT => 'paint_add',
                RoomMapItemsService::TYPE_LOG => 'log',
                default => throw new \Exception("Invalid room map item type: {$roomMapItem['type']}"),
            };
            yield [
                'type' => $messageType,
                'data' => $roomMapItem['data'],
            ];
        }

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
