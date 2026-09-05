<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class TokenAuthMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $token = $request->bearerToken();

        if (!$token) {
            $authHeader = $request->header('Authorization') 
                ?? $request->header('authorization') 
                ?? $request->server('HTTP_AUTHORIZATION') 
                ?? $request->server('REDIRECT_HTTP_AUTHORIZATION');

            if ($authHeader && preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
                $token = trim($matches[1]);
            }
        }

        if (!$token) {
            $token = $request->header('X-Auth-Token') 
                ?? $request->header('X-Bearer-Token') 
                ?? $request->header('x-auth-token') 
                ?? $request->header('x-bearer-token') 
                ?? $request->query('token')
                ?? $request->input('token');
        }

        if (!$token) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        // 1. Direct lookup by remember_token
        $user = User::where('remember_token', $token)->with(['role', 'organization', 'shift', 'manager'])->first();

        // 2. Cache lookup if multi-session or token cached
        if (!$user) {
            $cachedUserId = Cache::get('auth_token_' . $token);
            if ($cachedUserId) {
                $user = User::where('id', $cachedUserId)->with(['role', 'organization', 'shift', 'manager'])->first();
            }
        }

        if (!$user) {
            return response()->json(['message' => 'Invalid or expired token'], 401);
        }

        // Cache token for resilience
        Cache::put('auth_token_' . $token, $user->id, now()->addDays(30));

        Auth::setUser($user);
        $request->setUserResolver(fn () => $user);

        return $next($request);
    }
}
