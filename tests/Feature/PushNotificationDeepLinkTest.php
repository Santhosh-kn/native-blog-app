<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\User;
use App\Support\PushNotificationDeepLink;
use Bbs\FirebasePushNotifications\Facades\FirebasePushNotifications;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PushNotificationDeepLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_native_tap_is_captured_for_safe_continuation(): void
    {
        $tapId = (string) Str::uuid();
        $destination = [
            'version' => 1,
            'destination' => 'posts',
        ];

        FirebasePushNotifications::shouldReceive('getPendingNotification')
            ->once()
            ->with($tapId)
            ->andReturn((object) [
                'available' => true,
                'payload' => $destination,
            ]);

        $response = $this->get(route('push.deep-link.capture', ['tap' => $tapId]));

        $response
            ->assertRedirect(route('push.deep-link.resume'))
            ->assertSessionHas(PushNotificationDeepLink::SESSION_KEY, $destination);
    }

    public function test_invalid_tap_id_never_reaches_the_native_bridge(): void
    {
        FirebasePushNotifications::shouldReceive('getPendingNotification')->never();

        $response = $this
            ->withSession([PushNotificationDeepLink::SESSION_KEY => ['version' => 1, 'destination' => 'posts']])
            ->get(route('push.deep-link.capture', ['tap' => 'not-a-uuid']));

        $response
            ->assertRedirect(route('home'))
            ->assertSessionMissing(PushNotificationDeepLink::SESSION_KEY);
    }

    public function test_unsupported_destination_falls_back_to_home(): void
    {
        $tapId = (string) Str::uuid();

        FirebasePushNotifications::shouldReceive('getPendingNotification')
            ->once()
            ->with($tapId)
            ->andReturn((object) [
                'available' => true,
                'payload' => ['version' => 1, 'destination' => 'https://evil.example/redirect'],
            ]);

        $response = $this->get(route('push.deep-link.capture', ['tap' => $tapId]));

        $response
            ->assertRedirect(route('home'))
            ->assertSessionMissing(PushNotificationDeepLink::SESSION_KEY);
    }

    public function test_pending_destination_survives_authentication_redirect(): void
    {
        $destination = ['version' => 1, 'destination' => 'posts'];

        $response = $this
            ->withSession([PushNotificationDeepLink::SESSION_KEY => $destination])
            ->get(route('push.deep-link.resume'));

        $response
            ->assertRedirect(route('login'))
            ->assertSessionHas(PushNotificationDeepLink::SESSION_KEY, $destination);
    }

    public function test_pending_destination_survives_biometric_redirect(): void
    {
        $user = User::factory()->create();
        $destination = ['version' => 1, 'destination' => 'posts'];

        $response = $this
            ->actingAs($user)
            ->withSession([PushNotificationDeepLink::SESSION_KEY => $destination])
            ->get(route('push.deep-link.resume'));

        $response
            ->assertRedirect(route('unlock'))
            ->assertSessionHas(PushNotificationDeepLink::SESSION_KEY, $destination);
    }

    public function test_unlocked_request_resumes_a_pending_destination(): void
    {
        $user = User::factory()->create();
        $destination = [
            'version' => 1,
            'destination' => 'posts',
        ];

        $response = $this
            ->actingAs($user)
            ->withSession(['device_unlocked' => true, PushNotificationDeepLink::SESSION_KEY => $destination])
            ->get(route('home'));

        $response
            ->assertRedirect(route('push.deep-link.resume'))
            ->assertSessionHas(PushNotificationDeepLink::SESSION_KEY, $destination);
    }

    public function test_allowed_destination_is_opened_and_consumed_once(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->withSession([
                'device_unlocked' => true,
                PushNotificationDeepLink::SESSION_KEY => ['version' => 1, 'destination' => 'posts'],
            ])
            ->get(route('push.deep-link.resume'));

        $response
            ->assertRedirect(route('posts.index'))
            ->assertSessionMissing(PushNotificationDeepLink::SESSION_KEY);
    }

    public function test_owned_post_destination_passes_authorization(): void
    {
        $user = User::factory()->create();
        $post = $this->createPost($user);

        $response = $this
            ->actingAs($user)
            ->withSession([
                'device_unlocked' => true,
                PushNotificationDeepLink::SESSION_KEY => ['version' => 1, 'destination' => 'post_edit', 'resource_id' => $post->id],
            ])
            ->get(route('push.deep-link.resume'));

        $response->assertRedirect(route('posts.edit', ['id' => $post->id]))
            ->assertSessionMissing(PushNotificationDeepLink::SESSION_KEY);
    }

    public function test_unauthorized_post_destination_falls_back_to_home(): void
    {
        $user = User::factory()->create();
        $owner = User::factory()->create();
        $post = $this->createPost($owner);

        $response = $this
            ->actingAs($user)
            ->withSession([
                'device_unlocked' => true,
                PushNotificationDeepLink::SESSION_KEY => ['version' => 1, 'destination' => 'post_edit', 'resource_id' => $post->id],
            ])
            ->get(route('push.deep-link.resume'));

        $response
            ->assertRedirect(route('home'))
            ->assertSessionMissing(PushNotificationDeepLink::SESSION_KEY);
    }

    public function test_missing_post_destination_falls_back_to_home(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->withSession([
                'device_unlocked' => true,
                PushNotificationDeepLink::SESSION_KEY => ['version' => 1, 'destination' => 'post_edit', 'resource_id' => 999999],
            ])
            ->get(route('push.deep-link.resume'));

        $response
            ->assertRedirect(route('home'))
            ->assertSessionMissing(PushNotificationDeepLink::SESSION_KEY);
    }

    public function test_missing_pending_destination_falls_back_to_home(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->withSession(['device_unlocked' => true])
            ->get(route('push.deep-link.resume'));

        $response->assertRedirect(route('home'));
    }

    private function createPost(User $user): Post
    {
        return Post::query()->create([
            'user_id' => $user->id,
            'title' => 'Notification post',
            'slug' => 'notification-post-'.Str::lower(Str::random(8)),
            'body' => 'Notification deep-link authorization test.',
            'published_at' => now(),
        ]);
    }
}
