<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PermissionMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(Response::HTTP_UNAUTHORIZED, 'Authentication required.');
        }

        $allowed = collect($permissions)
            ->flatMap(static function ($permission) {
                return explode('|', $permission);
            })
            ->filter()
            ->unique()
            ->contains(static function ($permission) use ($user) {
                return $user->hasPermission($permission);
            });

        if (! $allowed) {
            abort(Response::HTTP_FORBIDDEN, 'Insufficient permissions.');
        }

        return $next($request);
    }
}
