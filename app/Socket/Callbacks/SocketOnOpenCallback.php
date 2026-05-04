<?php

namespace App\Socket\Callbacks;

use App\Enums\ConnectionClientTypeEnum;
use App\Enums\TeamEnum;
use App\Models\Connection;
use App\Models\RoomMapItem;
use App\Models\RoomUser;
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
        $isReady = null;
        if (
            $room->stage === 'planning'
            && in_array($currentConnection->team, [TeamEnum::RED, TeamEnum::BLUE], true)
            && $currentConnection->room_map_user_id
        ) {
            $isReady = (bool) RoomUser::query()
                ->where('room_id', $currentConnection->room_id)
                ->where('user_id', $currentConnection->room_map_user_id)
                ->value('is_ready');
        }
        $newConnectionData = [
            'id' => $currentConnection->id,
            'team' => $currentConnection->team,
            'user' => $currentConnection->user?->name,
        ];
        if ($room->stage === 'planning' && in_array($currentConnection->team, [TeamEnum::RED, TeamEnum::BLUE], true)) {
            $newConnectionData['user_id'] = $currentConnection->room_map_user_id;
            $newConnectionData['is_ready'] = $isReady;
        }
        foreach ($connectionIds as $connectionId) {
            $messages = [
                [
                    'type' => 'connection_new',
                    'data' => $newConnectionData,
                ]
            ];
            if (
                $room->stage === 'planning'
                && in_array($currentConnection->team, [TeamEnum::RED, TeamEnum::BLUE], true)
                && $currentConnection->room_map_user_id
            ) {
                $messages[] = [
                    'type' => 'room_user_ready',
                    'data' => [
                        'user_id' => $currentConnection->room_map_user_id,
                        'user' => $currentConnection->user?->name,
                        'team' => $currentConnection->team->value,
                        'is_ready' => (bool) $isReady,
                    ],
                ];
            }
            $server->push($connectionId, json_encode([
                'type' => 'messages',
                'messages' => $messages,
            ]));
        }

        $messages = [];
        foreach ($this->getAllMessages($currentConnection, $roomMap) as $message) {
            $messages[] = $message;
            if (count($messages) >= 30) {
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

        $adminRoomMap = \App\Models\RoomMap::query()
            ->where('room_id', $roomId)
            ->where('team', TeamEnum::ADMIN)
            ->firstOrFail();

        $room = \App\Models\Room::query()
            ->where('id', $roomId)
            ->firstOrFail();
        $isPlayerRoomMap = (bool) ($room->options['isPlayerRoomMap'] ?? false);

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

        if ($room->stage === 'planning') {
            if (in_array($currentConnection->team, [TeamEnum::ADMIN, TeamEnum::SPECTATOR], true)) {
                $readyRoomUsers = RoomUser::query()
                    ->with('user')
                    ->where('room_id', $currentConnection->room_id)
                    ->whereIn('team', [TeamEnum::RED, TeamEnum::BLUE])
                    ->get(['user_id', 'team', 'is_ready']);
                foreach ($readyRoomUsers as $readyRoomUser) {
                    yield [
                        'type' => 'room_user_ready',
                        'data' => [
                            'user_id' => $readyRoomUser->user_id,
                            'user' => $readyRoomUser->user?->name,
                            'team' => $readyRoomUser->team->value,
                            'is_ready' => (bool) $readyRoomUser->is_ready,
                        ],
                    ];
                }
            } elseif (
                in_array($currentConnection->team, [TeamEnum::RED, TeamEnum::BLUE], true)
                && $currentConnection->room_map_user_id
            ) {
                $selfReady = RoomUser::query()
                    ->with('user')
                    ->where('room_id', $currentConnection->room_id)
                    ->where('user_id', $currentConnection->room_map_user_id)
                    ->where('team', $currentConnection->team)
                    ->first(['user_id', 'team', 'is_ready']);
                if ($selfReady) {
                    yield [
                        'type' => 'room_user_ready',
                        'data' => [
                            'user_id' => $selfReady->user_id,
                            'user' => $selfReady->user?->name,
                            'team' => $selfReady->team->value,
                            'is_ready' => (bool) $selfReady->is_ready,
                        ],
                    ];
                }
            }
        }

        if (in_array($currentConnection->team, [TeamEnum::ADMIN, TeamEnum::SPECTATOR])) {
            $connections = Connection::query()
                ->with('user')
                ->where('id', '!=', $currentConnection->id)
                ->where('room_id', $currentConnection->room_id)
                ->get();
            $isReadyByUserId = [];
            if ($room->stage === 'planning') {
                $playerUserIds = $connections
                    ->whereIn('team', [TeamEnum::RED, TeamEnum::BLUE])
                    ->pluck('room_map_user_id')
                    ->filter()
                    ->map(fn ($userId) => (int) $userId)
                    ->unique()
                    ->values()
                    ->all();
                if ($playerUserIds) {
                    $isReadyByUserId = RoomUser::query()
                        ->where('room_id', $currentConnection->room_id)
                        ->whereIn('user_id', $playerUserIds)
                        ->pluck('is_ready', 'user_id')
                        ->map(fn ($isReady) => (bool) $isReady)
                        ->all();
                }
            }
            foreach ($connections as $connection) {
                $isReady = null;
                if (
                    $room->stage === 'planning'
                    && in_array($connection->team, [TeamEnum::RED, TeamEnum::BLUE], true)
                    && $connection->room_map_user_id
                ) {
                    $isReady = $isReadyByUserId[$connection->room_map_user_id] ?? false;
                }
                $connectionData = [
                    'id' => $connection->id,
                    'team' => $connection->team,
                    'user' => $connection->user?->name,
                ];
                if ($room->stage === 'planning' && in_array($connection->team, [TeamEnum::RED, TeamEnum::BLUE], true)) {
                    $connectionData['user_id'] = $connection->room_map_user_id;
                    $connectionData['is_ready'] = $isReady;
                }
                yield [
                    'type' => 'connection_new',
                    'data' => $connectionData,
                ];
            }
        }

        $roomMapItems = RoomMapItem::query()
            ->where('room_map_id', $roomMap->id)
            ->when($adminRoomMap->id !== $roomMap->id, function (Builder $query) use ($adminRoomMap) {
                $query->orWhere(function (Builder $query) use ($adminRoomMap) {
                    $query
                        ->where('room_map_id', $adminRoomMap->id)
                        ->where('shared', true);
                });
            })
            ->orderBy('id')
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
                    ->when($isPlayerRoomMap, function ($query) use ($roomMap) {
                        $query->whereHas('roomMaps', function (Builder $roomMapQuery) use ($roomMap) {
                            $roomMapQuery->where('room_maps.id', $roomMap->id);
                        });
                    })
                    ->where(function ($query) use ($isPlayerRoomMap, $roomMap) {
                        $query
                            ->where('author_team', '!=', \App\Enums\TeamEnum::ADMIN)
                            ->orWhere('delivered', '1');
                    })
            )
            ->where('room_id', $roomId)
            ->orderByRaw('COALESCE(delivered_at, created_at) asc')
            ->orderBy('id', 'asc')
            ->lazy(100);

        $authorAvatars = \App\Models\User::query()
            ->whereIn('id', function ($query) use ($roomId) {
                $query->from('room_chats')
                    ->select('user_id')
                    ->where('room_id', $roomId)
                    ->whereNotNull('user_id')
                    ->distinct();
            })
            ->get(['id', 'avatar'])
            ->reduce(function (array $carry, \App\Models\User $user) {
                $carry[(int) $user->id] = $user->avatar;
                return $carry;
            }, []);

        $authorIdentityMap = RoomUser::query()
            ->where('room_id', $roomId)
            ->with('user:id,name,avatar')
            ->get()
            ->reduce(function (array $carry, RoomUser $roomUser) {
                $authorTeam = $roomUser->team instanceof TeamEnum
                    ? $roomUser->team->value
                    : (string) $roomUser->team;
                $authorName = $roomUser->user?->name;
                if (!$authorName) {
                    return $carry;
                }
                $key = "{$authorTeam}::{$authorName}";
                $carry[$key] = [
                    'author_id' => $roomUser->user_id,
                    'author_avatar' => $roomUser->user?->avatar,
                ];
                return $carry;
            }, []);

        /** @var \App\Models\RoomChat $chatMessage */
        foreach ($chatMessages as $chatMessage) {
            $authorId = $chatMessage->user_id ? (int) $chatMessage->user_id : null;
            $authorAvatar = $authorId ? ($authorAvatars[$authorId] ?? null) : null;
            if (!$authorId || !$authorAvatar) {
                $authorTeam = $chatMessage->author_team instanceof TeamEnum
                    ? $chatMessage->author_team->value
                    : (string) $chatMessage->author_team;
                $identity = $authorIdentityMap["{$authorTeam}::{$chatMessage->author}"] ?? null;
                if ($identity) {
                    $authorId = $authorId ?: ($identity['author_id'] ?? null);
                    $authorAvatar = $authorAvatar ?: ($identity['author_avatar'] ?? null);
                }
            }
            yield [
                'type' => 'chat',
                'data' => [
                    'id' => $chatMessage->uuid,
                    'author' => $chatMessage->author,
                    'author_id' => $authorId,
                    'author_team' => $chatMessage->author_team,
                    'author_avatar' => $authorAvatar,
                    'status' => $chatMessage->status,
                    'team' => $chatMessage->team,
                    'text' => $chatMessage->data,
                    'time' => $chatMessage->ingame_time->format('Y-m-d H:i:s'),
                    'created_at' => $chatMessage->created_at?->format('Y-m-d H:i:s'),
                    'delivered_at' => $chatMessage->delivered_at?->format('Y-m-d H:i:s'),
                    'delivered' => (bool) $chatMessage->delivered,
                    'unitIds' => $chatMessage->unitIds,
                ],
                'meta' => [
                    'ignore' => true,
                ]
            ];
        }
    }
}
