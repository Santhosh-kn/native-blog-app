<?php

namespace App\Http\Controllers;

use App\Support\PushNotificationDeepLink;
use Bbs\FirebasePushNotifications\Facades\FirebasePushNotifications;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

class PushNotificationDeepLinkController extends Controller
{
    public function capture(Request $request, PushNotificationDeepLink $deepLink): RedirectResponse
    {
        $tapId = $request->query('tap');
        if (! is_string($tapId) || ! Str::isUuid($tapId)) {
            return $this->fallbackToHome($request, $deepLink);
        }

        try {
            $result = FirebasePushNotifications::getPendingNotification($tapId);
        } catch (Throwable $exception) {
            report($exception);

            return $this->fallbackToHome($request, $deepLink);
        }

        if (! is_object($result) || ! ($result->available ?? false)) {
            return $this->fallbackToHome($request, $deepLink);
        }

        $destination = $deepLink->normalize($result->payload ?? null);

        if ($destination === null || ! $deepLink->store($request->session(), $destination)) {
            return $this->fallbackToHome($request, $deepLink);
        }

        return redirect()->route('push.deep-link.resume');
    }

    public function resume(Request $request, PushNotificationDeepLink $deepLink): RedirectResponse
    {
        $destination = $deepLink->pull($request->session());
        $user = $request->user();

        if ($destination === null || $user === null) {
            return $this->fallbackToHome($request, $deepLink);
        }

        $route = $deepLink->routeFor($user, $destination);

        if ($route === null) {
            return $this->fallbackToHome($request, $deepLink);
        }

        return redirect()->route($route['name'], $route['parameters']);
    }

    private function fallbackToHome(Request $request, PushNotificationDeepLink $deepLink): RedirectResponse
    {
        $deepLink->forget($request->session());

        return redirect()->route('home');
    }
}
