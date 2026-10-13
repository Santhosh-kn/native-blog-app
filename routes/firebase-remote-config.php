<?php

use App\Http\Controllers\FirebaseRemoteConfigController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'device.unlocked'])
    ->prefix('firebase-remote-config')->name('firebase-remote-config.')
    ->group(function () {
        Route::get('/', [FirebaseRemoteConfigController::class, 'index'])->name('index');
        Route::get('/snapshot', [FirebaseRemoteConfigController::class, 'snapshot'])->name('snapshot');
        Route::post('/start', [FirebaseRemoteConfigController::class, 'start'])->name('start');
        Route::get('/status/{id}', [FirebaseRemoteConfigController::class, 'status'])->whereUuid('id')->name('status');
    });
