<?php

namespace App\Http\Controllers;

use Bbs\FirebaseGoogleAuth\Facades\FirebaseGoogleAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

class GoogleAuthController extends Controller
{
    public function start(Request $request): JsonResponse
    {
        $requestId = (string) Str::uuid();
        $cacheKey = "firebase_google_auth_result:{$requestId}";

        Cache::forget($cacheKey);

        $request->session()->put(
            'firebase_google_auth_request_id',
            $requestId,
        );

        try {
            FirebaseGoogleAuth::signIn([
                'id' => $requestId,
            ]);
        } catch (Throwable $exception) {
            $request->session()->forget(
                'firebase_google_auth_request_id',
            );

            report($exception);

            return response()->json([
                'status' => 'failed',
                'message' => 'Unable to start Google authentication.',
            ], 500);
        }

        return response()->json([
            'status' => 'started',
            'request_id' => $requestId,
        ]);
    }

    public function status(Request $request, string $requestId): JsonResponse {
        $sessionRequestId = $request->session()->get('firebase_google_auth_request_id');

        if (! is_string($sessionRequestId) || ! hash_equals($sessionRequestId, $requestId) ) {
            return response()->json([ 'status' => 'forbidden', 'message' => 'This Google authentication request is not valid.' ], 403);
        }

        $result = Cache::pull(
            "firebase_google_auth_result:{$requestId}",
        );

        if ($result === null) {
            return response()->json(['status' => 'pending']);
        }

        $request->session()->forget('firebase_google_auth_request_id');

        if (! is_array($result)) {
            return response()->json([ 'status' => 'failed', 'message' => 'An invalid Google authentication result was received.' ], 500);
        }

        if (! ($result['success'] ?? false)) {
            return response()->json([
                'status' => ($result['cancelled'] ?? false) ? 'cancelled' : 'failed',
                'message' => $result['error'] ?? 'Google authentication failed.',
            ]);
        }

        $firebaseUid = $result['firebase_uid'] ?? null;
        $email = $result['email'] ?? null;

        if (! is_string($firebaseUid) || trim($firebaseUid) === '' || ! is_string($email) || trim($email) === '') {
            return response()->json([
                'status' => 'failed',
                'message' => 'Firebase did not return the required user information.',
            ], 500);
        }

        $firebaseUid = trim($firebaseUid);
        $email = strtolower(trim($email));

        $googleName = $result['name'] ?? null;

        if (! is_string($googleName) || trim($googleName) === '') {
            $googleName = Str::before($email, '@');
        } else {
            $googleName = trim($googleName);
        }

        $avatarUrl = $result['avatar_url'] ?? null;

        if (! is_string($avatarUrl) || trim($avatarUrl) === '') {
            $avatarUrl = null;
        }

        try {
            $user = User::query()->where('firebase_uid', $firebaseUid)->first();

            if ($user === null) {
                $user = User::query()->where('email', $email)->first();
            }

            if ( $user !== null && $user->firebase_uid !== null && $user->firebase_uid !== $firebaseUid ) {
                return response()->json([ 'status' => 'failed', 'message' => 'This email is already connected to another Google account.'], 409);
            }

            if ($user === null) {
                $user = User::create([
                    'name' => $googleName,
                    'email' => $email,
                    'password' => Str::random(64),
                    'firebase_uid' => $firebaseUid,
                    'avatar_url' => $avatarUrl,
                ]);
            } else {
                $user->update([
                    'firebase_uid' => $firebaseUid,
                    'avatar_url' => $avatarUrl ?? $user->avatar_url,
                ]);
            }
        } catch (Throwable $exception) {
            report($exception);
            return response()->json([ 'status' => 'failed', 'message' => 'Unable to create or update the local user.', ], 500);
        }
        Auth::login($user);
        $request->session()->regenerate();
        return response()->json([ 'status' => 'authenticated', 'redirect' => route('home') ]);
    }
}