<?php

namespace App\Http\Middleware;

use App\Models\PersonalAccessToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateOctoPrint
{
    public function handle(Request $request, Closure $next): Response
    {
        $plainTextToken = $request->header('X-Api-Key')
            ?: $request->bearerToken()
            ?: $request->query('apikey');

        if (! is_string($plainTextToken) || trim($plainTextToken) === '') {
            return $request->user() ? $next($request) : $this->forbidden();
        }

        $accessToken = PersonalAccessToken::findToken(trim($plainTextToken));

        if (
            ! $accessToken
            || ($accessToken->kind ?? null) !== 'octoprint'
            || ($accessToken->expires_at && $accessToken->expires_at->isPast())
        ) {
            return $this->forbidden();
        }

        $user = $accessToken->tokenable;

        if (! $user) {
            return $this->forbidden();
        }

        $accessToken->forceFill(['last_used_at' => now()])->save();
        $user->withAccessToken($accessToken);

        Auth::setUser($user);
        $request->setUserResolver(fn () => $user);

        return $next($request);
    }

    private function forbidden(): Response
    {
        return response()->json(['error' => 'Invalid or missing API credentials.'], 403);
    }
}
