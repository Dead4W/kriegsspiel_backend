<?php

namespace App\Socket\Callbacks;

use App\Enums\TeamEnum;
use App\Models\Connection;
use App\Models\RoomMap;
use App\Models\RoomMapItem;
use App\Models\RoomUser;
use App\Services\RoomMapItemsService;
use App\Services\MetricsService;
use App\Services\RoomOptionsService;
use App\Services\RoomUnitLimitsService;
use App\Socket\Actions\GetOtherListenersAction;
use App\Socket\Actions\SocketErrorAction;
use Carbon\Carbon;
use OpenSwoole\WebSocket\Frame;
use OpenSwoole\WebSocket\Server;

class SocketOnMessageCallback extends AbstractSocketCallback
{
    private const MAP_SYNC_COMMANDS = [
        '/sync',
        '/sync-map',
        '/sync-maps',
        '/maps-sync',
    ];

    public function __invoke(Server $server, Frame $frame) {
        $start = microtime(true);
        $metrics = app(MetricsService::class);
        $metrics->incrementMessageCount();

        try {
            $this->run($server, $frame);
        } catch (\Throwable $t) {
            $metrics->incrementErrorCount();
            throw $t;
        } finally {
            $duration = microtime(true) - $start;
            $metrics->addMessageDuration($duration);
        }
    }

