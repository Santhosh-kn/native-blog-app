<?php

use App\Http\Controllers\CameraController;
use App\Http\Controllers\GoogleAuthController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LoginController;
use App\Http\Controllers\MicrophoneController;
use App\Http\Controllers\NativePrintingController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\PushController;
use App\Http\Controllers\PushNotificationDeepLinkController;
use App\Http\Controllers\RegistrationController;
use App\Http\Controllers\SystemController;
use App\Http\Controllers\UnlockController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Native\Mobile\Facades\Browser;

Route::get('/register', [RegistrationController::class, 'create'])->name('register');
Route::post('/register', [RegistrationController::class, 'store'])->name('register.store');

Route::get('/login', [LoginController::class, 'create'])->name('login');
Route::post('/login', [LoginController::class, 'store'])->name('login.store');
Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
Route::get('/logout', function () {
    return redirect('/login');
});

Route::get('/push/open', [PushNotificationDeepLinkController::class, 'capture'])->name('push.deep-link.capture');

// Route::middleware('auth')->group(function () {
Route::middleware('device.unlocked')->group(function () {
    Route::get('/', [HomeController::class, 'index'])->name('home');

    Route::get('/users', [UserController::class, 'index'])->name('users.index');
    Route::get('/users/{id}/edit', [UserController::class, 'edit'])->name('users.edit');
    Route::put('/users/{id}', [UserController::class, 'update'])->name('users.update');
    Route::delete('/users/{id}', [UserController::class, 'destroy'])->name('users.destroy');

    Route::get('/posts', [PostController::class, 'index'])->name('posts.index');
    Route::get('/posts/create', [PostController::class, 'create'])->name('posts.create');
    Route::get('/posts/{id}/photo', [PostController::class, 'photo'])
        ->whereNumber('id')
        ->name('posts.photo');
    Route::post('/posts', [PostController::class, 'store'])->name('posts.store');
    Route::get('/posts/{id}/edit', [PostController::class, 'edit'])->name('posts.edit');
    Route::put('/posts/{id}', [PostController::class, 'update'])->name('posts.update');
    Route::delete('/posts/{id}', [PostController::class, 'destroy'])->name('posts.destroy');

    Route::get('/camera/capture', [CameraController::class, 'capture'])->name('camera.capture');
    Route::get('/camera/preview', [CameraController::class, 'preview'])->name('camera.preview');
    Route::get('/camera/waiting', [CameraController::class, 'waiting'])->name('camera.waiting');
    Route::get('/camera/status', [CameraController::class, 'status'])->name('camera.status');

    Route::get('/camera/pick', [CameraController::class, 'pick'])->name('camera.pick');

    Route::get('/debug/cache', function () {
        $path = Cache::get('pending_photo_path');
        $mediaDebug = Cache::get('debug_media_files');

        return response()->json([
            'cached_path' => $path,
            'file_exists' => $path ? file_exists($path) : null,
            'media_debug' => $mediaDebug,
        ]);
    });

    Route::post('/posts/{id}/export', [PostController::class, 'export'])->name('posts.export');

    Route::post('/browser/open', function () {
        Browser::open('https://nativephp.com/mobile');

        return back();
    })->name('browser.open');

    Route::post('/system/open-settings', [SystemController::class, 'openSettings'])->name('system.open-settings');

    Route::get('/microphone', [MicrophoneController::class, 'index'])->name('microphone.index');
    Route::post('/microphone/start', [MicrophoneController::class, 'start'])->name('microphone.start');
    Route::post('/microphone/stop', [MicrophoneController::class, 'stop'])->name('microphone.stop');
    Route::get('/microphone/status', [MicrophoneController::class, 'status'])->name('microphone.status');

    Route::get('/push/resume', [PushNotificationDeepLinkController::class, 'resume'])
        ->name('push.deep-link.resume');

    Route::prefix('push')->name('push.')->group(function () {
        Route::get('/', [PushController::class, 'index'])->name('index');
        Route::post('/enroll', [PushController::class, 'enroll'])->name('enroll');
        Route::post('/sync', [PushController::class, 'sync'])->name('sync');
        Route::get('/status', [PushController::class, 'status'])->name('status');
    });

    Route::prefix('native-printing')
        ->name('native-printing.')
        ->group(function () {
            Route::get(
                '/',
                [NativePrintingController::class, 'index'],
            )->name('index');

            Route::get(
                '/availability',
                [NativePrintingController::class, 'availability'],
            )->name('availability');

            Route::post(
                '/preview',
                [NativePrintingController::class, 'preview'],
            )->name('preview');

            Route::post(
                '/print',
                [NativePrintingController::class, 'print'],
            )->name('print');

            Route::get(
                '/status/{requestId}',
                [NativePrintingController::class, 'status'],
            )
                ->whereUuid('requestId')
                ->name('status');
        });
    Route::post('/posts/{id}/share', [PostController::class, 'share'])->name('posts.share');
});

