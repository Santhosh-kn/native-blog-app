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
    }
}