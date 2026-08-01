<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureNativeApiAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        if ($token && ($token->kind ?? null) === 'octoprint') {
            return response()->json(['error' => 'This API token is limited to the OctoPrint compatibility API.'], 403);
        }

        return $next($request);
    }
}
