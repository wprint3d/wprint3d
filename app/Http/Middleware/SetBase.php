<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;

use Josantonius\Url\Url;

use Closure;

class SetBase
{

    public function handle(Request $request, Closure $next) {
        if (session()->has('app_url')) {
            $url = new Url( session()->get('app_url') );

            config([ 'app.url' => $url->base ]);

            $request->headers->set('x-forwarded-proto',   $url->scheme);
            $request->headers->set('x-forwarded-host',    $url->host);
            $request->headers->set('x-forwarded-port',    $url->port);
        }

        if (! session()->has('websocket_public_ports')) {;
            session()->put('websocket_public_ports', $this->getPublicWebSocketPorts());
        }

        return $next($request);
    }

}
