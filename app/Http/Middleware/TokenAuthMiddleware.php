<?php

namespace App\Http\Middleware;

use App\Models\UserToken;
use Closure;
use Illuminate\Http\Request;

class TokenAuthMiddleware {
    public function handle(Request $request, Closure $next)
    {
        $header = $request->header('Authorization');

        if (!$header || !str_starts_with($header, 'Bearer ')) {
            return $this->redirectToLogin(
                $request,
                'error.auth_required'
            );
        }

        $token = substr($header, 7);

        $userToken = UserToken::with('user')
            ->where('token', $token)
            ->first();

        if (!$userToken || !$userToken->user) {
            return $this->redirectToLogin(
                $request,
                'error.bad_token'
            );
        }

        auth()->setUser($userToken->user);

        return $next($request);
    }

    protected function redirectToLogin(Request $request, string $message)
    {
        $redirectUrl = $request->fullUrl();

        // для API — JSON
        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'redirect_url' => $redirectUrl,
            ], 401);
        }

        // для web — redirect
        return redirect('/login')
            ->withErrors([
                'auth' => $message,
            ])
            ->with([
                'redirect_url' => $redirectUrl,
            ]);
    }
}
