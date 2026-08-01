<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureOctoPrintAbility
{
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();

        if (! $user || ($token && $token->cant($ability))) {
            return $this->forbidden();
        }

        if (! $token && $ability !== 'read' && $user->role === UserRole::SPECTATOR) {
            return $this->forbidden();
        }

        return $next($request);
    }

    private function forbidden(): Response
    {
        return response()->json(['error' => 'Your account cannot perform this operation.'], 403);
    }
}
