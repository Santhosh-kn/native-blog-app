<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureApiAuthenticated
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! session('api_token')) {
            return redirect()->route('login');
        }

        // if (! session('device_unlocked')) {
        //     return redirect()->route('unlock');
        // }
        session(['device_unlocked' => true]);
        return $next($request);
    }
}