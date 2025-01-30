<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;

use Illuminate\Support\Facades\Auth;

use Symfony\Component\HttpFoundation\Response;

use Closure;

class EnsurePasswordWasChanged
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::user()->firstLogin ?? true) {
            return response(
                content: 'You must change your password before you can continue.',
                status: Response::HTTP_LOCKED
            );
        }

        return $next($request);
    }
}
