<?php

use App\Http\Controllers\NativeMediaOptimizerController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'device.unlocked'])
    ->prefix('native-media-optimizer')
    ->name('native-media-optimizer.')
    ->group(function (): void {
        $controller = NativeMediaOptimizerController::class;
        Route::get('/', [$controller, 'index'])->name('index');
        Route::get('/availability', [$controller, 'availability'])->name('availability');
        Route::post('/pick', [$controller, 'pick'])->name('pick');
        Route::get('/pick-status/{id}', [$controller, 'pickStatus'])->whereUuid('id')->name('pick-status');
        Route::post('/start', [$controller, 'start'])->name('start');
        Route::get('/status/{id}', [$controller, 'status'])->whereUuid('id')->name('status');
        Route::post('/cancel/{id}', [$controller, 'cancel'])->whereUuid('id')->name('cancel');
        Route::get('/preview/{id}', [$controller, 'preview'])->whereUuid('id')->name('preview');
        Route::post('/share/{id}', [$controller, 'share'])->whereUuid('id')->name('share');
        Route::post('/delete/{id}', [$controller, 'delete'])->whereUuid('id')->name('delete');
    });
