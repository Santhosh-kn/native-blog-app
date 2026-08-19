<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\RegistrationController;
use App\Http\Controllers\LoginController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\UnlockController;
use App\Http\Controllers\CameraController;
use App\Http\Controllers\SystemController;
use App\Http\Controllers\MicrophoneController;
use App\Http\Controllers\PushController;

Route::get('/register', [RegistrationController::class, 'create'])->name('register');
Route::post('/register', [RegistrationController::class, 'store'])->name('register.store');

Route::get('/login', [LoginController::class, 'create'])->name('login');
Route::post('/login', [LoginController::class, 'store'])->name('login.store');
Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
Route::get('/logout', function () {
    return redirect('/login');
});

// Route::middleware('auth')->group(function () {
Route::middleware('device.unlocked')->group(function () {
    Route::get('/', [HomeController::class, 'index'])->name('home');

    Route::get('/users', [UserController::class, 'index'])->name('users.index');
    Route::get('/users/{id}/edit', [UserController::class, 'edit'])->name('users.edit');
    Route::put('/users/{id}', [UserController::class, 'update'])->name('users.update');
    Route::delete('/users/{id}', [UserController::class, 'destroy'])->name('users.destroy');

    Route::get('/posts', [PostController::class, 'index'])->name('posts.index');
    Route::get('/posts/create', [PostController::class, 'create'])->name('posts.create');
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
        $path = \Illuminate\Support\Facades\Cache::get('pending_photo_path');
        $mediaDebug = \Illuminate\Support\Facades\Cache::get('debug_media_files');

        return response()->json([
            'cached_path' => $path,
            'file_exists' => $path ? file_exists($path) : null,
            'media_debug' => $mediaDebug,
        ]);
    });

    Route::get('/debug/push-token', function () {
        return response()->json(['push_token' => auth()->user()->push_token]);
    });

    Route::post('/posts/{id}/export', [PostController::class, 'export'])->name('posts.export');

    Route::post('/browser/open', function () {
        \Native\Mobile\Facades\Browser::open('https://nativephp.com/mobile');
        return back();
    })->name('browser.open');

    Route::post('/system/open-settings', function () {
        \Native\Mobile\Facades\System::openAppSettings();
        return back();
    })->name('system.open-settings');


    Route::post('/system/open-settings', [SystemController::class, 'openSettings'])->name('system.open-settings');


    Route::get('/microphone', [MicrophoneController::class, 'index'])->name('microphone.index');
    Route::post('/microphone/start', [MicrophoneController::class, 'start'])->name('microphone.start');
    Route::post('/microphone/stop', [MicrophoneController::class, 'stop'])->name('microphone.stop');
    Route::get('/microphone/status', [MicrophoneController::class, 'status'])->name('microphone.status');


    Route::get('/push', [PushController::class, 'index'])->name('push.index');
    Route::post('/push/enroll', [PushController::class, 'enroll'])->name('push.enroll');
    Route::get('/push/enroll', function () {
        return redirect()->route('push.index');
    });
    Route::get('/push/status', [PushController::class, 'status'])->name('push.status');

    Route::post('/push/send-test', [PushController::class, 'sendTest'])->name('push.send-test');
    Route::get('/push/send-test', function () {
        return redirect()->route('push.index');
    });

    Route::post('/posts/{id}/share', [PostController::class, 'share'])->name('posts.share');

});
    
Route::get('/unlock', [UnlockController::class, 'show'])->name('unlock');
Route::post('/unlock', [UnlockController::class, 'confirm'])->name('unlock.confirm');
Route::post('/unlock/trigger-biometric', [UnlockController::class, 'triggerBiometric'])->name('unlock.trigger-biometric');
Route::get('/unlock/biometric-status', [UnlockController::class, 'biometricStatus'])->name('unlock.biometric-status');

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