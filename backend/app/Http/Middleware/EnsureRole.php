<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRole
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(401, 'Unauthenticated');
        }

        $allowed = collect($roles)
            ->map(fn (string $role) => UserRole::tryFrom($role)?->value ?? $role)
            ->filter()
            ->values();

        if ($allowed->isNotEmpty() && ! $allowed->contains($user->role->value)) {
            abort(403, 'Forbidden');
        }

        return $next($request);
    }
}
