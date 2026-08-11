<?php

namespace Santhosh\FirebaseGoogleAuth;

use Illuminate\Support\ServiceProvider;
use Santhosh\FirebaseGoogleAuth\Commands\CopyAssetsCommand;

class FirebaseGoogleAuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(FirebaseGoogleAuth::class, function () {
            return new FirebaseGoogleAuth();
        });
    }

    public function boot(): void
    {
        // Register plugin hook commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                CopyAssetsCommand::class,
            ]);
        }
    }
}