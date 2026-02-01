<?php

namespace App\Http\Controllers;

use App\Models\Room;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class RoomController extends Controller
{
    public function create(Request $request): JsonResponse {
        $data = $request->validate([
            'name'      => 'required|string|max:255',
            'password'  => 'nullable|string|max:255',
            'options'   => 'required|array',
            'time'      => 'required|string|date_format:Y-m-d H:i:s',
        ]);

        /** @var User $user */
        $user = auth()->user();

        $room = new Room();
        $room->uuid = Str::uuid()->toString();
        $room->ingame_time = $data['time'];
        $room->stage = 'planning';
        $room->name = $data['name'];
        $room->password = $data['password'] ?? '';
        $room->admin_key = Str::random(32);
        $room->red_key = Str::random(32);
        $room->blue_key = Str::random(32);
        $room->admin_id = $user->id;
        $room->options = $data['options'];

        $room->save();

        return response()->json([
            'uuid' => $room->uuid,
            'admin_key' => $room->admin_key,
        ], 201);
    }

    public function get(Request $request, string $roomUuid): JsonResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:255'],
        ]);

        $room = Room::query()
            ->where('uuid', $roomUuid)
            ->first();

        if (!$room) {
            return response()->json([
                'message' => 'room_not_found',
            ], 404);
        }

        $team = null;
        if ($data['key'] === $room->admin_key) {
            $team = \App\Enums\TeamEnum::ADMIN;
        } elseif ($data['key'] === $room->red_key) {
            $team = \App\Enums\TeamEnum::RED;
        } elseif ($data['key'] === $room->blue_key) {
            $team = \App\Enums\TeamEnum::BLUE;
        }

        if ($team === null) {
            return response()->json([
                'message' => 'wrong_key',
            ], 403);
        }

        $result = [
            'uuid'       => $room->uuid,
            'team'       => $team,
            'name'       => $room->name,
            'admin_id'   => $room->admin_id,
            'options'    => $room->options,
            'created_at' => $room->created_at,
            'updated_at' => $room->updated_at,
        ];

        if ($team === \App\Enums\TeamEnum::ADMIN) {
            $result['admin_key'] = $room->admin_key;
            $result['red_key'] = $room->red_key;
            $result['blue_key'] = $room->blue_key;
        }

        return response()->json($result);
    }

    public function snapshots(Request $request, string $roomUuid): JsonResponse {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:255'],
        ]);

        $room = Room::query()
            ->where('uuid', $roomUuid)
            ->first();

        if (!$room) {
            return response()->json([
                'message' => 'room_not_found',
            ], 404);
        }

        if ($data['key'] !== $room->admin_key) {
            return response()->json([
                'message' => 'wrong_key',
            ], 403);
        }

        $roomMap = \App\Models\RoomMap::query()
            ->where('room_id', $room->id)
            ->where('team', \App\Enums\TeamEnum::ADMIN)
            ->firstOrFail();

        $snapshots = \App\Models\Snapshot::query()
            ->where('room_map_id', $roomMap->id)
            ->orderBy('ingame_time', 'asc')
            ->pluck('ingame_time');

        return response()->json($snapshots);
    }

    public function snapshot(Request $request, string $roomUuid, string $ingameTime): JsonResponse {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:255'],
        ]);

        $room = Room::query()
            ->where('uuid', $roomUuid)
            ->first();

        if (!$room) {
            return response()->json([
                'message' => 'room_not_found',
            ], 404);
        }

        if ($data['key'] !== $room->admin_key) {
            return response()->json([
                'message' => 'wrong_key',
            ], 403);
        }

        $roomMap = \App\Models\RoomMap::query()
            ->where('room_id', $room->id)
            ->where('team', \App\Enums\TeamEnum::ADMIN)
            ->firstOrFail();

        $snapshot = \App\Models\Snapshot::query()
            ->where('room_map_id', $roomMap->id)
            ->where('ingame_time', $ingameTime)
            ->first();

        if (!$snapshot) {
            return response()->json([
                'message' => 'snapshot_not_found',
            ], 404);
        }

        return response()->json([
            'units' => $snapshot->units,
            'paint' => $snapshot->paint,
        ]);
    }

    public function snapshotsChart(Request $request, string $roomUuid): JsonResponse {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:255'],
        ]);

        $room = Room::query()
            ->where('uuid', $roomUuid)
            ->first();

        if (!$room) {
            return response()->json([
                'message' => 'room_not_found',
            ], 404);
        }

        if ($data['key'] !== $room->admin_key) {
            return response()->json([
                'message' => 'wrong_key',
            ], 403);
        }

        $roomMap = \App\Models\RoomMap::query()
            ->where('room_id', $room->id)
            ->where('team', \App\Enums\TeamEnum::ADMIN)
            ->firstOrFail();

        $snapshots = \App\Models\Snapshot::query()
            ->where('room_map_id', $roomMap->id)
            ->orderBy('ingame_time', 'asc')
            ->lazyById(10);

        $result = [];
        foreach ($snapshots as $snapshot) {
            $hpRed = 0;
            $hpBlue = 0;
            foreach ($snapshot->units as $unit) {
                if (!isset($unit['hp'])) continue;
                if ($unit['type'] === 'messenger') continue;
                if ($unit['team'] === 'red') {
                    $hpRed += $unit['hp'];
                } else {
                    $hpBlue += $unit['hp'];
                }
            }
            $result[] = [
                'ingame_time' => $snapshot->ingame_time,
                'blue' => $hpBlue,
                'red' => $hpRed,
            ];
        }

        return response()->json($result);
    }
}
