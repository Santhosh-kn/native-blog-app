<?php

namespace App\Providers;

use Bbs\Biometric\BiometricServiceProvider;
use Bbs\FirebaseGoogleAuth\FirebaseGoogleAuthServiceProvider;
use Bbs\FirebasePushNotifications\FirebasePushNotificationsServiceProvider;
use Bbs\NativeDocumentPicker\NativeDocumentPickerServiceProvider;
use Bbs\NativePrinting\NativePrintingServiceProvider;
use Illuminate\Support\ServiceProvider;
use Native\Mobile\Providers\BrowserServiceProvider;
use Native\Mobile\Providers\CameraServiceProvider;
use Native\Mobile\Providers\MicrophoneServiceProvider;
use Native\Mobile\Providers\NetworkServiceProvider;
use Native\Mobile\Providers\ShareServiceProvider;

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
     * @return array<int, class-string<ServiceProvider>>
     */
    public function plugins(): array
    {
        return [
            CameraServiceProvider::class,
            NetworkServiceProvider::class,
            BrowserServiceProvider::class,
            MicrophoneServiceProvider::class,
            ShareServiceProvider::class,
            BiometricServiceProvider::class,
            FirebaseGoogleAuthServiceProvider::class,
            FirebasePushNotificationsServiceProvider::class,
            NativeDocumentPickerServiceProvider::class,
            NativePrintingServiceProvider::class,
        ];
    }
}
