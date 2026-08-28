<?php

namespace App\Http\Controllers;

use App\Support\NativeImagePayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Native\Mobile\Facades\Camera;
use RuntimeException;

class CameraController extends Controller
{
    public function capture()
    {
        Cache::forget('pending_photo_path');
        Cache::forever('pending_photo_selection', [
            'state' => 'waiting',
            'message' => null,
        ]);

        Camera::getPhoto()->start();

        return redirect()->route('camera.waiting');
    }

    public function waiting()
    {
        return view('camera-waiting');
    }

    public function status(): JsonResponse
    {
        $path = Cache::get('pending_photo_path');
        $hasImage = is_string($path) && trim($path) !== '';
        $selection = Cache::get('pending_photo_selection');

        $state = is_array($selection) &&
            is_string($selection['state'] ?? null)
                ? $selection['state']
                : 'waiting';

        $message = is_array($selection) &&
            is_string($selection['message'] ?? null)
                ? $selection['message']
                : null;

        if ($hasImage) {
            $state = 'ready';
            $message = null;
        } elseif ($state === 'ready') {
            $state = 'failed';
            $message = 'The selected image is unavailable.';
        }

        if (! in_array(
            $state,
            ['waiting', 'ready', 'cancelled', 'failed'],
            true,
        )) {
            $state = 'failed';
            $message = 'The image selection result was invalid.';
        }

        return response()
            ->json([
                'state' => $state,
                'ready' => $state === 'ready',
                'cancelled' => $state === 'cancelled',
                'message' => $message,
            ])
            ->withHeaders([
                'Cache-Control' => 'private, no-store',
            ]);
    }

    public function preview(): JsonResponse
    {
        $path = Cache::get('pending_photo_path');

        if (! is_string($path) || trim($path) === '') {
            return response()->json([
                'message' => 'The selected image is unavailable.',
            ], 404);
        }

        try {
            $payload = NativeImagePayload::fromPath($path);
        } catch (RuntimeException) {
            return response()->json([
                'message' => 'The selected image is unavailable.',
            ], 404);
        }

        return response()
            ->json($payload)
            ->withHeaders([
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ]);
    }

    public function pick()
    {
        Cache::forget('pending_photo_path');
        Cache::forget('debug_media_files');
        Cache::forever('pending_photo_selection', [
            'state' => 'waiting',
            'message' => null,
        ]);

        Camera::pickImages('images', false)->start();

        return redirect()->route('camera.waiting');
    }
}
