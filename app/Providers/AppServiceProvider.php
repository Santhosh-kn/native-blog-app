<?php

namespace App\Providers;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use App\Models\Post;
use App\Policies\PostPolicy;
use Native\Mobile\Events\Camera\PhotoTaken;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Cache;
use Native\Mobile\Events\Gallery\MediaSelected;
use Bbs\Biometric\Events\BiometricCompleted;
use Bbs\FirebaseGoogleAuth\Events\FirebaseGoogleAuthCompleted;
use Illuminate\Support\Facades\Log;
class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Post::class, PostPolicy::class);
        Event::listen(PhotoTaken::class, function (PhotoTaken $event) {
            Cache::forever('pending_photo_path', $event->path);
        });
        Event::listen(MediaSelected::class, function (MediaSelected $event) {
            Cache::forever('debug_media_files', json_encode([
                'success' => $event->success ?? null,
                'files' => $event->files ?? null,
                'count' => $event->count ?? null,
            ]));

            $files = $event->files ?? [];

            if (! empty($files)) {
                $first = $files[0];
                $path = is_array($first) ? ($first['path'] ?? null) : (is_string($first) ? $first : null);

                if ($path) {
                    Cache::forever('pending_photo_path', $path);
                }
            }
        });

        Event::listen(BiometricCompleted::class, function (BiometricCompleted $event) {
            \Log::info('BiometricCompleted event received', [
                'success' => $event->success,
                'id' => $event->id,
            ]);

            Cache::forever('biometric_result', [
                'success' => $event->success,
                'id' => $event->id,
            ]);

            \Log::info('Cached biometric_result', [
                'stored' => Cache::get('biometric_result'),
            ]);
        });

        Event::listen(
            FirebaseGoogleAuthCompleted::class,
            function (FirebaseGoogleAuthCompleted $event) {
                if (! $event->id) {
                    Log::warning('Google authentication result received without a request ID');

                    return;
                }

                Cache::put(
                    "firebase_google_auth_result:{$event->id}",
                    [
                        'success' => $event->success,
                        'id_token' => $event->idToken,
                        'firebase_uid' => $event->uid,
                        'email' => $event->email,
                        'name' => $event->name,
                        'avatar_url' => $event->photoUrl,
                        'error' => $event->error,
                        'cancelled' => $event->cancelled,
                    ],
                    now()->addMinutes(2),
                );

                Log::info('Firebase Google authentication result received', [
                    'success' => $event->success,
                    'request_id' => $event->id,
                    'cancelled' => $event->cancelled,
                ]);
            },
        );
    }
}