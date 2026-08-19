<?php

namespace Bbs\Biometric;

use Illuminate\Support\ServiceProvider;
use Bbs\Biometric\Commands\CopyAssetsCommand;

class BiometricServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Biometric::class, function () {
            return new Biometric();
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                CopyAssetsCommand::class,
            ]);
        }
    }
}