    protected function run(Server $server, Frame $frame) {
        /** @var Connection $currentConnection */
        $currentConnection = Connection::query()
            ->where('id', $frame->fd)
            ->first();

        if ($currentConnection === null) {
            $this->error("Connection not found!");
            SocketErrorAction::run($server, $frame->fd, "Connection closed");
            return;
        }

        $this->info("Received message from {$currentConnection->name}: {$frame->data}");
        $currentConnection->last_message_at = Carbon::now();
        $currentConnection->save();

        /** @var \App\Models\User $user */
        $user = $currentConnection->user;
        $user->last_online_at = Carbon::now();
        $user->save();

        $decodedFrameData = @json_decode($frame->data, true);

        if ($decodedFrameData === null) {
            $this->error("JSON data invalid");
            SocketErrorAction::run($server, $frame->fd, "JSON data invalid");
            return;
        }

        $goodMessages = [];
        $allMessages = [];
        $selfMessages = [];
        $messagesByTeam = [
            TeamEnum::RED->value => [],
            TeamEnum::BLUE->value => [],
            TeamEnum::ADMIN->value => [],
        ];
        $messagesByTeamUser = [
            TeamEnum::RED->value => [],
            TeamEnum::BLUE->value => [],
        ];
        $unitLimitsUsageChanged = false;
        $selfUnitRemovals = [];

        $room = \App\Models\Room::query()
            ->where('id', $currentConnection->room_id)
            ->firstOrFail();

        \Illuminate\Support\Facades\DB::transaction(function () use (
            $room,
            $currentConnection,
            $decodedFrameData,
            &$goodMessages,
            &$allMessages,
            &$selfMessages,
            &$messagesByTeam,
            &$messagesByTeamUser,
            &$unitLimitsUsageChanged,
            &$selfUnitRemovals,
        ) {
            if ($currentConnection->team === TeamEnum::SPECTATOR || $room->stage === 'end') {
                foreach ($decodedFrameData['messages'] as $message) {
                    if (in_array($message['type'], ['cursor'])) {
                        $goodMessages[] = $message;
                    }
                }
                // Ignore all messages exclude client
                return;
            }
            $roomMap = \App\Models\RoomMap::getRoomMapForConnection($currentConnection);

            if (!$roomMap) {
                throw new \Exception('RoomMap not found');
            }

            foreach ($decodedFrameData['messages'] as $message) {
                if ($message['type'] === 'unit') {
                    $itemData = $message['data'];
                    if (!is_array($itemData)) {
                        continue;
                    }
                    $existingUnit = RoomMapItem::query()
                        ->where('room_map_id', $roomMap->id)
                        ->where('type', RoomMapItemsService::TYPE_UNIT)
                        ->where('item_id', $itemData['id'])
                        ->first();
                    $isNewUnit = $existingUnit === null;
                    /** @var RoomOptionsService $roomOptionsService */
                    $roomOptionsService = app(RoomOptionsService::class);
                    $isInActiveZone = $roomOptionsService->isPointInsideActiveZone(
                        $room,
                        $itemData['pos'] ?? null
                    );
                    if (!$isInActiveZone) {
                        if ($isNewUnit) {
                            $selfUnitRemovals[] = (string) ($itemData['id'] ?? '');
                        } elseif ($existingUnit && is_array($existingUnit->data)) {
                            $selfMessages[] = [
                                'type' => 'unit',
                                'data' => $existingUnit->data,
                            ];
                        }
                        continue;
                    }

                    if ($room->stage === 'planning') {
                        $existingUnitTeam = is_array($existingUnit?->data)
                            ? ($existingUnit->data['team'] ?? null)
                            : null;
                        $unitTeam = $isNewUnit
                            ? (string) ($itemData['team'] ?? '')
                            : (string) ($existingUnitTeam ?: ($itemData['team'] ?? ''));
                        if (in_array($unitTeam, [TeamEnum::RED->value, TeamEnum::BLUE->value], true)) {
                            $isAllowedPosition = $roomOptionsService->isPlanningSpawnPointAllowed(
                                $room,
                                $unitTeam,
                                $itemData['pos'] ?? null
                            );
                            if (!$isAllowedPosition) {
                                if ($isNewUnit) {
                                    $selfUnitRemovals[] = (string) ($itemData['id'] ?? '');
                                } elseif ($existingUnit && is_array($existingUnit->data)) {
                                    $selfMessages[] = [
                                        'type' => 'unit',
                                        'data' => $existingUnit->data,
                                    ];
                                }
                                continue;
                            }
                        }
                    }

                    if ($isNewUnit) {
                        /** @var RoomUnitLimitsService $roomUnitLimitsService */
                        $roomUnitLimitsService = app(RoomUnitLimitsService::class);
                        if (!$roomUnitLimitsService->canSpawnUnit($room, $itemData)) {
                            $selfUnitRemovals[] = (string) ($itemData['id'] ?? '');
                            continue;
                        }
                    }

                    RoomMapItem::query()->updateOrCreate(
                        [
                            'room_map_id' => $roomMap->id,
                            'type' => RoomMapItemsService::TYPE_UNIT,
                            'item_id' => $itemData['id'],
                        ],
                        [
                            'data' => $itemData,
                        ]
                    );
                    if ($isNewUnit) {
                        $unitLimitsUsageChanged = true;
                    }
                } elseif ($message['type'] === 'unit-remove') {
                    if (!empty($message['data'])) {
                        \App\Models\RoomMapItem::query()
                            ->where('room_map_id', $roomMap->id)
                            ->where('type', RoomMapItemsService::TYPE_UNIT)
                            ->whereIn('item_id', $message['data'])
                            ->delete();
                        $unitLimitsUsageChanged = true;
                    }
                } elseif ($message['type'] === 'paint_add') {
                    $isSharedForPlayers = $currentConnection->team === TeamEnum::ADMIN
                        && isset($message['data']['sharedForPlayers'])
                        && $message['data']['sharedForPlayers'];
                    $paintData = $message['data'];
                    if (isset($paintData['moveFrames'])) {
                        unset($paintData['moveFrames']);
                    }
                    RoomMapItem::query()->updateOrCreate(
                        [
                            'room_map_id' => $roomMap->id,
                            'type' => RoomMapItemsService::TYPE_PAINT,
                            'item_id' => $paintData['id'],
                        ],
                        [
                            'data' => $paintData,
                            'shared' => $isSharedForPlayers,
                        ]
                    );

                    if ($isSharedForPlayers) {
                        unset($message['data']['sharedForPlayers']);
                        $allMessages[] = $message;
                        continue;
                    }
                } elseif ($message['type'] === 'paint_undo') {
                    $id = $message['data']['id'];
                    $roomMapIds = [$roomMap->id];
                    if ($currentConnection->team === TeamEnum::ADMIN) {
                        $otherMapIds = \App\Models\RoomMap::query()
                            ->where('room_id', $currentConnection->room_id)
                            ->where('team', '!=', TeamEnum::ADMIN)
                            ->pluck('id')
                            ->all();
                        $allMessages[] = $message;
                        $roomMapIds = array_merge($roomMapIds, $otherMapIds);
                    }

                    \App\Models\RoomMapItem::query()
                        ->whereIn('room_map_id', $roomMapIds)
                        ->where('type', RoomMapItemsService::TYPE_PAINT)
                        ->where('item_id', $id)
                        ->delete();
                } elseif ($message['type'] === 'chat') {
                    $chatAuthorId = $currentConnection->user_id ?: null;
                    $roomChat = new \App\Models\RoomChat();
                    $roomChat->uuid = $message['data']['id'];
                    $roomChat->user_id = $chatAuthorId;
                    $roomChat->author = $message['data']['author'];
                    $roomChat->author_team = $currentConnection->team;
                    $roomChat->unitIds = (array) $message['data']['unitIds'];
                    $roomChat->route_points = (array) ($message['data']['routePoints'] ?? []);
                    $roomChat->status = $message['data']['status'];
                    $roomChat->team = $message['data']['team'];
                    $roomChat->data = $message['data']['text'];
                    $roomChat->quoted_message_uuid = $message['data']['quotedMessageId'] ?? null;
                    $roomChat->messenger_id = $message['data']['messengerId'] ?? null;
                    $roomChat->delivery_status = $message['data']['deliveryStatus'] ?? null;
                    $roomChat->orders = is_array($message['data']['orders'] ?? null)
                        ? $message['data']['orders']
                        : null;
                    $roomChat->ingame_time = $room->ingame_time;
                    $roomChat->room_id = $currentConnection->room_id;
                    $roomChat->save();
                    $roomChat->roomMaps()->syncWithoutDetaching([$roomMap->id]);
                    $message['data']['author_id'] = $chatAuthorId;
                    $message['data']['author_team'] = $currentConnection->team;
                    $message['data']['author_avatar'] = $currentConnection->user?->avatar;
                    $message['data']['time'] = $room->ingame_time->format('Y-m-d H:i:s');
                    $message['data']['created_at'] = $roomChat->created_at?->format('Y-m-d H:i:s');
                    $message['data']['delivered_at'] = null;
                    $message['data']['quotedMessageId'] = $roomChat->quoted_message_uuid;
                    $message['data']['messengerId'] = $roomChat->messenger_id;
                    $message['data']['deliveryStatus'] = $roomChat->delivery_status;
                    $message['data']['routePoints'] = $roomChat->route_points ?? [];
                    $message['data']['orders'] = $roomChat->orders;
                    $message['data']['unitFallbackTitles'] = $this->buildChatUnitFallbackTitles(
                        $room->id,
                        (array) ($message['data']['unitIds'] ?? []),
                    );
                    if ($currentConnection->team === TeamEnum::ADMIN) {
                        $isMessengerReport = $roomChat->author === 'Messenger'
                            && in_array($roomChat->team, [TeamEnum::RED, TeamEnum::BLUE], true);
                        if ($isMessengerReport) {
                            $roomChat->delivered = true;
                            $roomChat->delivered_at = Carbon::now();
                            if (!$roomChat->delivery_status) {
                                $roomChat->delivery_status = 'delivered';
                            }
                            $roomChat->save();

                            $roomMapIds = [];
                            $isPlayerRoomMap = (bool) ($room->options['isPlayerRoomMap'] ?? false);
                            if ($isPlayerRoomMap) {
                                $adminRoomMapId = (int) RoomMap::query()
                                    ->where('room_id', $roomChat->room_id)
                                    ->where('team', TeamEnum::ADMIN)
                                    ->value('id');
                                $roomUserIds = [];
                                if ($adminRoomMapId > 0 && $roomChat->unitIds) {
                                    $roomUserIds = RoomMapItem::query()
                                        ->where('room_map_id', $adminRoomMapId)
                                        ->where('type', RoomMapItemsService::TYPE_UNIT)
                                        ->whereIn('item_id', $roomChat->unitIds)
                                        ->get(['data'])
                                        ->map(fn (RoomMapItem $item) => (int) ($item->data['roomMapUserId'] ?? 0))
                                        ->filter(fn (int $id) => $id > 0)
                                        ->unique()
                                        ->values()
                                        ->all();
                                }
                                if ($roomUserIds) {
                                    $roomMapIds = RoomMap::query()
                                        ->where('room_id', $roomChat->room_id)
                                        ->where('team', $roomChat->team)
                                        ->whereIn('user_id', $roomUserIds)
                                        ->pluck('id')
                                        ->all();
                                }
                            }
                            if (!$roomMapIds) {
                                $roomMapIds = RoomMap::query()
                                    ->where('room_id', $roomChat->room_id)
                                    ->where('team', $roomChat->team)
                                    ->pluck('id')
                                    ->all();
                            }
                            if ($roomMapIds) {
                                $roomChat->roomMaps()->syncWithoutDetaching($roomMapIds);
                            }

                            $message['data']['delivered'] = true;
                            $message['data']['delivered_at'] = $roomChat->delivered_at?->format('Y-m-d H:i:s');
                            $message['data']['deliveryStatus'] = $roomChat->delivery_status;

                            if ($isPlayerRoomMap && $roomMapIds) {
                                $chatRoomMaps = RoomMap::query()
                                    ->whereIn('id', $roomMapIds)
                                    ->get(['id', 'team', 'user_id']);
                                foreach ($chatRoomMaps as $chatRoomMap) {
                                    if ($chatRoomMap->user_id) {
                                        $messagesByTeamUser[$chatRoomMap->team->value][$chatRoomMap->user_id][] = $message;
                                    } else {
                                        $messagesByTeam[$chatRoomMap->team->value][] = $message;
                                    }
                                }
                            } else {
                                $messagesByTeam[$roomChat->team->value][] = $message;
                            }
                        }
                        $goodMessages[] = $message;
                    } else {
                        if (($room->options['isPlayerRoomMap'] ?? false) && $currentConnection->room_map_user_id) {
                            $messagesByTeamUser[$currentConnection->team->value][$currentConnection->room_map_user_id][] = $message;
                        } else {
                            $messagesByTeam[$currentConnection->team->value][] = $message;
                        }
                        $shouldSyncToUmpire = in_array($room->stage, ['planning', 'war'], true)
                            && in_array($currentConnection->team, [TeamEnum::BLUE, TeamEnum::RED], true);
                        if ($shouldSyncToUmpire) {
                            $adminRoomMapId = (int) RoomMap::query()
                                ->where('room_id', $roomChat->room_id)
                                ->where('team', TeamEnum::ADMIN)
                                ->value('id');

                            $messagesByTeam[TeamEnum::ADMIN->value][] = $message;
                            if ($adminRoomMapId > 0) {
                                $roomChat->roomMaps()->syncWithoutDetaching([$adminRoomMapId]);
                            }
                        }
                    }
                    continue;
                } elseif ($message['type'] === 'chat_edit') {
                    $messageId = (string) ($message['data']['id'] ?? '');
                    $messageText = (string) ($message['data']['text'] ?? '');
                    if ($messageId === '' || trim($messageText) === '') {
                        continue;
                    }

                    $roomChat = \App\Models\RoomChat::query()
                        ->where('room_id', $room->id)
                        ->where('uuid', $messageId)
                        ->first();
                    if (!$roomChat) {
                        $this->error("RoomChat not found for edit '{$messageId}'");
                        continue;
                    }

                    if ($roomChat->ingame_time->format('Y-m-d H:i:s') !== $room->ingame_time->format('Y-m-d H:i:s')) {
                        continue;
                    }

                    $canEditByUserId = $roomChat->user_id !== null
                        && (int) $roomChat->user_id === (int) $currentConnection->user_id;
                    $canEditByLegacyIdentity = $roomChat->author_team === $currentConnection->team
                        && $roomChat->author === $currentConnection->user?->name;
                    if (!$canEditByUserId && !$canEditByLegacyIdentity) {
                        continue;
                    }

                    $roomChat->data = $messageText;
                    $roomChat->save();

                    $chatMessage = [
                        'type' => 'chat',
                        'data' => [
                            'id' => $roomChat->uuid,
                            'author' => $roomChat->author,
                            'author_id' => $roomChat->user_id,
                            'author_team' => $roomChat->author_team,
                            'author_avatar' => $currentConnection->user?->avatar,
                            'unitIds' => $roomChat->unitIds,
                            'text' => $roomChat->data,
                            'time' => $roomChat->ingame_time->format('Y-m-d H:i:s'),
                            'created_at' => $roomChat->created_at?->format('Y-m-d H:i:s'),
                            'delivered_at' => $roomChat->delivered_at?->format('Y-m-d H:i:s'),
                            'quotedMessageId' => $roomChat->quoted_message_uuid,
                            'messengerId' => $roomChat->messenger_id,
                            'deliveryStatus' => $roomChat->delivery_status,
                            'routePoints' => $roomChat->route_points ?? [],
                            'orders' => $roomChat->orders,
                            'team' => $roomChat->team,
                            'status' => $roomChat->status,
                            'delivered' => (bool) $roomChat->delivered,
                            'unitFallbackTitles' => $this->buildChatUnitFallbackTitles(
                                $room->id,
                                (array) $roomChat->unitIds,
                            ),
                        ],
                    ];

                    $chatRoomMaps = $roomChat->roomMaps()->get(['room_maps.id', 'team', 'user_id']);
                    foreach ($chatRoomMaps as $chatRoomMap) {
                        if ($chatRoomMap->user_id) {
                            $messagesByTeamUser[$chatRoomMap->team->value][$chatRoomMap->user_id][] = $chatMessage;
                        } else {
                            $messagesByTeam[$chatRoomMap->team->value][] = $chatMessage;
                        }
                    }

                    continue;
                } elseif ($message['type'] === 'chat_orders_update') {
                    if ($currentConnection->team !== TeamEnum::ADMIN) {
                        continue;
                    }
                    $messageId = (string) ($message['data']['id'] ?? '');
                    if ($messageId === '') {
                        continue;
                    }
                    $roomChat = \App\Models\RoomChat::query()
                        ->where('room_id', $room->id)
                        ->where('uuid', $messageId)
                        ->first();
                    if (!$roomChat) {
                        continue;
                    }
                    $roomChat->orders = is_array($message['data']['orders'] ?? null)
                        ? $message['data']['orders']
                        : null;
                    $roomChat->save();

                    $chatMessage = [
                        'type' => 'chat',
                        'data' => [
                            'id' => $roomChat->uuid,
                            'author' => $roomChat->author,
                            'author_id' => $roomChat->user_id,
                            'author_team' => $roomChat->author_team,
                            'author_avatar' => $currentConnection->user?->avatar,
                            'unitIds' => $roomChat->unitIds,
                            'text' => $roomChat->data,
                            'time' => $roomChat->ingame_time->format('Y-m-d H:i:s'),
                            'created_at' => $roomChat->created_at?->format('Y-m-d H:i:s'),
                            'delivered_at' => $roomChat->delivered_at?->format('Y-m-d H:i:s'),
                            'quotedMessageId' => $roomChat->quoted_message_uuid,
                            'messengerId' => $roomChat->messenger_id,
                            'deliveryStatus' => $roomChat->delivery_status,
                            'routePoints' => $roomChat->route_points ?? [],
                            'orders' => $roomChat->orders,
                            'team' => $roomChat->team,
                            'status' => $roomChat->status,
                            'delivered' => (bool) $roomChat->delivered,
                            'unitFallbackTitles' => $this->buildChatUnitFallbackTitles(
                                $room->id,
                                (array) $roomChat->unitIds,
                            ),
                        ],
                    ];

                    $chatRoomMaps = $roomChat->roomMaps()->get(['room_maps.id', 'team', 'user_id']);
                    foreach ($chatRoomMaps as $chatRoomMap) {
                        if ($chatRoomMap->user_id) {
                            $messagesByTeamUser[$chatRoomMap->team->value][$chatRoomMap->user_id][] = $chatMessage;
                        } else {
                            $messagesByTeam[$chatRoomMap->team->value][] = $chatMessage;
                        }
                    }
                    $goodMessages[] = $chatMessage;
                    continue;
                } elseif ($message['type'] === 'cursor') {
                    $message['data']['team'] = $currentConnection->team->value;
                    $message['data']['name'] = $currentConnection->user->name;
                    if (in_array($currentConnection->team, [TeamEnum::BLUE, TeamEnum::RED])) {
                        // Send to admin/spectator
                        $messagesByTeam[TeamEnum::ADMIN->value][] = $message;
                    }
                } elseif ($message['type'] === 'room_user_ready') {
                    if (!in_array($currentConnection->team, [TeamEnum::BLUE, TeamEnum::RED], true)) {
                        continue;
                    }
                    if ($room->stage !== 'planning') {
                        continue;
                    }
                    if (!$currentConnection->room_map_user_id) {
                        continue;
                    }

                    $isReady = $message['data'];
                    if (is_array($isReady)) {
                        $isReady = $isReady['is_ready'] ?? false;
                    }
                    $isReady = (bool) $isReady;

                    RoomUser::query()->updateOrCreate(
                        [
                            'room_id' => $currentConnection->room_id,
                            'user_id' => $currentConnection->room_map_user_id,
                        ],
                        [
                            'team' => $currentConnection->team,
                            'is_ready' => $isReady,
                        ]
                    );

                    $readyMessage = [
                        'type' => 'room_user_ready',
                        'data' => [
                            'user_id' => $currentConnection->room_map_user_id,
                            'user' => $currentConnection->user?->name,
                            'team' => $currentConnection->team->value,
                            'is_ready' => $isReady,
                        ],
                    ];
                    $messagesByTeam[TeamEnum::ADMIN->value][] = $readyMessage;
                    $selfMessages[] = $readyMessage;
                    continue;
                } else if ($message['type'] === 'ruler') {
                    // pass backend
                } elseif ($currentConnection->team === TeamEnum::ADMIN) {
                    if ($message['type'] === 'skip_time') {
                        $oldIngameTime = $room->ingame_time->clone();
                        $room->ingame_time = Carbon::createFromFormat('Y-m-d H:i:s', $message['data']);

                        \App\Socket\Actions\SnapshotBoardAction::run(
                            $room,
                            $roomMap,
                        );

                        $allMessages[] = $message;
                        $selfMessages[] = [
                            'type' => 'skip_time_success',
                            'data' => true,
                        ];

                        // send previous messages
                        $chatMessages = \App\Models\RoomChat::query()
                            ->where('room_id', $room->id)
                            ->where('ingame_time', $oldIngameTime)
                            ->orderByRaw('COALESCE(delivered_at, created_at) asc')
                            ->orderBy('id', 'asc')
                            ->get();

                        $authorAvatars = \App\Models\User::query()
                            ->whereIn('id', function ($query) use ($room) {
                                $query->from('room_chats')
                                    ->select('user_id')
                                    ->where('room_id', $room->id)
                                    ->whereNotNull('user_id')
                                    ->distinct();
                            })
                            ->get(['id', 'avatar'])
                            ->reduce(function (array $carry, \App\Models\User $user) {
                                $carry[(int) $user->id] = $user->avatar;
                                return $carry;
                            }, []);
                        $authorIdentityMap = RoomUser::query()
                            ->where('room_id', $room->id)
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

                            $messageEvent = [
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
                                    'quotedMessageId' => $chatMessage->quoted_message_uuid,
                                    'messengerId' => $chatMessage->messenger_id,
                                    'deliveryStatus' => $chatMessage->delivery_status,
                                    'routePoints' => $chatMessage->route_points ?? [],
                                    'orders' => $chatMessage->orders,
                                    'unitIds' => $chatMessage->unitIds,
                                ]
                            ];
                            $messagesByTeam[TeamEnum::ADMIN->value][] = $messageEvent;
                            $selfMessages[] = $messageEvent;
                        }

                        continue;
                    } else if ($message['type'] === 'chat_read') {
                        if (!$message['data']) {
                            continue;
                        }

                        \App\Models\RoomChat::query()
                            ->where('room_id', $room->id)
                            ->whereIn('uuid', $message['data'])
                            ->update([
                                'status' => 'read',
                            ]);
                    } else if ($message['type'] === 'room_options_update') {
                        if ($room->stage !== 'planning') {
                            continue;
                        }
                        $patch = is_array($message['data'] ?? null) ? $message['data'] : [];
                        /** @var RoomOptionsService $roomOptionsService */
                        $roomOptionsService = app(RoomOptionsService::class);
                        $normalizedPatch = $roomOptionsService->normalizeAdminPatch($patch);
                        if (!$normalizedPatch) {
                            continue;
                        }
                        $room->options = array_merge((array) $room->options, $normalizedPatch);
                        $message['data'] = $normalizedPatch;
                        $allMessages[] = $message;
                        $unitLimitsUsageChanged = true;
                        continue;
                    } else if ($message['type'] === 'room_params_update') {
                        $patch = is_array($message['data'] ?? null) ? $message['data'] : [];
                        /** @var RoomOptionsService $roomOptionsService */
                        $roomOptionsService = app(RoomOptionsService::class);
                        $normalizedPatch = $roomOptionsService->normalizeRoomParamsPatch($patch);
                        if (!$normalizedPatch) {
                            continue;
                        }

                        $roomOptions = (array) $room->options;
                        $currentParams = is_array($roomOptions['params'] ?? null)
                            ? $roomOptions['params']
                            : [];
                        $roomOptions['params'] = array_merge($currentParams, $normalizedPatch);
                        $room->options = $roomOptions;
                        $message['data'] = $normalizedPatch;
                        $allMessages[] = $message;
                        continue;
                    } else if ($message['type'] === 'room_per_team_settings_update') {
                        if ($room->stage !== 'planning') {
                            continue;
                        }

                        $patch = is_array($message['data'] ?? null) ? $message['data'] : [];
                        /** @var RoomOptionsService $roomOptionsService */
                        $roomOptionsService = app(RoomOptionsService::class);
                        $normalizedPatch = $roomOptionsService->normalizePerTeamSettingsPatch($patch);
                        if (!$normalizedPatch) {
                            continue;
                        }

                        $currentPerTeamSettings = is_array($room->options['perTeamSettings'] ?? null)
                            ? $room->options['perTeamSettings']
                            : [];

                        foreach ($normalizedPatch as $team => $teamSettings) {
                            $currentTeamSettings = is_array($currentPerTeamSettings[$team] ?? null)
                                ? $currentPerTeamSettings[$team]
                                : [];
                            $currentPerTeamSettings[$team] = array_merge($currentTeamSettings, $teamSettings);
                        }

                        $roomOptions = (array) $room->options;
                        $roomOptions['perTeamSettings'] = $currentPerTeamSettings;
                        $room->options = $roomOptions;
                        $message['data'] = $normalizedPatch;
                        $allMessages[] = $message;
                        continue;
                    } else if ($message['type'] === 'set_stage') {
                        if ($room->stage !== $message['data']) {
                            if ($room->stage === 'planning' && $message['data'] === 'war') {
                                $room->stage = $message['data'];
                            } else if ($room->stage === 'war' && $message['data'] === 'end') {
                                $room->stage = $message['data'];
                            } else {
                                $this->error("Invalid stage value '{$message['data']}'");
                                // bad stage
                                continue;
                            }

                            if ($message['data'] === 'war') {
                                \App\Socket\Actions\CopyBoardAction::run(
                                    $roomMap,
                                    TeamEnum::BLUE,
                                    $selfMessages
                                );
                                \App\Socket\Actions\CopyBoardAction::run(
                                    $roomMap,
                                    TeamEnum::RED,
                                    $selfMessages
                                );

                                \App\Socket\Actions\SnapshotBoardAction::run(
                                    $room,
                                    $roomMap,
                                );
                            }

                            $allMessages[] = $message;
                            continue;
                        }
                    } else if ($message['type'] === 'messenger_delivery_update') {
                        $roomChat = \App\Models\RoomChat::query()
                            ->where('room_id', $room->id)
                            ->where('uuid', $message['data']['id'])
                            ->first();

                        if (!$roomChat) {
                            $this->error("RoomChat not found '{$message['data']['id']}'");
                            continue;
                        }

                        $roomChat->messenger_id = $message['data']['messengerId'] ?? $roomChat->messenger_id;
                        $roomChat->quoted_message_uuid = $message['data']['quotedMessageId'] ?? $roomChat->quoted_message_uuid;
                        $roomChat->delivery_status = $message['data']['deliveryStatus'] ?? $roomChat->delivery_status;
                        $roomChat->save();

                        $authorId = $roomChat->user_id ? (int) $roomChat->user_id : null;
                        if (!$authorId) {
                            $authorId = RoomUser::query()
                                ->where('room_id', $roomChat->room_id)
                                ->where('team', $roomChat->author_team)
                                ->whereHas('user', function ($query) use ($roomChat) {
                                    $query->where('name', $roomChat->author);
                                })
                                ->value('user_id');
                            if ($authorId) {
                                $roomChat->user_id = $authorId;
                                $roomChat->save();
                            }
                        }

                        $chatMessage = [
                            'type' => 'chat',
                            'data' => [
                                'id' => $roomChat->uuid,
                                'author' => $roomChat->author,
                                'author_id' => $authorId,
                                'author_team' => $roomChat->author_team,
                                'author_avatar' => $authorId
                                    ? \App\Models\User::query()->where('id', $authorId)->value('avatar')
                                    : null,
                                'unitIds' => $roomChat->unitIds,
                                'text' => $roomChat->data,
                                'time' => $roomChat->ingame_time->format('Y-m-d H:i:s'),
                                'created_at' => $roomChat->created_at?->format('Y-m-d H:i:s'),
                                'delivered_at' => $roomChat->delivered_at?->format('Y-m-d H:i:s'),
                                'quotedMessageId' => $roomChat->quoted_message_uuid,
                                'messengerId' => $roomChat->messenger_id,
                                'deliveryStatus' => $roomChat->delivery_status,
                                'routePoints' => $roomChat->route_points ?? [],
                                'orders' => $roomChat->orders,
                                'team' => $roomChat->team,
                                'status' => $roomChat->status,
                                'delivered' => (bool) $roomChat->delivered,
                                'unitFallbackTitles' => $this->buildChatUnitFallbackTitles(
                                    $room->id,
                                    (array) $roomChat->unitIds,
                                ),
                            ],
                        ];

                        $chatRoomMaps = $roomChat->roomMaps()->get(['room_maps.id', 'team', 'user_id']);
                        foreach ($chatRoomMaps as $chatRoomMap) {
                            if ($chatRoomMap->user_id) {
                                $messagesByTeamUser[$chatRoomMap->team->value][$chatRoomMap->user_id][] = $chatMessage;
                            } else {
                                $messagesByTeam[$chatRoomMap->team->value][] = $chatMessage;
                            }
                        }

                        continue;
                    } else if ($message['type'] === 'messenger_delivery') {
                        $roomChat = \App\Models\RoomChat::query()
                            ->where('room_id', $room->id)
                            ->where('uuid', $message['data']['id'])
                            ->first();

                        if (!$roomChat) {
                            $this->error("RoomChat not found '{$message['data']['id']}'");
                            continue;
                        }

                        $messageCreated = $roomChat->ingame_time->clone();

                        $roomChat->ingame_time = $message['data']['time'];
                        $roomChat->delivered = true;
                        $roomChat->messenger_id = $message['data']['messengerId'] ?? $roomChat->messenger_id;
                        $roomChat->quoted_message_uuid = $message['data']['quotedMessageId'] ?? $roomChat->quoted_message_uuid;
                        $roomChat->delivery_status = $message['data']['deliveryStatus'] ?? 'delivered';
                        $roomChat->delivered_at = Carbon::now();
                        $roomChat->save();

                        $roomMapIds = [];
                        if ($room->options['isPlayerRoomMap'] ?? false) {
                            $roomUserIds = array_filter((array) ($message['data']['roomUserIds'] ?? []), fn ($id) => $id !== null && $id !== '' && $id > 0);
                            $roomUserIds = array_unique($roomUserIds);
                            if ($roomUserIds) {
                                $roomMapIds = \App\Models\RoomMap::query()
                                    ->where('room_id', $roomChat->room_id)
                                    ->where('team', $roomChat->team)
                                    ->whereIn('user_id', $roomUserIds)
                                    ->pluck('id')
                                    ->all();
                            }
                        } else {
                            $roomMapIds = \App\Models\RoomMap::query()
                                ->where('room_id', $roomChat->room_id)
                                ->where('team', $roomChat->team)
                                ->pluck('id')
                                ->all();
                        }
                        if ($roomMapIds) {
                            $roomChat->roomMaps()->syncWithoutDetaching($roomMapIds);
                        }

                        $isPlayerRoomMap = (bool) ($room->options['isPlayerRoomMap'] ?? false);
                        if ($isPlayerRoomMap && $roomMapIds && $this->isMapSyncCommand($roomChat)) {
                            $adminRoomMapId = (int) RoomMap::query()
                                ->where('room_id', $roomChat->room_id)
                                ->where('team', TeamEnum::ADMIN)
                                ->value('id');

                            if ($adminRoomMapId > 0) {
                                $targetRoomMaps = RoomMap::query()
                                    ->whereIn('id', $roomMapIds)
                                    ->get(['id', 'user_id', 'team']);

                                foreach ($targetRoomMaps as $targetRoomMap) {
                                    $targetRoomUserId = (int) ($targetRoomMap->user_id ?? 0);
                                    if ($targetRoomUserId <= 0) {
                                        continue;
                                    }

                                    $this->syncUnitsBetweenRoomMaps(
                                        $adminRoomMapId,
                                        (int) $targetRoomMap->id,
                                        $targetRoomUserId
                                    );

                                    $targetUnits = RoomMapItem::query()
                                        ->where('room_map_id', (int) $targetRoomMap->id)
                                        ->where('type', RoomMapItemsService::TYPE_UNIT)
                                        ->get()
                                        ->map(fn (RoomMapItem $item) => $item->data)
                                        ->filter(fn ($data) => is_array($data))
                                        ->values()
                                        ->all();

                                    foreach ($targetUnits as $targetUnit) {
                                        $messagesByTeamUser[$targetRoomMap->team->value][$targetRoomUserId][] = [
                                            'type' => 'unit',
                                            'data' => $targetUnit,
                                        ];
                                    }
                                }
                            }
                        }

                        $authorId = $roomChat->user_id ? (int) $roomChat->user_id : null;
                        if (!$authorId) {
                            $authorId = RoomUser::query()
                                ->where('room_id', $roomChat->room_id)
                                ->where('team', $roomChat->author_team)
                                ->whereHas('user', function ($query) use ($roomChat) {
                                    $query->where('name', $roomChat->author);
                                })
                                ->value('user_id');
                            if ($authorId) {
                                $roomChat->user_id = $authorId;
                                $roomChat->save();
                            }
                        }

                        $chatMessage = [
                            'type' => 'chat',
                            'data' => [
                                'id' => $roomChat->uuid,
                                'author' => $roomChat->author,
                                'author_id' => $authorId,
                                'author_team' => $roomChat->author_team,
                                'author_avatar' => $authorId
                                    ? \App\Models\User::query()->where('id', $authorId)->value('avatar')
                                    : null,
                                'unitIds' => $roomChat->unitIds,
                                'text' => $roomChat->data,
                                'time' => $roomChat->ingame_time->format('Y-m-d H:i:s'),
                                'created_at' => $roomChat->created_at?->format('Y-m-d H:i:s'),
                                'delivered_at' => $roomChat->delivered_at?->format('Y-m-d H:i:s'),
                                'quotedMessageId' => $roomChat->quoted_message_uuid,
                                'messengerId' => $roomChat->messenger_id,
                                'deliveryStatus' => $roomChat->delivery_status,
                                'routePoints' => $roomChat->route_points ?? [],
                                'orders' => $roomChat->orders,
                                'team' => $roomChat->team,
                                'status' => $roomChat->status,
                                'delivered' => true,
                                'unitFallbackTitles' => $this->buildChatUnitFallbackTitles(
                                    $room->id,
                                    (array) $roomChat->unitIds,
                                ),
                            ],
                        ];
                        if (($room->options['isPlayerRoomMap'] ?? false)) {
                            if ($roomMapIds) {
                                $chatRoomMaps = \App\Models\RoomMap::query()
                                    ->whereIn('id', $roomMapIds)
                                    ->get(['id', 'team', 'user_id']);
                                foreach ($chatRoomMaps as $chatRoomMap) {
                                    if ($chatRoomMap->user_id) {
                                        $messagesByTeamUser[$chatRoomMap->team->value][$chatRoomMap->user_id][] = $chatMessage;
                                    } else {
                                        $messagesByTeam[$chatRoomMap->team->value][] = $chatMessage;
                                    }
                                }
                            }
                        } else {
                            $messagesByTeam[$roomChat->team->value][] = $chatMessage;
                        }

                        // Update stats
                        if (isset($room->options['autoStatsUpdate']) && $room->options['autoStatsUpdate']) {
                            $snapshotRoomMapAdmin = \App\Models\Snapshot::query()
                                ->where('room_map_id', $roomMap->id)
                                ->where('ingame_time', $messageCreated)
                                ->first();
                            if (!$snapshotRoomMapAdmin) {
                                $this->error("Not found snapshot roomMap for team '$messageCreated");
                                continue;
                            }
                            $snapshotRoomMapAdminUnits = $snapshotRoomMapAdmin->units;

                            if ($roomChat->unitIds) {
                                $isPlayerRoomMap = (bool) ($room->options['isPlayerRoomMap'] ?? false);
                                $roomMapsTeamQuery = \App\Models\RoomMap::query()
                                    ->where('room_id', $currentConnection->room_id)
                                    ->where('team', $roomChat->team);
                                if ($isPlayerRoomMap) {
                                    if (!$roomMapIds) {
                                        continue;
                                    }
                                    $roomMapsTeamQuery->whereIn('id', $roomMapIds);
                                }
                                $roomMapsTeam = $roomMapsTeamQuery->get();
                                foreach ($roomMapsTeam as $roomMapTeam) {
                                    $roomMapTeamUnits = \App\Models\RoomMapItem::query()
                                        ->where('room_map_id', $roomMapTeam->id)
                                        ->where('type', RoomMapItemsService::TYPE_UNIT)
                                        ->whereIn('item_id', $roomChat->unitIds)
                                        ->get()
                                        ->mapWithKeys(fn (RoomMapItem $item) => [
                                            $item->item_id => $item->data ?? [],
                                        ])
                                        ->all();
                                    foreach ($roomChat->unitIds as $unitId) {
                                        if (!isset($snapshotRoomMapAdminUnits[$unitId])) continue;
                                        if (!isset($roomMapTeamUnits[$unitId])) continue;
                                        $roomMapTeamUnits[$unitId]['hp'] = $snapshotRoomMapAdminUnits[$unitId]['hp'];
                                        $roomMapTeamUnits[$unitId]['ammo'] = $snapshotRoomMapAdminUnits[$unitId]['ammo'];
                                        $roomMapTeamUnits[$unitId]['pos'] = $snapshotRoomMapAdminUnits[$unitId]['pos'];
                                        $team = $snapshotRoomMapAdminUnits[$unitId]['team'];
                                        if ($roomMapTeam->user_id) {
                                            $messagesByTeamUser[$team][$roomMapTeam->user_id][] = [
                                                'type' => 'unit',
                                                'data' => $roomMapTeamUnits[$unitId],
                                            ];
                                        } else {
                                            $messagesByTeam[$team][] = [
                                                'type' => 'unit',
                                                'data' => $roomMapTeamUnits[$unitId],
                                            ];
                                        }
                                        RoomMapItem::query()->updateOrCreate(
                                            [
                                                'room_map_id' => $roomMapTeam->id,
                                                'type' => RoomMapItemsService::TYPE_UNIT,
                                                'item_id' => (string) $unitId,
                                            ],
                                            [
                                                'data' => $roomMapTeamUnits[$unitId],
                                            ]
                                        );
                                    }
                                }
                            }
                        }
                    } else if ($message['type'] === 'direct_view') {
                        $roomMapsTeam = \App\Models\RoomMap::query()
                            ->where('room_id', $currentConnection->room_id)
                            ->where('team', $message['team'])
                            ->get();

                        foreach ($roomMapsTeam as $roomMapTeam) {
                            \App\Models\RoomMapItem::query()
                                ->where('room_map_id', $roomMapTeam->id)
                                ->where('type', RoomMapItemsService::TYPE_UNIT)
                                ->where('data->directView', true)
                                ->where(function ($query) use ($message) {
                                    $query->where('data->team', '!=', $message['team']);
                                })
                                ->delete();

                            $directViewUuids = array_values(array_filter(array_map(
                                fn ($packet) => is_array($packet) && isset($packet['unit']['id'])
                                    ? (string) $packet['unit']['id']
                                    : null,
                                (array) ($message['data'] ?? [])
                            )));
                            $roomMapTeamUnits = \App\Models\RoomMapItem::query()
                                ->where('room_map_id', $roomMapTeam->id)
                                ->where('type', RoomMapItemsService::TYPE_UNIT)
                                ->where(function ($query) use ($directViewUuids) {
                                    $query
                                        ->whereIn('item_id', $directViewUuids)
                                        ->orWhere('data->directView', true);
                                })
                                ->get()
                                ->pluck('data', 'item_id')
                                ->toArray();

                            $wasDirectViewByUnitId = [];
                            foreach ($roomMapTeamUnits as &$roomMapTeamUnit) {
                                $unitId = (string) ($roomMapTeamUnit['id'] ?? '');
                                if ($unitId !== '') {
                                    $wasDirectViewByUnitId[$unitId] = (bool) ($roomMapTeamUnit['directView'] ?? false);
                                }
                                $roomMapTeamUnit['directView'] = false;
                            }
                            unset($roomMapTeamUnit);
                            $isPlayerRoomMap = (bool) ($room->options['isPlayerRoomMap'] ?? false);
                            $roomMapMessageDatas = [];
                            foreach ((array) ($message['data'] ?? []) as $packet) {
                                if (!is_array($packet) || !isset($packet['unit']) || !is_array($packet['unit'])) {
                                    continue;
                                }
                                $messageData = $packet['unit'];
                                if (!isset($messageData['id'])) {
                                    continue;
                                }
                                $messageFrames = isset($packet['frames']) && is_array($packet['frames'])
                                    ? $packet['frames']
                                    : null;
                                if ($isPlayerRoomMap && $roomMapTeam->user_id) {
                                    $seenRoomUserIds = array_filter(
                                        (array) ($messageData['seenRoomUserIds'] ?? []),
                                        fn ($id) => $id !== null && $id !== ''
                                    );
                                    $seenRoomUserIds = array_map('intval', $seenRoomUserIds);
                                    if (!$seenRoomUserIds || !in_array((int) $roomMapTeam->user_id, $seenRoomUserIds, true)) {
                                        continue;
                                    }
                                }
                                unset($messageData['seenRoomUserIds']);

                                if (isset($roomMapTeamUnits[$messageData['id']])) {
                                    foreach ($messageData as $unitKey => $unitValue) {
                                        $roomMapTeamUnits[$messageData['id']][$unitKey] = $unitValue;
                                    }
                                } else {
                                    $roomMapTeamUnits[$messageData['id']] = $messageData;
                                }
                                $roomMapTeamUnits[$messageData['id']]['directView'] = true;

                                $packetData = [
                                    'unit' => $messageData,
                                ];
                                if ($messageFrames !== null) {
                                    $packetData['frames'] = $messageFrames;
                                }
                                $roomMapMessageDatas[] = $packetData;
                            }

                            $hiddenUnitUpdates = [];
                            foreach ($roomMapTeamUnits as $unitId => &$roomMapTeamUnit) {
                                if ($roomMapTeamUnit['type'] === 'messenger') continue;
                                $wasDirect = (bool) ($wasDirectViewByUnitId[(string) $unitId] ?? false);
                                $isDirect = (bool) ($roomMapTeamUnit['directView'] ?? false);
                                if (!$wasDirect || $isDirect) {
                                    continue;
                                }

                                $positionChanged = $this->applyLastMoveTargetForHiddenDirectViewUnit($roomMapTeamUnit);
                                $hadCommands = is_array($roomMapTeamUnit['commands'] ?? null) && count($roomMapTeamUnit['commands']) > 0;
                                $roomMapTeamUnit['commands'] = [];

                                if (!$positionChanged && !$hadCommands) {
                                    continue;
                                }

                                $hiddenUnitUpdates[] = [
                                    'type' => 'unit',
                                    'data' => [
                                        'id' => $roomMapTeamUnit['id'] ?? $unitId,
                                        'type' => $roomMapTeamUnit['type'] ?? null,
                                        'team' => $roomMapTeamUnit['team'] ?? null,
                                        'pos' => $roomMapTeamUnit['pos'] ?? null,
                                        'angle' => $roomMapTeamUnit['angle'] ?? null,
                                        'commands' => [],
                                    ],
                                ];
                            }
                            unset($roomMapTeamUnit);

                            $roomMapMessage = $message;
                            $roomMapMessage['data'] = $roomMapMessageDatas;
                            if ($roomMapTeam->user_id) {
                                $messagesByTeamUser[$message['team']][$roomMapTeam->user_id][] = $roomMapMessage;
                                foreach ($hiddenUnitUpdates as $unitUpdate) {
                                    $messagesByTeamUser[$message['team']][$roomMapTeam->user_id][] = $unitUpdate;
                                }
                            } else {
                                $messagesByTeam[$message['team']][] = $roomMapMessage;
                                foreach ($hiddenUnitUpdates as $unitUpdate) {
                                    $messagesByTeam[$message['team']][] = $unitUpdate;
                                }
                            }
                            foreach ($roomMapTeamUnits as $roomMapTeamUnit) {
                                RoomMapItem::query()->updateOrCreate(
                                    [
                                        'room_map_id' => $roomMapTeam->id,
                                        'type' => RoomMapItemsService::TYPE_UNIT,
                                        'item_id' => $roomMapTeamUnit['id'],
                                    ],
                                    [
                                        'data' => $roomMapTeamUnit,
                                    ]
                                );
                            }
                        }
                        continue;
                    } else if ($message['type'] === 'direct_view_objects') {
                        $roomMapsTeam = \App\Models\RoomMap::query()
                            ->where('room_id', $currentConnection->room_id)
                            ->where('team', $message['team'])
                            ->get();

                        $isPlayerRoomMap = (bool) ($room->options['isPlayerRoomMap'] ?? false);
                        foreach ($roomMapsTeam as $roomMapTeam) {
                            $roomMapMessageDatas = [];
                            foreach ((array) ($message['data'] ?? []) as $messageData) {
                                if ($isPlayerRoomMap && $roomMapTeam->user_id) {
                                    $rawSeenRoomUserIds = (array) ($messageData['seenRoomUserIds'] ?? []);
                                    $seenRoomUserIds = array_filter(
                                        $rawSeenRoomUserIds,
                                        fn ($id) => $id !== null && $id !== ''
                                    );
                                    $seenRoomUserIds = array_map('intval', $seenRoomUserIds);

                                    // For direct-view objects an empty/missing seen list means "no per-user restriction".
                                    if (
                                        $seenRoomUserIds
                                        && !in_array((int) $roomMapTeam->user_id, $seenRoomUserIds, true)
                                    ) {
                                        continue;
                                    }
                                }
                                unset($messageData['seenRoomUserIds']);
                                $roomMapMessageDatas[] = $messageData;
                            }

                            $roomMapMessage = $message;
                            $roomMapMessage['data'] = $roomMapMessageDatas;
                            if ($roomMapTeam->user_id) {
                                $messagesByTeamUser[$message['team']][$roomMapTeam->user_id][] = $roomMapMessage;
                            } else {
                                $messagesByTeam[$message['team']][] = $roomMapMessage;
                            }
                        }
                        continue;
                    } else if ($message['type'] === 'weather') {
                        $room->weather = $message['data'];
                        $allMessages[] = $message;
                    } else if ($message['type'] === 'log') {
//                        $logId = $message['data']['id'];
                        $message['data']['is_new'] = false;
//                        $roomMapLogs[$logId] = $message['data'];
                    } else {
                        $this->error("Invalid message type '{$message['type']}' for team '{$currentConnection->team}'");
                        continue;
                    }
                } else if ($message['type'] === 'direct_view_send_order') {
                    if ($room->stage !== 'war') {
                        continue;
                    }
                    if (!in_array($currentConnection->team, [TeamEnum::BLUE, TeamEnum::RED], true)) {
                        continue;
                    }

                    $payload = is_array($message['data'] ?? null) ? $message['data'] : [];
                    $unitId = (string) (
                        $payload['unitId']
                        ?? $payload['id']
                        ?? (is_array($payload['unit'] ?? null) ? ($payload['unit']['id'] ?? '') : '')
                    );
                    if ($unitId === '') {
                        continue;
                    }

                    $umpireRoomMapId = (int) RoomMap::query()
                        ->where('room_id', $room->id)
                        ->where('team', TeamEnum::ADMIN)
                        ->value('id');
                    if ($umpireRoomMapId <= 0) {
                        continue;
                    }

                    /** @var RoomMapItem|null $umpireUnit */
                    $umpireUnit = RoomMapItem::query()
                        ->where('room_map_id', $umpireRoomMapId)
                        ->where('type', RoomMapItemsService::TYPE_UNIT)
                        ->where('item_id', $unitId)
                        ->first();
                    if (!$umpireUnit || !is_array($umpireUnit->data)) {
                        continue;
                    }

                    $unitData = $umpireUnit->data;
                    if ((string) ($unitData['team'] ?? '') !== $currentConnection->team->value) {
                        continue;
                    }
                    if ($unitData['type'] === 'messenger') {
                        continue;
                    }
                    if (!($unitData['directView'] ?? false)) {
                        continue;
                    }
                    if (($unitData['isRetreatState'] ?? false)) {
                        continue;
                    }

                    $rawCommands = $payload['commands']
                        ?? (is_array($payload['unit'] ?? null) ? ($payload['unit']['commands'] ?? []) : []);
                    $rawCommands = is_array($rawCommands) ? $rawCommands : [];
                    $directViewManagedTypes = ['move', 'attack'];
                    $directViewCommands = array_values(array_filter(
                        $rawCommands,
                        fn ($command) => is_array($command)
                            && in_array(($command['type'] ?? null), $directViewManagedTypes, true)
                            && is_array($command['state'] ?? null)
                    ));

                    $existingCommands = is_array($unitData['commands'] ?? null) ? $unitData['commands'] : [];
                    $incomingCommandTypes = array_values(array_unique(array_map(
                        fn ($command) => (string) ($command['type'] ?? ''),
                        $directViewCommands
                    )));
                    $preservedCommands = array_values(array_filter(
                        $existingCommands,
                        function ($command) use ($incomingCommandTypes, $directViewManagedTypes) {
                            if (!is_array($command)) {
                                return true;
                            }
                            $commandType = (string) ($command['type'] ?? '');
                            if (!$incomingCommandTypes) {
                                return !in_array($commandType, $directViewManagedTypes, true);
                            }
                            return !in_array($commandType, $incomingCommandTypes, true);
                        }
                    ));
                    $normalizedDirectViewCommands = array_map(function ($command) {
                        $type = (string) ($command['type'] ?? '');
                        $status = (string) ($command['status'] ?? 'pending');
                        if (!in_array($status, ['pending', 'running'], true)) {
                            $status = 'pending';
                        }

                        if ($type === 'move') {
                            return [
                                'type' => 'move',
                                'status' => $status,
                                'state' => $command['state'],
                            ];
                        }

                        $state = is_array($command['state'] ?? null) ? $command['state'] : [];
                        $targets = array_values(array_filter(
                            array_map('strval', is_array($state['targets'] ?? null) ? $state['targets'] : []),
                            fn ($targetId) => $targetId !== ''
                        ));
                        $abilities = array_values(array_filter(
                            array_map('strval', is_array($state['abilities'] ?? null) ? $state['abilities'] : []),
                            fn ($ability) => $ability !== ''
                        ));
                        $inaccuracyPoint = null;
                        if (
                            is_array($state['inaccuracyPoint'] ?? null)
                            && isset($state['inaccuracyPoint']['x'], $state['inaccuracyPoint']['y'])
                            && is_numeric($state['inaccuracyPoint']['x'])
                            && is_numeric($state['inaccuracyPoint']['y'])
                        ) {
                            $inaccuracyPoint = [
                                'x' => (float) $state['inaccuracyPoint']['x'],
                                'y' => (float) $state['inaccuracyPoint']['y'],
                            ];
                        }

                        return [
                            'type' => 'attack',
                            'status' => $status,
                            'state' => [
                                'targets' => $targets,
                                'damageModifier' => 1.0,
                                'radiusModifier' => 1.0,
                                'abilities' => $abilities,
                                'inaccuracyPoint' => $inaccuracyPoint,
                            ],
                        ];
                    }, $directViewCommands);

                    $unitData['commands'] = array_values(array_merge($preservedCommands, $normalizedDirectViewCommands));
                    $unitData['manualEnvironment'] = null;
                    $umpireUnit->data = $unitData;
                    $umpireUnit->save();

                    $directViewOrderPacket = [
                        'type' => 'direct_view_send_order',
                        'data' => [
                            'unitId' => (string) ($unitData['id'] ?? $unitId),
                            'commands' => $unitData['commands'] ?? [],
                        ],
                    ];
                    $messagesByTeam[TeamEnum::ADMIN->value][] = $directViewOrderPacket;
                    if (($room->options['isPlayerRoomMap'] ?? false) && $currentConnection->room_map_user_id) {
                        $messagesByTeamUser[$currentConnection->team->value][$currentConnection->room_map_user_id][] = $directViewOrderPacket;
                    } else {
                        $messagesByTeam[$currentConnection->team->value][] = $directViewOrderPacket;
                    }

                    continue;
                } else if (in_array($message['type'], ['chat_read'])) {
                    // Ignore messages (No relay)
                    continue;
                } else {
                    $this->error("Invalid message type '{$message['type']}' for team '{$currentConnection->team}'");
                    continue;
                }

                $goodMessages[] = $message;
            }

            $room->save();
            $roomMap->save();
        });

        $selfUnitRemovals = array_values(array_filter(array_unique($selfUnitRemovals)));
        if ($selfUnitRemovals) {
            $selfMessages[] = [
                'type' => 'unit-remove',
                'data' => $selfUnitRemovals,
            ];
        }

        if ($selfMessages) {
            $server->push($currentConnection->id,  json_encode([
                'type' => 'messages',
                'messages' => $this->sanitizeMessagesForTeam($selfMessages, $currentConnection->team),
            ]));
        }

        $data = [
            'type' => 'messages',
            'messages' => array_merge($goodMessages, $selfMessages),
        ];
        if ($data['messages']) {
            if ($room->options['isPlayerRoomMap'] ?? false && in_array($currentConnection->team, [TeamEnum::BLUE, TeamEnum::RED])) {
                $connectionIds = Connection::query()
                    ->where('id', '!=', $currentConnection->id)
                    ->where('room_id', $currentConnection->room_id)
                    ->where('room_map_user_id', $currentConnection->room_map_user_id)
                    ->where('team', $currentConnection->team)
                    ->pluck('id');
            } else {
                $connectionIds = GetOtherListenersAction::run($currentConnection);
            }
            $connections = Connection::query()
                ->whereIn('id', collect($connectionIds)->all())
                ->get(['id', 'team']);
            foreach ($connections as $connection) {
                $server->push((int) $connection->id,  json_encode([
                    'type' => 'messages',
                    'messages' => $this->sanitizeMessagesForTeam(
                        $data['messages'],
                        $connection->team
                    ),
                ]));
            }
        }

        if ($allMessages) {
            $data = [
                'type' => 'messages',
                'messages' => $allMessages,
            ];
            $connectionIds = GetOtherListenersAction::run($currentConnection, [
                TeamEnum::SPECTATOR,
                TeamEnum::ADMIN,
                TeamEnum::BLUE,
                TeamEnum::RED,
            ]);
            $connections = Connection::query()
                ->whereIn('id', collect($connectionIds)->all())
                ->get(['id', 'team']);
            foreach ($connections as $connection) {
                $server->push((int) $connection->id,  json_encode([
                    'type' => 'messages',
                    'messages' => $this->sanitizeMessagesForTeam(
                        $data['messages'],
                        $connection->team
                    ),
                ]));
            }
        }

        $messagesByTeam[TeamEnum::SPECTATOR->value] = $messagesByTeam[TeamEnum::ADMIN->value] ?? [];
        foreach ($messagesByTeam as $team => $messages) {
            if (!$messages) continue;
            $connectionIds = GetOtherListenersAction::run($currentConnection, [$team]);
            foreach ($connectionIds as $connectionId) {
                $server->push($connectionId, json_encode([
                    'type' => 'messages',
                    'messages' => $this->sanitizeMessagesForTeam($messages, $team),
                ]));
            }
        }

        foreach ($messagesByTeamUser as $team => $userMessages) {
            foreach ($userMessages as $userId => $messages) {
                if (!$messages) continue;
                $connectionIds = Connection::query()
                    ->where('id', '!=', $currentConnection->id)
                    ->where('room_id', $currentConnection->room_id)
                    ->where('room_map_user_id', $userId)
                    ->where('team', $team)
                    ->pluck('id');
                foreach ($connectionIds as $connectionId) {
                    $server->push($connectionId, json_encode([
                        'type' => 'messages',
                        'messages' => $this->sanitizeMessagesForTeam($messages, $team),
                    ]));
                }
            }
        }

        if ($unitLimitsUsageChanged) {
            /** @var RoomUnitLimitsService $roomUnitLimitsService */
            $roomUnitLimitsService = app(RoomUnitLimitsService::class);
            $usagePayload = $roomUnitLimitsService->buildUsagePayload($room);

            $roomConnections = Connection::query()
                ->where('room_id', $currentConnection->room_id)
                ->pluck('id');

            foreach ($roomConnections as $connectionId) {
                $server->push((int) $connectionId, json_encode([
                    'type' => 'messages',
                    'messages' => [
                        [
                            'type' => 'unit_limits_usage',
                            'data' => $usagePayload,
                        ],
                    ],
                ]));
            }
        }
    }

    private function sanitizeMessagesForTeam(array $messages, TeamEnum|string|null $team): array
    {
        $teamValue = $team instanceof TeamEnum ? $team->value : (string) $team;
        /** @var RoomOptionsService $roomOptionsService */
        $roomOptionsService = app(RoomOptionsService::class);

        foreach ($messages as &$message) {
            $type = $message['type'] ?? null;

            if ($type === 'chat') {
                if (!in_array($teamValue, [TeamEnum::BLUE->value, TeamEnum::RED->value], true)) {
                    continue;
                }
                if (!isset($message['data']) || !is_array($message['data'])) {
                    continue;
                }
                unset($message['data']['deliveryStatus'], $message['data']['routePoints']);
                continue;
            }

            if (!in_array($type, ['room', 'room_options_update', 'room_per_team_settings_update'], true)) {
                continue;
            }
            if (!isset($message['data']) || !is_array($message['data'])) {
                continue;
            }

            if ($type === 'room' && isset($message['data']['options']) && is_array($message['data']['options'])) {
                $message['data']['options'] = $roomOptionsService->sanitizeOptionsForTeam(
                    $message['data']['options'],
                    $teamValue
                );
                continue;
            }

            if ($type === 'room_options_update') {
                $message['data'] = $roomOptionsService->sanitizeOptionsForTeam(
                    $message['data'],
                    $teamValue
                );
                continue;
            }

            if ($type === 'room_per_team_settings_update') {
                $message['data'] = $roomOptionsService->sanitizePerTeamSettingsPatchForTeam(
                    $message['data'],
                    $teamValue
                );
            }
        }
        unset($message);

        return $messages;
    }

    private function buildChatUnitFallbackTitles(int $roomId, array $unitIds): array
    {
        $normalizedUnitIds = array_values(array_unique(array_filter(
            array_map(fn ($unitId) => (string) $unitId, $unitIds),
            fn (string $unitId) => $unitId !== '',
        )));
        if (!$normalizedUnitIds) {
            return [];
        }

        $adminRoomMapId = \App\Models\RoomMap::query()
            ->where('room_id', $roomId)
            ->where('team', TeamEnum::ADMIN)
            ->value('id');
        if (!$adminRoomMapId) {
            return [];
        }

        return RoomMapItem::query()
            ->where('room_map_id', $adminRoomMapId)
            ->where('type', RoomMapItemsService::TYPE_UNIT)
            ->whereIn('item_id', $normalizedUnitIds)
            ->get(['item_id', 'data'])
            ->reduce(function (array $carry, RoomMapItem $roomMapItem) {
                $label = isset($roomMapItem->data['label']) ? trim((string) $roomMapItem->data['label']) : '';
                if ($label !== '') {
                    $carry[(string) $roomMapItem->item_id] = $label;
                }
                return $carry;
            }, []);
    }

    private function isMapSyncCommand(\App\Models\RoomChat $roomChat): bool
    {
        $text = trim(mb_strtolower((string) ($roomChat->data ?? '')));
        return in_array($text, self::MAP_SYNC_COMMANDS, true);
    }

    private function syncUnitsBetweenRoomMaps(
        int $firstRoomMapId,
        int $secondRoomMapId,
        int $roomMapUserId
    ): void {
        if ($firstRoomMapId <= 0 || $secondRoomMapId <= 0 || $firstRoomMapId === $secondRoomMapId || $roomMapUserId <= 0) {
            return;
        }

        $firstUnits = $this->loadCopyableUnitsForRoomUser($firstRoomMapId, $roomMapUserId);
        $secondUnits = $this->loadCopyableUnitsForRoomUser($secondRoomMapId, $roomMapUserId);

        $this->copyUnitsToRoomMap($firstUnits, $secondRoomMapId);
        $this->copyUnitsToRoomMap($secondUnits, $firstRoomMapId);
    }

    /**
     * @return RoomMapItem[]
     */
    private function loadCopyableUnitsForRoomUser(int $roomMapId, int $roomMapUserId): array
    {
        return RoomMapItem::query()
            ->where('room_map_id', $roomMapId)
            ->where('type', RoomMapItemsService::TYPE_UNIT)
            ->get()
            ->filter(function (RoomMapItem $item) use ($roomMapUserId): bool {
                $unit = $item->data;
                if (!is_array($unit)) {
                    return false;
                }

                $unitType = strtolower((string) ($unit['type'] ?? ''));
                if (in_array($unitType, ['messenger'], true)) {
                    return false;
                }

                return (int) ($unit['roomMapUserId'] ?? 0) === $roomMapUserId;
            })
            ->values()
            ->all();
    }

    /**
     * @param RoomMapItem[] $units
     */
    private function copyUnitsToRoomMap(array $units, int $targetRoomMapId): void
    {
        foreach ($units as $unitItem) {
            RoomMapItem::query()->updateOrCreate(
                [
                    'room_map_id' => $targetRoomMapId,
                    'type' => RoomMapItemsService::TYPE_UNIT,
                    'item_id' => (string) $unitItem->item_id,
                ],
                [
                    'data' => $unitItem->data,
                ]
            );
        }
    }

    private function applyLastMoveTargetForHiddenDirectViewUnit(array &$unitData): bool
    {
        $commands = $unitData['commands'] ?? null;
        if (!is_array($commands)) {
            return false;
        }

        for ($i = count($commands) - 1; $i >= 0; $i--) {
            $command = $commands[$i] ?? null;
            if (!is_array($command) || ($command['type'] ?? null) !== 'move') {
                continue;
            }

            $state = $command['state'] ?? null;
            if (!is_array($state)) {
                continue;
            }

            $target = $state['target'] ?? null;
            if (!is_array($target) || !array_key_exists('x', $target) || !array_key_exists('y', $target)) {
                continue;
            }

            $x = (float) $target['x'];
            $y = (float) $target['y'];
            $prevPos = $unitData['pos'] ?? null;
            if (is_array($prevPos)) {
                $prevX = (float) ($prevPos['x'] ?? $x);
                $prevY = (float) ($prevPos['y'] ?? $y);
                if (abs($prevX - $x) < 0.0001 && abs($prevY - $y) < 0.0001) {
                    return false;
                }
            }

            $unitData['pos'] = [
                'x' => $x,
                'y' => $y,
            ];

            return true;
        }

        return false;
    }

}
