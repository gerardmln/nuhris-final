<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserType
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, int|string ...$types): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->route('login');
        }

        $allowedTypes = array_map(static fn ($type) => (int) $type, $types);

        if (! collect($allowedTypes)->contains(fn (int $type) => $user->canAccessPortal($type))) {
            abort(403, 'You are not authorized to access this module.');
        }

        return $next($request);
    }
}
