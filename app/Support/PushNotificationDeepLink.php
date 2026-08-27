<?php

namespace App\Support;

use App\Models\Post;
use App\Models\User;
use Illuminate\Contracts\Session\Session;

final class PushNotificationDeepLink
{
    public const SESSION_KEY = 'push_notification.pending_destination';

    private const VERSION = 1;

    /**
     * @return array{
     *     version: int,
     *     destination: string,
     *     resource_id?: int
     * }|null
     */
    public function normalize(mixed $payload): ?array
    {
        if (! is_array($payload) || ($payload['version'] ?? null) !== self::VERSION) {
            return null;
        }
        $destination = $payload['destination'] ?? null;

        if (! is_string($destination)) {
            return null;
        }
        if (in_array($destination, ['home', 'posts', 'post_create', 'push_settings'], true)) {
            return ['version' => self::VERSION, 'destination' => $destination];
        }
        if ($destination !== 'post_edit') {
            return null;
        }
        $resourceId = $payload['resource_id'] ?? null;
        if (! is_int($resourceId) || $resourceId < 1) {
            return null;
        }

        return ['version' => self::VERSION, 'destination' => 'post_edit', 'resource_id' => $resourceId];
    }

    public function store(Session $session, array $destination): bool
    {
        $normalized = $this->normalize($destination);
        if ($normalized === null) {
            $this->forget($session);

            return false;
        }

        $session->put(self::SESSION_KEY, $normalized);

        return true;
    }

    public function hasPending(Session $session): bool
    {
        return $session->has(self::SESSION_KEY);
    }

    /**
     * @return array{
     *     version: int,
     *     destination: string,
     *     resource_id?: int
     * }|null
     */
    public function pull(Session $session): ?array
    {
        return $this->normalize($session->pull(self::SESSION_KEY));
    }

    public function forget(Session $session): void
    {
        $session->forget(self::SESSION_KEY);
    }

    /**
     * @param array{
     *     version: int,
     *     destination: string,
     *     resource_id?: int
     * } $destination
     * @return array{
     *     name: string,
     *     parameters: array<string, int>
     * }|null
     */
    public function routeFor(User $user, array $destination): ?array
    {
        $normalized = $this->normalize($destination);
        if ($normalized === null) {
            return null;
        }

        return match ($normalized['destination']) {
            'home' => ['name' => 'home', 'parameters' => []],
            'posts' => ['name' => 'posts.index', 'parameters' => []],
            'post_create' => ['name' => 'posts.create', 'parameters' => []],
            'push_settings' => ['name' => 'push.index', 'parameters' => []],
            'post_edit' => $this->authorizedPostRoute($user, $normalized['resource_id']),
            default => null,
        };
    }

    /**
     * @return array{
     *     name: string,
     *     parameters: array<string, int>
     * }|null
     */
    private function authorizedPostRoute(User $user, int $resourceId): ?array
    {
        $post = Post::query()->find($resourceId);
        if ($post === null || ! $user->can('update', $post)) {
            return null;
        }

        return ['name' => 'posts.edit', 'parameters' => ['id' => $post->id]];
    }
}
