<?php

namespace App\Http\Controllers;

use App\Enums\TeamEnum;
use App\Models\User;
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
            'id'   => $user->id,
            'name' => $user->name,
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

        $rooms = $user->rooms()->get()->map(function ($room) use ($user) {
            $room->makeVisible(['admin_key', 'red_key', 'blue_key']);
            $team = $room->pivot->team;
            $key = ($room->admin_id === $user->id || $team === TeamEnum::ADMIN)
                ? $room->admin_key
                : ($team === TeamEnum::RED ? $room->red_key : ($team === TeamEnum::BLUE ? $room->blue_key : null));

            return [
                'uuid'        => $room->uuid,
                'name'        => $room->name,
                'team'        => $team->value,
                'key'         => $key,
                'stage'       => $room->stage,
                'ingame_time' => $room->ingame_time,
                'options'     => $room->options,
                'admin_id'    => $room->admin_id,
                'map_url'     => $room->map_url ?? null,
                'height_map_url' => $room->height_map_url ?? null,
                'weather'     => $room->weather ?? null,
                'created_at'  => $room->created_at,
                'updated_at'  => $room->updated_at,
            ];
        });

        return response()->json($rooms);
    }
}
