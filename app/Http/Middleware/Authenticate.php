<?php

namespace App\Http\Middleware;

use Illuminate\Support\Facades\Auth;

use Closure;

class Authenticate
{

    public function handle($request, Closure $next) {
        if (Auth::user()) {
            return $next($request);
        }

        return redirect()->away(
            route(
                name:       'login',
                parameters: [],
                absolute:   false
            )
        );
    }

}
