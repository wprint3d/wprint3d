<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class SetLocaleFromRequest
{
    public function handle(Request $request, Closure $next)
    {
        $requestedLocale = $request->header('X-WPrint3D-Locale')
            ?: $request->getPreferredLanguage([
                'en',
                'es',
                'fr',
                'pt',
                'it',
                'de',
                'es-AR',
            ]);

        if (is_string($requestedLocale) && trim($requestedLocale) !== '') {
            app()->setLocale(str_replace('-', '_', trim($requestedLocale)));
        }

        return $next($request);
    }
}
