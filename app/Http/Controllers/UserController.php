<?php

namespace App\Http\Controllers;

use App\Enums\TeamEnum;
use App\Models\User;
use App\Services\RoomOptionsService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class UserController extends Controller
{
    public function create(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:32'],
        ]);

        // создаём пользователя
        $user = User::create([
            'name' => $data['name'],
        ]);

        // генерируем токен
        $token = Str::random(64);

        $user->tokens()->create([
            'token' => $token,
        ]);

        return response()->json([
            'user_id' => $user->id,
            'token'   => $token,
        ]);
    }

    public function auth()
    {
        /** @var User $user */
        $user = auth()->user();

        return response()->json([
            'id'         => $user->id,
            'name'       => $user->name,
            'email'      => $user->email,
            'avatar_url' => $user->avatar,
            'picture'    => $user->avatar,
            'provider'   => $user->google_id ? 'google' : null,
        ]);
    }

    public function changeNickname(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:32'],
        ]);

        /** @var User $user */
        $user = auth()->user();
        $user->update(['name' => $data['name']]);

        return response()->json([
            'id'   => $user->id,
            'name' => $user->name,
        ]);
    }

    public function rooms()
    {
        /** @var User $user */
        $user = auth()->user();

        $rooms = $user
            ->rooms()
            ->with('resourcePack')
            ->orderByDesc('id')
            ->get()->map(function (\App\Models\Room $room) use ($user) {
            /** @var RoomOptionsService $roomOptionsService */
            $roomOptionsService = app(RoomOptionsService::class);
            $room->makeVisible(['admin_key', 'red_key', 'blue_key']);
            $team = $room->pivot->team;
            $key = ($room->admin_id === $user->id || $team === TeamEnum::ADMIN || $room->stage === 'end')
                ? $room->admin_key
                : ($team === TeamEnum::RED ? $room->red_key : ($team === TeamEnum::BLUE ? $room->blue_key : null));

            return array_merge([
                'uuid'        => $room->uuid,
                'name'        => $room->name,
                'team'        => $team->value,
                'key'         => $key,
                'stage'       => $room->stage,
                'ingame_time' => $room->ingame_time,
                'options'     => $room->options,
                'resource_pack_id' => $room->resource_pack_id,
                'resource_pack_public_id' => $room->resourcePack?->public_id,
                'resource_pack_url' => $room->resourcePack ? url('/api/resource-pack/' . $room->resourcePack->public_id) : null,
                'admin_id'    => $room->admin_id,
                'map_url'     => $room->map_url ?? null,
                'height_map_url' => $room->height_map_url ?? null,
                'weather'     => $room->weather ?? null,
                'created_at'  => $room->created_at,
                'updated_at'  => $room->updated_at,
            ], $roomOptionsService->getEndResults($room));
        });

        return response()->json($rooms);
    }
}
