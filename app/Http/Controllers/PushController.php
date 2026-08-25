<?php

namespace App\Http\Controllers;

use Bbs\FirebasePushNotifications\Facades\FirebasePushNotifications;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

class PushController extends Controller
{
    private const REQUEST_SESSION_KEY = 'firebase_push_token_request_id';

    private const CACHE_KEY_PREFIX = 'firebase_push_token_result:';

    public function index(Request $request): View
    {
        $permissionResponse = FirebasePushNotifications::checkPermission();
        $requestId = $request->query('request_id');
        $sessionRequestId = $request->session()->get(self::REQUEST_SESSION_KEY);

        if (! is_string($requestId) || ! is_string($sessionRequestId) || ! hash_equals($sessionRequestId, $requestId)) {
            $requestId = null;
        }

        return view('push', [
            'permission' => $permissionResponse->status ?? 'unknown',
            'registered' => filled(
                $request->user()?->push_token
            ),
            'requestId' => $requestId,
        ]);
    }

    public function enroll(Request $request): RedirectResponse
    {
        $requestId = (string) Str::uuid();
        $cacheKey = self::CACHE_KEY_PREFIX.$requestId;
        Cache::forget($cacheKey);
        $request->session()->put(self::REQUEST_SESSION_KEY, $requestId);

        try {
            $permissionRequest = FirebasePushNotifications::requestPermission();
            $tokenRequest = FirebasePushNotifications::getToken($requestId);
        } catch (Throwable $exception) {
            Cache::forget($cacheKey);
            $request->session()->forget(self::REQUEST_SESSION_KEY);
            report($exception);

            return redirect()
                ->route('push.index')
                ->withErrors([
                    'push' => 'Unable to start native push registration.',
                ]);
        }

        if ($permissionRequest === null || $tokenRequest === null || ! ($tokenRequest->started ?? false)) {
            Cache::forget($cacheKey);
            $request->session()->forget(self::REQUEST_SESSION_KEY);

            return redirect()
                ->route('push.index')
                ->withErrors([
                    'push' => 'Unable to start native push registration.',
                ]);
        }

        return redirect()
            ->route('push.index', [
                'request_id' => $requestId,
            ])
            ->with('status', 'Push registration started.');
    }

    public function sync(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user === null) {
            return response()->json([
                'success' => false,
                'updated' => false,
                'status' => 'unauthenticated',
            ], 401);
        }

        $currentToken = $user->push_token;

        if (! is_string($currentToken) || trim($currentToken) === '') {
            return response()->json([
                'success' => true,
                'updated' => false,
                'status' => 'not_enrolled',
            ]);
        }

        try {
            $storedTokenResponse = FirebasePushNotifications::getStoredToken();
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'updated' => false,
                'status' => 'native_error',
            ], 500);
        }

        $storedToken = $storedTokenResponse->token ?? null;

        if (! ($storedTokenResponse->available ?? false) || ! is_string($storedToken) || trim($storedToken) === '') {
            return response()->json(['success' => true, 'updated' => false, 'status' => 'token_unavailable'], 202);
        }

        $currentToken = trim($currentToken);
        $storedToken = trim($storedToken);

        if (hash_equals($currentToken, $storedToken)) {
            return response()->json(['success' => true, 'updated' => false, 'status' => 'current']);
        }

        try {
            $user->update(['push_token' => $storedToken]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'updated' => false,
                'status' => 'storage_error',
            ], 500);
        }

        return response()->json([
            'success' => true,
            'updated' => true,
            'status' => 'updated',
        ]);
    }

    public function status(Request $request): JsonResponse
    {
        $validated = $request->validate(['id' => ['required', 'uuid']]);

        $requestId = $validated['id'];
        $sessionRequestId = $request->session()->get(self::REQUEST_SESSION_KEY);

        if (! is_string($sessionRequestId) || ! hash_equals($sessionRequestId, $requestId)) {
            return response()->json(['pending' => false, 'success' => false, 'message' => 'This push registration request is not valid.'], 403);
        }

        $permissionResponse = FirebasePushNotifications::checkPermission();
        $permission = $permissionResponse->status ?? 'unknown';
        $result = Cache::pull(self::CACHE_KEY_PREFIX.$requestId);

        if ($result === null) {
            return response()->json(['pending' => true, 'permission' => $permission], 202);
        }

        $request->session()->forget(self::REQUEST_SESSION_KEY);

        if (! is_array($result)) {
            return response()->json([
                'pending' => false,
                'success' => false,
                'permission' => $permission,
                'message' => 'An invalid Firebase token result was received.',
            ], 500);
        }

        $success = (bool) ($result['success'] ?? false);

        if (! $success) {
            return response()->json([
                'pending' => false,
                'success' => false,
                'permission' => $permission,
                'error' => $result['error']
                    ?? 'Firebase token registration failed.',
            ]);
        }

        try {
            $storedTokenResponse =
                FirebasePushNotifications::getStoredToken();
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'pending' => false,
                'success' => false,
                'permission' => $permission,
                'message' => 'Unable to read the Firebase device token.',
            ], 500);
        }

        $token = $storedTokenResponse->token ?? null;

        if (
            ! ($storedTokenResponse->available ?? false) ||
            ! is_string($token) ||
            trim($token) === ''
        ) {
            return response()->json([
                'pending' => false,
                'success' => false,
                'permission' => $permission,
                'message' => 'Firebase returned an invalid device token.',
            ], 500);
        }

        $token = trim($token);
        try {
            $request->user()->update(['push_token' => $token]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'pending' => false,
                'success' => false,
                'permission' => $permission,
                'message' => 'Unable to save the Firebase device token.',
            ], 500);
        }

        return response()->json([
            'pending' => false,
            'success' => true,
            'permission' => $permission,
            'registered' => true,
            'error' => null,
        ]);
    }
}
