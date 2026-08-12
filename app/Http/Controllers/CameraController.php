<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Cache;
use Native\Mobile\Facades\Camera;

class CameraController extends Controller
{
    public function capture()
    {
        Cache::forget('pending_photo_path');

        Camera::getPhoto()->start();

        return redirect()->route('camera.waiting');
    }

    public function waiting()
    {
        return view('camera-waiting');
    }

    public function status()
    {
        return response()->json([
            'ready' => (bool) Cache::get('pending_photo_path'),
        ]);
    }

    public function preview()
    {
        $path = Cache::get('pending_photo_path');

        if (! $path || ! file_exists($path)) {
            abort(404);
        }

        return response()->file($path);
    }

    public function pick()
    {
        Cache::forget('pending_photo_path');
        Cache::forget('debug_media_files');

        Camera::pickImages('images', false)->start();

        return redirect()->route('camera.waiting');
    }
}