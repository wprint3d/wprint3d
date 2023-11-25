<?php

namespace App\Http\Middleware;

use App\Enums\LogoutReason;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

use Closure;

class Authenticate
{

    public function handle($request, Closure $next) {
        $user = Auth::user();

        if ($user) {
            if ($user->getSessionHash() != $user->getCachedHash()) {
                Auth::logout();

                Session::invalidate(); // force invalidate session, just in case Laravel thinks it shouldn't

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
