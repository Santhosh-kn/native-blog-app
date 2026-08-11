<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\RegistrationController;
use App\Http\Controllers\LoginController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\PostController;

Route::get('/register', [RegistrationController::class, 'create'])->name('register');
Route::post('/register', [RegistrationController::class, 'store'])->name('register.store');

Route::get('/login', [LoginController::class, 'create'])->name('login');
Route::post('/login', [LoginController::class, 'store'])->name('login.store');
Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
Route::get('/logout', function () {
    return redirect('/login');
});

Route::middleware('auth')->group(function () {
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

    Route::post('/camera/capture', [CameraController::class, 'capture'])->name('camera.capture');
});


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