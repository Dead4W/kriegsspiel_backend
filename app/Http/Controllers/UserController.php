<?php

namespace App\Http\Controllers;

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
}
