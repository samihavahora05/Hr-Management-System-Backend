<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RoleAuthMiddleware
{
    /**
     * Handle an incoming request and enforce server-side role-based access control.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @param  string  ...$roles
     * @return mixed
     */
    public function handle(Request $request, Closure $next, ...$roles)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $userRole = $user->getCanonicalRole();

        // Admin override: admins are permitted for all role-restricted routes
        if ($userRole === 'admin') {
            return $next($request);
        }

        if (!empty($roles)) {
            $allowedRoles = array_map('strtolower', $roles);
            if (!in_array($userRole, $allowedRoles)) {
                return response()->json([
                    'message' => 'Unauthorized: Insufficient role permissions for this endpoint'
                ], 403);
            }
        }

        return $next($request);
    }
}
