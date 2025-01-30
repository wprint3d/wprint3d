<?php

namespace App\Http\Middleware;

use App\Enums\LogoutReason;

use Illuminate\Http\Request;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

use Symfony\Component\HttpFoundation\Response;

use Closure;

class Authenticate
{

    private function getUnauthenticatedResponse(Request $request, $parameters = []) {
        if ($request->wantsJson()) {
            return response('Unauthenticated.', Response::HTTP_UNAUTHORIZED);
        }

        return redirect()->away(
            route(
                name:       'login',
                parameters: $parameters,
                absolute:   false
            )
        );
    }

    public function handle($request, Closure $next) {
        $user = Auth::user();

        if ($user) {
            if ($user->getSessionHash() != $user->getCachedHash()) {
                Auth::logout();

                Session::invalidate(); // force invalidate session, just in case Laravel thinks it shouldn't

                return $this->getUnauthenticatedResponse(
                    request:    $request,
                    parameters: [ 'logoutReason' => LogoutReason::ACCOUNT_CHANGED ]
                );
            }

            return $next($request);
        }

        return $this->getUnauthenticatedResponse($request);
    }

}
