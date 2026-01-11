<?php

namespace App\Socket\Callbacks;

use App\Enums\ConnectionClientTypeEnum;
use App\Enums\TeamEnum;
use App\Models\Connection;
use App\Socket\Actions\GetOtherListenersAction;
use App\Socket\Actions\SocketErrorAction;
use Carbon\Carbon;
use OpenSwoole\WebSocket\Frame;
use OpenSwoole\WebSocket\Server;

class SocketOnMessageCallback extends AbstractSocketCallback
{

    public function __invoke(Server $server, Frame $frame) {
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
        $timeMessages = [];
        $selfMessages = [];
        $directViewMessages = [];

        \Illuminate\Support\Facades\DB::transaction(function () use (
            $currentConnection,
            $decodedFrameData,
            &$goodMessages,
            &$chatMessages,
            &$timeMessages,
            &$selfMessages,
            &$directViewMessages,
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
            $roomMapUnits = $roomMap->units ?? [];
            foreach ($decodedFrameData['messages'] as $message) {
                if ($message['type'] === 'unit') {
                    $unitUuid = $message['data']['id'];

                    $roomMapUnits[$unitUuid] = $message['data'];
                } elseif ($message['type'] === 'unit-remove') {
                    foreach ($message['data'] as $unitUuid) {
                        unset($roomMapUnits[$unitUuid]);
                    }
                } elseif ($message['type'] === 'paint') {
                    $paint = $roomMap->paint ?? [];

                    foreach ($message['pos_list'] as $pos) {
                        $paint[$pos] = 1;
                    }

                    $roomMap->paint = $paint;
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
                    // pass backend
                } elseif ($currentConnection->team === TeamEnum::ADMIN) {
                    if ($message['type'] === 'skip_time') {
                        $room->ingame_time = Carbon::createFromFormat('Y-m-d H:i:s', $message['data']);

                        $snapshot = new \App\Models\Snapshot();
                        $snapshot->room_map_id = $roomMap->id;
                        $snapshot->units = $roomMapUnits;
                        $snapshot->paint = $roomMap->paint;
                        $snapshot->ingame_time = $room->ingame_time;
                        $snapshot->save();

                        $timeMessages[] = $message;
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
                        if ($room->stage === 'planning' && $message['data'] === 'war') {
                            $room->stage = $message['data'];
                        } else if ($room->stage === 'war' && $message['data'] === 'end') {
                            $room->stage = $message['data'];
                        } else {
                            $this->error("Invalid stage value '{$message['data']}'");
                            // bad stage
                            continue;
                        }
                    } else if ($message['type'] === 'copy_board') {
                        if (!in_array($message['data'], [TeamEnum::RED->value, TeamEnum::BLUE->value])) {
                            // bad team
                            $this->error("Invalid team value '{$message['data']}'");
                            continue;
                        }

                        $roomMapOtherTeam = \App\Models\RoomMap::query()
                            ->where('room_id', $currentConnection->room_id)
                            ->where('team', $message['data'])
                            ->firstOrFail();
                        $this->error("Test map other team");

                        if (!$roomMapOtherTeam) {
                            $this->error("Not found map for team '{$message['data']}'");
                            continue;
                        }

                        $otherTeamUnits = $roomMapOtherTeam->units;
                        $otherTeamUnits = array_filter($otherTeamUnits, function ($unit) use ($message) {
                            return $unit['team'] && $unit['team'] === $message['data'];
                        });
                        foreach ($otherTeamUnits as $unitUuid => $unit) {
                            $copyKeys = ['id', 'label', 'type', 'team', 'pos', 'envState'];
                            $copyUnit = [];
                            foreach ($copyKeys as $key) {
                                $copyUnit[$key] = $unit[$key] ?? null;
                            }
                            $roomMapUnits[$unitUuid] = $copyUnit;
                            $selfMessages[] = [
                                'type' => 'unit',
                                'data' => $copyUnit,
                                'frames' => [],
                            ];
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

                        $roomChat->ingame_time = $message['data']['time'];
                        $roomChat->status = 'delivered';
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
                    } else if ($message['type'] === 'direct_view') {
                        $roomMapTeam = \App\Models\RoomMap::query()
                            ->where('room_id', $currentConnection->room_id)
                            ->where('team', $message['team'])
                            ->first();

                        if (!$roomMapTeam) {
                            $this->error("Not found roomMap for team '{$message['team']}'");
                            continue;
                        }

                        $directViewUuids = [];
                        $roomMapTeamUnits = $roomMapTeam->units;
                        foreach ($roomMapTeamUnits as $unitUuid => $unit) {
                            if ($unit['directView'] && $unit['team'] !== $message['team']) {
                                unset($roomMapTeamUnits[$unitUuid]);
                            }
                        }
                        foreach ($message['data'] as $messageData) {
                            $directViewUuids[] = $messageData['id'];
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
                        $roomMapTeam->units = $roomMapTeamUnits;
                        $roomMapTeam->save();
                        $directViewMessages[] = $message;
                        continue;
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
            $roomMap->units = $roomMapUnits;
            $roomMap->save();
        });

        $server->push($currentConnection->id,  json_encode([
            'type' => 'messages',
            'messages' => $selfMessages,
        ]));

        $data = [
            'type' => 'messages',
            'messages' => array_merge($goodMessages, $selfMessages),
        ];
        $connectionIds = GetOtherListenersAction::run($currentConnection);
        foreach ($connectionIds as $connectionId) {
            $server->push($connectionId,  json_encode($data));
        }
        foreach ($chatMessages as $chatMessage) {
            if ($chatMessage['data']['status'] === 'delivered') {
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

        $connectionIds = GetOtherListenersAction::run($currentConnection, [
            TeamEnum::SPECTATOR,
            TeamEnum::ADMIN,
            TeamEnum::BLUE,
            TeamEnum::RED,
        ]);
        $data = [
            'type' => 'messages',
            'messages' => $timeMessages,
        ];
        foreach ($connectionIds as $connectionId) {
            $server->push($connectionId,  json_encode($data));
        }

        foreach ($directViewMessages as $directViewMessage) {
            $connectionIds = GetOtherListenersAction::run($currentConnection, [$directViewMessage['team']]);
            foreach ($connectionIds as $connectionId) {
                $server->push($connectionId,  json_encode([
                    'type' => 'messages',
                    'messages' => [$directViewMessage],
                ]));
            }
        }
    }

}
