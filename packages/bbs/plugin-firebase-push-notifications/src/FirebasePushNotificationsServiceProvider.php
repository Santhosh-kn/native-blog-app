<?php

namespace Bbs\FirebasePushNotifications;

use Illuminate\Support\ServiceProvider;

class FirebasePushNotificationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            FirebasePushNotifications::class,
            function () {
                return new FirebasePushNotifications();
            }
        );
    }

    public function boot(): void
    {
        //
    }
}