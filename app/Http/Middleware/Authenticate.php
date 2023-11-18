<?php

namespace App\Http\Middleware;

use App\Enums\LogoutReason;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

use Closure;

class Authenticate
{

    public function handle($request, Closure $next) {
        $user = Auth::user();

        if ($user) {
            Log::info( $user->getSessionHash() . ' != ' . $user->getCachedHash() );

            if ($user->getSessionHash() != $user->getCachedHash()) {
                Auth::logout();

                return redirect()->away(
                    route(
                        name:       'login',
                        parameters: [ 'logoutReason' => LogoutReason::ACCOUNT_CHANGED ],
                        absolute:   false
                    )
                );
            }

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
