<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class AuthController extends Controller
{
    public function redirectToGoogle()
    {
        return Socialite::driver('google')->redirect();
    }

    public function handleGoogleCallback()
    {
        $googleUser = Socialite::driver('google')->user();

        $user = User::query()
            ->where('google_id', $googleUser->getId())
            ->first();

        if (!$user) {
            $user = User::create([
                'name' => $googleUser->getName() ?? $googleUser->getEmail(),
                'google_id' => $googleUser->getId(),
                'email' => $googleUser->getEmail(),
                'avatar' => $googleUser->getAvatar(),
            ]);
        }

        $token = Str::random(64);
        $user->tokens()->create(['token' => $token]);

        $redirectUrl = config('services.google.frontend_redirect', env('FRONTEND_URL', '/'));
        $separator = str_contains($redirectUrl, '?') ? '&' : '?';
        $url = $redirectUrl . $separator . http_build_query([
            'token' => $token,
            'user_id' => $user->id,
        ]);

        return redirect($url);
    }
}
