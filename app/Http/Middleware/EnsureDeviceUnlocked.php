<?php

namespace App\Http\Middleware;

use App\Support\PushNotificationDeepLink;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureDeviceUnlocked
{
    public function handle(
        Request $request,
        Closure $next
    ): Response {
        if (! auth()->check()) {
            return redirect()->route('login');
        }

        if (! session('device_unlocked')) {
            return redirect()->route('unlock');
        }

        if (
            $request->session()->has(
                PushNotificationDeepLink::SESSION_KEY
            ) &&
            ! $request->routeIs('push.deep-link.resume')
        ) {
            return redirect()->route(
                'push.deep-link.resume'
            );
        }

        return $next($request);
    }
}
