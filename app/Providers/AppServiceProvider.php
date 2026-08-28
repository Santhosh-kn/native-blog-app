<?php

namespace App\Providers;

use App\Models\Post;
use App\Policies\PostPolicy;
use Bbs\Biometric\Events\BiometricCompleted;
use Bbs\FirebaseGoogleAuth\Events\FirebaseGoogleAuthCompleted;
use Bbs\FirebasePushNotifications\Events\FirebasePushNotificationsCompleted;
use Bbs\NativePrinting\Events\NativePrintingStateChanged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Native\Mobile\Events\Camera\PhotoCancelled;
use Native\Mobile\Events\Camera\PhotoTaken;
use Native\Mobile\Events\Gallery\MediaSelected;

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
            Cache::forever('pending_photo_selection', [
                'state' => 'ready',
                'message' => null,
            ]);
        });

        Event::listen(
            PhotoCancelled::class,
            function (PhotoCancelled $event) {
                Cache::forget('pending_photo_path');
                Cache::forever('pending_photo_selection', [
                    'state' => 'cancelled',
                    'message' => 'No image was selected.',
                ]);
            },
        );

        Event::listen(MediaSelected::class, function (MediaSelected $event) {
            Cache::forever('debug_media_files', json_encode([
                'success' => $event->success,
                'files' => $event->files,
                'count' => $event->count,
                'cancelled' => $event->cancelled,
                'error' => $event->error,
            ]));

            if ($event->cancelled) {
                Cache::forget('pending_photo_path');
                Cache::forever('pending_photo_selection', [
                    'state' => 'cancelled',
                    'message' => 'No image was selected.',
                ]);

                return;
            }

            if (! $event->success) {
                Cache::forget('pending_photo_path');
                Cache::forever('pending_photo_selection', [
                    'state' => 'failed',
                    'message' => 'The gallery could not return an image.',
                ]);

                return;
            }

            $first = $event->files[0] ?? null;
            $path = is_array($first)
                ? ($first['path'] ?? null)
                : (is_string($first) ? $first : null);

            if (is_string($path) && trim($path) !== '') {
                Cache::forever('pending_photo_path', $path);
                Cache::forever('pending_photo_selection', [
                    'state' => 'ready',
                    'message' => null,
                ]);

                return;
            }

            Cache::forget('pending_photo_path');
            Cache::forever('pending_photo_selection', [
                'state' => 'failed',
                'message' => 'The selected gallery image was unavailable.',
            ]);
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
        Event::listen(
            FirebasePushNotificationsCompleted::class,
            function (FirebasePushNotificationsCompleted $event) {
                if (! $event->id) {
                    Log::warning(
                        'Firebase push-token result received without a request ID'
                    );

                    return;
                }

                Cache::put(
                    "firebase_push_token_result:{$event->id}",
                    [
                        'success' => $event->success,
                        'error' => $event->error,
                        'id' => $event->id,
                    ],
                    now()->addMinutes(2),
                );

                Log::info('Firebase push-token result received', [
                    'success' => $event->success,
                    'request_id' => $event->id,
                ]);
            },
        );

        Event::listen(
            NativePrintingStateChanged::class,
            function (NativePrintingStateChanged $event) {
                $allowedActions = [
                    'preview',
                    'print',
                ];

                $allowedStatuses = [
                    'accepted',
                    'presented',
                    'closed',
                    'submitted',
                    'blocked',
                    'cancelled',
                    'completed',
                    'failed',
                ];

                if (
                    ! Str::isUuid($event->request_id) ||
                    ! in_array(
                        $event->action,
                        $allowedActions,
                        true,
                    ) ||
                    ! in_array(
                        $event->status,
                        $allowedStatuses,
                        true,
                    )
                ) {
                    Log::warning(
                        'Invalid native printing state event received',
                        [
                            'valid_request_id' => Str::isUuid($event->request_id),
                            'action' => Str::limit(
                                $event->action,
                                50,
                                '',
                            ),
                            'status' => Str::limit(
                                $event->status,
                                50,
                                '',
                            ),
                        ],
                    );

                    return;
                }

                Cache::put(
                    "native_printing_result:{$event->request_id}",
                    [
                        'request_id' => $event->request_id,
                        'action' => $event->action,
                        'status' => $event->status,
                        'job_id' => $event->job_id,
                        'error_code' => $event->error_code,
                        'error_message' => $event->error_message,
                    ],
                    now()->addMinutes(2),
                );

                Log::info(
                    'Native printing state received',
                    [
                        'request_id' => $event->request_id,
                        'action' => $event->action,
                        'status' => $event->status,
                        'error_code' => $event->error_code,
                    ],
                );
            },
        );
    }
}