Route::get('/unlock', [UnlockController::class, 'show'])->name('unlock');
Route::post('/unlock', [UnlockController::class, 'confirm'])->name('unlock.confirm');
Route::post('/unlock/trigger-biometric', [UnlockController::class, 'triggerBiometric'])->name('unlock.trigger-biometric');
Route::get('/unlock/biometric-status', [UnlockController::class, 'biometricStatus'])->name('unlock.biometric-status');

Route::post('/auth/google/start', [GoogleAuthController::class, 'start'])->name('google.start');
Route::get('/auth/google/status/{requestId}', [GoogleAuthController::class, 'status'])->whereUuid('requestId')->name('google.status');

// use Illuminate\Support\Facades\Route;
// use App\Http\Controllers\RegistrationController;
// use App\Http\Controllers\LoginController;
// use App\Http\Controllers\HomeController;
// use App\Http\Controllers\UserController;
// use App\Http\Controllers\PostController;
// use App\Http\Controllers\UnlockController;
// use App\Http\Controllers\CameraController;
// use App\Http\Controllers\GoogleAuthController;
// use Santhosh\FirebaseGoogleAuth\Facades\FirebaseGoogleAuth;

// Route::get('/register', [RegistrationController::class, 'create'])->name('register');
// Route::post('/register', [RegistrationController::class, 'store'])->name('register.store');

// Route::get('/login', [LoginController::class, 'create'])->name('login');
// Route::post('/login', [LoginController::class, 'store'])->name('login.store');
// Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
// Route::get('/logout', function () {
//     return redirect('/login');
// });

// Route::get('/unlock', [UnlockController::class, 'show'])->name('unlock');
// Route::post('/unlock', [UnlockController::class, 'confirm'])->name('unlock.confirm');
// Route::post('/auth/google/callback', [GoogleAuthController::class, 'verify'])->name('google.callback');

// Route::middleware('api.auth')->group(function () {
//     Route::get('/', [HomeController::class, 'index'])->name('home');

//     Route::get('/users/{id}/edit', [UserController::class, 'edit'])->name('users.edit');
//     Route::put('/users/{id}', [UserController::class, 'update'])->name('users.update');
//     Route::delete('/users/{id}', [UserController::class, 'destroy'])->name('users.destroy');

//     Route::get('/posts', [PostController::class, 'index'])->name('posts.index');
//     Route::get('/posts/create', [PostController::class, 'create'])->name('posts.create');
//     Route::post('/posts', [PostController::class, 'store'])->name('posts.store');
//     Route::get('/posts/{id}/edit', [PostController::class, 'edit'])->name('posts.edit');
//     Route::put('/posts/{id}', [PostController::class, 'update'])->name('posts.update');
//     Route::delete('/posts/{id}', [PostController::class, 'destroy'])->name('posts.destroy');

//     Route::post('/camera/capture', [CameraController::class, 'capture'])->name('camera.capture');
// });

// Route::get('/firebase-test', function () {
//     return response()->json(
//         FirebaseGoogleAuth::execute([
//             'option1' => 'Hello from Laravel'
//         ])
//     );
// });
