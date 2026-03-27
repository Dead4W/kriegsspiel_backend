<?php

namespace App\Socket\Callbacks;

use App\Enums\TeamEnum;
use App\Models\Connection;
use App\Models\RoomMapItem;
use App\Services\RoomMapItemsService;
use App\Services\MetricsService;
use App\Socket\Actions\GetOtherListenersAction;
use App\Socket\Actions\SocketErrorAction;
use Carbon\Carbon;
use OpenSwoole\WebSocket\Frame;
use OpenSwoole\WebSocket\Server;

class SocketOnMessageCallback extends AbstractSocketCallback
{

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

        $decodedFrameData = @json_decode($frame->data, true);

        if ($decodedFrameData === null) {
            $this->error("JSON data invalid");
            SocketErrorAction::run($server, $frame->fd, "JSON data invalid");
            return;
        }

        $goodMessages = [];
        $chatMessages = [];
        $allMessages = [];
        $selfMessages = [];
        $messagesByTeam = [
            TeamEnum::RED->value => [],
            TeamEnum::BLUE->value => [],
            TeamEnum::ADMIN->value => [],
            TeamEnum::SPECTATOR->value => [],
        ];

        \Illuminate\Support\Facades\DB::transaction(function () use (
            $currentConnection,
            $decodedFrameData,
            &$goodMessages,
            &$chatMessages,
            &$allMessages,
            &$selfMessages,
            &$messagesByTeam,
        ) {
            $room = \App\Models\Room::query()
                ->where('id', $currentConnection->room_id)
                ->firstOrFail();

            if ($currentConnection->team === TeamEnum::SPECTATOR || $room->stage === 'end') {
                foreach ($decodedFrameData['messages'] as $message) {
                    if (in_array($message['type'], ['cursor'])) {
                        $goodMessages[] = $message;
                    }
                }
                // Ignore all messages exclude client
                return;
            }
            $roomMap = \App\Models\RoomMap::query()
                ->where('room_id', $currentConnection->room_id)
                ->where('team', $currentConnection->team)
                ->firstOrFail();
            $roomMapItemsService = app(RoomMapItemsService::class);
            foreach ($decodedFrameData['messages'] as $message) {
                if ($message['type'] === 'unit') {
                    $itemData = $message['data'];
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
                } elseif ($message['type'] === 'unit-remove') {
                    if (!empty($message['data'])) {
                        \App\Models\RoomMapItem::query()
                            ->where('room_map_id', $roomMap->id)
                            ->where('type', RoomMapItemsService::TYPE_UNIT)
                            ->whereIn('item_id', $message['data'])
                            ->delete();
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
                        ]
                    );

                    if ($isSharedForPlayers) {
                        unset($message['data']['sharedForPlayers']);
                        $otherMaps = \App\Models\RoomMap::query()
                            ->where('room_id', $currentConnection->room_id)
                            ->where('team', '!=', TeamEnum::ADMIN)
                            ->get();

                        foreach ($otherMaps as $otherMap) {
                            RoomMapItem::query()->updateOrCreate(
                                [
                                    'room_map_id' => $otherMap->id,
                                    'type' => RoomMapItemsService::TYPE_PAINT,
                                    'item_id' => $paintData['id'],
                                ],
                                [
                                    'data' => $paintData,
                                ]
                            );
                        }

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
                    $roomChat = new \App\Models\RoomChat();
                    $roomChat->uuid = $message['data']['id'];
                    $roomChat->author = $message['data']['author'];
                    $roomChat->author_team = $currentConnection->team;
                    $roomChat->unitIds = (array) $message['data']['unitIds'];
                    $roomChat->status = $message['data']['status'];
                    $roomChat->team = $message['data']['team'];
                    $roomChat->data = $message['data']['text'];
                    $roomChat->ingame_time = $room->ingame_time;
                    $roomChat->room_id = $currentConnection->room_id;
                    $roomChat->save();
                    $message['data']['author_team'] = $currentConnection->team;
                    $message['data']['time'] = $room->ingame_time->format('Y-m-d H:i:s');
                    if ($currentConnection->team === TeamEnum::ADMIN) {
                        $goodMessages[] = $message;
                    } else {
                        $chatMessages[] = $message;
                    }
                    continue;
                } elseif ($message['type'] === 'cursor') {
                    if (in_array($currentConnection->team, [TeamEnum::BLUE, TeamEnum::RED])) {
                        // Send to admin/spectator
                        $messagesByTeam[TeamEnum::ADMIN->value][] = $message;
                        $messagesByTeam[TeamEnum::SPECTATOR->value][] = $message;
                    }
                } else if ($message['type'] === 'ruler') {
                    // pass backend
                } elseif ($currentConnection->team === TeamEnum::ADMIN) {
                    if ($message['type'] === 'skip_time') {
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
                        }
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
                        $roomChat->save();

                        $chatMessages[] = [
                            'type' => 'chat',
                            'data' => [
                                'id' => $roomChat->uuid,
                                'author' => $roomChat->author,
                                'author_team' => $roomChat->author_team,
                                'unitIds' => $roomChat->unitIds,
                                'text' => $roomChat->data,
                                'time' => $roomChat->ingame_time->format('Y-m-d H:i:s'),
                                'team' => $roomChat->team,
                                'status' => $roomChat->status,
                            ],
                        ];

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

                            $roomMapTeam = \App\Models\RoomMap::query()
                                ->where('room_id', $currentConnection->room_id)
                                ->where('team', $roomChat->team)
                                ->first();
                            if (!$roomMapTeam) {
                                $this->error("Not found roomMap for team '{$message['team']}'");
                                continue;
                            }

                            if ($roomChat->unitIds) {
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
                                    $messagesByTeam[$team][] = [
                                        'type' => 'unit',
                                        'data' => $roomMapTeamUnits[$unitId],
                                    ];
                                }
                                foreach ($roomMapTeamUnits as $itemId => $itemData) {
                                    RoomMapItem::query()->updateOrCreate(
                                        [
                                            'room_map_id' => $roomMapTeam->id,
                                            'type' => RoomMapItemsService::TYPE_UNIT,
                                            'item_id' => (string) $itemId,
                                        ],
                                        [
                                            'data' => $itemData,
                                        ]
                                    );
                                }
                            }
                        }
                    } else if ($message['type'] === 'direct_view') {
                        $roomMapTeam = \App\Models\RoomMap::query()
                            ->where('room_id', $currentConnection->room_id)
                            ->where('team', $message['team'])
                            ->first();

                        if (!$roomMapTeam) {
                            $this->error("Not found roomMap for team '{$message['team']}'");
                            continue;
                        }

                        \App\Models\RoomMapItem::query()
                            ->where('room_map_id', $roomMapTeam->id)
                            ->where('type', RoomMapItemsService::TYPE_UNIT)
                            ->where('data->directView', true)
                            ->where(function ($query) use ($message) {
                                $query->where('data->team', '!=', $message['team']);
                            })
                            ->delete();

                        $directViewUuids = [];
                        foreach ($message['data'] as $messageData) {
                            $directViewUuids[] = $messageData['id'];
                        }

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

                        foreach ($message['data'] as $messageData) {
                            if (isset($roomMapTeamUnits[$messageData['id']])) {
                                foreach ($messageData as $unitKey => $unitValue) {
                                    $roomMapTeamUnits[$messageData['id']][$unitKey] = $unitValue;
                                }
                            } else {
                                $roomMapTeamUnits[$messageData['id']] = $messageData;
                            }
                        }
                        foreach ($roomMapTeamUnits as &$roomMapTeamUnit) {
                            if (isset($roomMapTeamUnit['id'])) {
                                $roomMapTeamUnit['directView'] = in_array($roomMapTeamUnit['id'], $directViewUuids);
                            }
                        }
                        foreach ($roomMapTeamUnits as $itemData) {
                            RoomMapItem::query()->updateOrCreate(
                                [
                                    'room_map_id' => $roomMapTeam->id,
                                    'type' => RoomMapItemsService::TYPE_UNIT,
                                    'item_id' => $itemData['id'],
                                ],
                                [
                                    'data' => $itemData,
                                ]
                            );
                        }
                        $messagesByTeam[$message['team']][] = $message;
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

        if ($selfMessages) {
            $server->push($currentConnection->id,  json_encode([
                'type' => 'messages',
                'messages' => $selfMessages,
            ]));
        }

        $data = [
            'type' => 'messages',
            'messages' => array_merge($goodMessages, $selfMessages),
        ];
        if ($data['messages']) {
            $connectionIds = GetOtherListenersAction::run($currentConnection);
            foreach ($connectionIds as $connectionId) {
                $server->push($connectionId,  json_encode($data));
            }
        }

        foreach ($chatMessages as $chatMessage) {
            if ($chatMessage['data']['delivered'] ?? false) {
                $connectionIds = GetOtherListenersAction::run($currentConnection, [
                    $chatMessage['data']['team'],
                ]);
            } else {
                $connectionIds = GetOtherListenersAction::run($currentConnection, [
                    TeamEnum::SPECTATOR,
                    TeamEnum::ADMIN,
                    $chatMessage['data']['team'],
                ]);
            }
            $data = [
                'type' => 'messages',
                'messages' => [$chatMessage],
            ];
            foreach ($connectionIds as $connectionId) {
                $server->push($connectionId,  json_encode($data));
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
            foreach ($connectionIds as $connectionId) {
                $server->push($connectionId,  json_encode($data));
            }
        }

        foreach ($messagesByTeam as $team => $messages) {
            if (!$messages) continue;
            $connectionIds = GetOtherListenersAction::run($currentConnection, [$team]);
            foreach ($connectionIds as $connectionId) {
                $server->push($connectionId, json_encode([
                    'type' => 'messages',
                    'messages' => $messages,
                ]));
            }
        }
    }

}
