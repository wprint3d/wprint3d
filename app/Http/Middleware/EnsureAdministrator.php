<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Illuminate\Http\Request;

use Illuminate\Support\Facades\Auth;

use Symfony\Component\HttpFoundation\Response;

use Closure;
use Illuminate\Support\Facades\Log;

class EnsureAdministrator
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::user()->role !== UserRole::ADMINISTRATOR) {
            return response(
                content: 'You must be an administrator to access this resource.',
                status: Response::HTTP_LOCKED
            );
        }

        return $next($request);
    }
}
