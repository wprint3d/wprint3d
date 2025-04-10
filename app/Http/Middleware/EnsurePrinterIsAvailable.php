<?php

namespace App\Http\Middleware;

use App\Models\Configuration;
use App\Models\User;

use Illuminate\Http\Request;

use Illuminate\Validation\ValidationException;

use Symfony\Component\HttpFoundation\Response;

use AllowDynamicProperties;
use Closure;

#[AllowDynamicProperties]
class EnsurePrinterIsAvailable
{

    private ?User $user;

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $this->user = $request->user();

        $request->{'printer'}               = $this->user->getActivePrinter();
        $request->{'streamMaxLengthBytes'}  = Configuration::get('streamMaxLengthBytes');

        if (!$request->printer) {
            throw ValidationException::withMessages([ 'printer' => 'No printer selected.' ]);
        }

        return $next($request);
    }

}
