<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class NativeServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }

    /**
     * The NativePHP plugins to enable.
     *
     * Only plugins listed here will be compiled into your native builds.
     * This is a security measure to prevent transitive dependencies from
     * automatically registering plugins without your explicit consent.
     *
     * @return array<int, class-string<\Illuminate\Support\ServiceProvider>>
     */
    public function plugins(): array
    {
        return [
            \Native\Mobile\Providers\CameraServiceProvider::class,
            \Native\Mobile\Providers\NetworkServiceProvider::class,
            \Native\Mobile\Providers\FileServiceProvider::class,
            \Native\Mobile\Providers\DialogServiceProvider::class,
            \Native\Mobile\Providers\BrowserServiceProvider::class,
            \Native\Mobile\Providers\SystemServiceProvider::class,
            \Native\Mobile\Providers\DeviceServiceProvider::class,
            \Native\Mobile\Providers\MicrophoneServiceProvider::class,
            \Lumi\NativePush\PushServiceProvider::class,
            \Native\Mobile\Providers\ShareServiceProvider::class,
            \Bbs\Biometric\BiometricServiceProvider::class,
            \Bbs\FirebaseGoogleAuth\FirebaseGoogleAuthServiceProvider::class,
        ];
    }
}