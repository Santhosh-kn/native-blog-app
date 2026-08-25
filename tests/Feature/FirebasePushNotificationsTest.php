<?php

namespace Tests\Feature;

use App\Models\User;
use Bbs\FirebasePushNotifications\Events\FirebasePushNotificationsCompleted;
use Bbs\FirebasePushNotifications\Facades\FirebasePushNotifications;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

class FirebasePushNotificationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_native_token_completion_is_cached_by_request_id(): void
    {
        $requestId = (string) Str::uuid();

        event(new FirebasePushNotificationsCompleted(
            success: true,
            error: null,
            id: $requestId,
        ));

        $this->assertSame(
            [
                'success' => true,
                'error' => null,
                'id' => $requestId,
            ],
            Cache::get(
                "firebase_push_token_result:{$requestId}"
            ),
        );
    }

    public function test_status_remains_pending_until_native_result_arrives(): void
    {
        $user = User::factory()->create();
        $requestId = (string) Str::uuid();

        $response = $this->actingAs($user)
            ->withSession(['device_unlocked' => true, 'firebase_push_token_request_id' => $requestId])
            ->getJson(route('push.status', ['id' => $requestId]));

        $response->assertStatus(202)
            ->assertJson(['pending' => true])
            ->assertJsonStructure(['pending', 'permission'])
            ->assertSessionHas('firebase_push_token_request_id', $requestId);
    }

    public function test_completed_token_is_saved_to_the_authenticated_user(): void
    {
        $user = User::factory()->create(['push_token' => null]);

        $requestId = (string) Str::uuid();
        $cacheKey = "firebase_push_token_result:{$requestId}";

        Cache::put(
            $cacheKey,
            [
                'success' => true,
                'error' => null,
                'id' => $requestId,
            ],
            now()->addMinutes(2),
        );

        FirebasePushNotifications::shouldReceive('checkPermission')
            ->once()
            ->andReturn((object) ['status' => 'granted']);

        FirebasePushNotifications::shouldReceive('getStoredToken')
            ->once()
            ->andReturn((object) [
                'available' => true,
                'token' => 'test-fcm-token',
            ]);

        $response = $this->actingAs($user)
            ->withSession([
                'device_unlocked' => true,
                'firebase_push_token_request_id' => $requestId,
            ])
            ->getJson(
                route('push.status', ['id' => $requestId])
            );

        $response->assertOk()
            ->assertExactJson([
                'pending' => false,
                'success' => true,
                'permission' => 'granted',
                'registered' => true,
                'error' => null,
            ])
            ->assertSessionMissing('firebase_push_token_request_id');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'push_token' => 'test-fcm-token',
        ]);

        $this->assertNull(Cache::get($cacheKey));
    }

    public function test_status_rejects_a_request_id_not_owned_by_the_session(): void
    {
        $user = User::factory()->create(['push_token' => null]);

        $sessionRequestId = (string) Str::uuid();
        $differentRequestId = (string) Str::uuid();
        $cacheKey = "firebase_push_token_result:{$differentRequestId}";

        Cache::put(
            $cacheKey,
            ['success' => true, 'error' => null, 'id' => $differentRequestId],
            now()->addMinutes(2),
        );

        $response = $this->actingAs($user)
            ->withSession(['device_unlocked' => true, 'firebase_push_token_request_id' => $sessionRequestId])
            ->getJson(route('push.status', ['id' => $differentRequestId]));

        $response
            ->assertForbidden()
            ->assertJson(['pending' => false, 'success' => false, 'message' => 'This push registration request is not valid.'])
            ->assertSessionHas('firebase_push_token_request_id', $sessionRequestId);

        $this->assertNull($user->fresh()->push_token);
        $this->assertNotNull(Cache::get($cacheKey));
    }

    public function test_enroll_starts_permission_and_token_requests(): void
    {
        $user = User::factory()->create();
        $capturedRequestId = null;

        FirebasePushNotifications::shouldReceive('requestPermission')
            ->once()
            ->andReturn((object) ['requested' => true, 'status' => 'pending']);

        FirebasePushNotifications::shouldReceive('getToken')
            ->once()
            ->with(\Mockery::on(function (mixed $requestId) use (&$capturedRequestId): bool {
                if (! is_string($requestId) || ! Str::isUuid($requestId)) {
                    return false;
                }
                $capturedRequestId = $requestId;

                return true;
            }))
            ->andReturn((object) ['started' => true]);

        $response = $this->actingAs($user)
            ->withSession(['device_unlocked' => true])
            ->post(route('push.enroll'));

        $this->assertNotNull($capturedRequestId);

        $response->assertRedirect(route('push.index', ['request_id' => $capturedRequestId]))
            ->assertSessionHas('firebase_push_token_request_id', $capturedRequestId)
            ->assertSessionHas('status', 'Push registration started.');
    }

    public function test_failed_token_result_is_not_saved_to_the_user(): void
    {
        $user = User::factory()->create(['push_token' => null]);
        $requestId = (string) Str::uuid();
        $cacheKey = "firebase_push_token_result:{$requestId}";

        Cache::put(
            $cacheKey,
            ['success' => false, 'error' => 'Firebase token request failed.', 'id' => $requestId],
            now()->addMinutes(2)
        );

        $response = $this
            ->actingAs($user)
            ->withSession(['device_unlocked' => true, 'firebase_push_token_request_id' => $requestId])
            ->getJson(route('push.status', ['id' => $requestId]));

        $response->assertOk()
            ->assertJson(['pending' => false, 'success' => false, 'error' => 'Firebase token request failed.'])
            ->assertSessionMissing('firebase_push_token_request_id');

        $this->assertNull($user->fresh()->push_token);
        $this->assertNull(Cache::get($cacheKey));
    }

    public function test_stored_refreshed_token_is_synchronized_to_the_enrolled_user(): void
    {
        $user = User::factory()->create([
            'push_token' => 'previous-fcm-token',
        ]);

        FirebasePushNotifications::shouldReceive('getStoredToken')
            ->once()
            ->andReturn((object) [
                'available' => true,
                'token' => 'refreshed-fcm-token',
            ]);

        $response = $this
            ->actingAs($user)
            ->withSession(['device_unlocked' => true])
            ->postJson(route('push.sync'));

        $response
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'updated' => true,
                'status' => 'updated',
            ]);

        $this->assertSame(
            'refreshed-fcm-token',
            $user->fresh()->push_token,
        );
    }

    public function test_stored_token_is_not_read_for_a_user_who_never_enrolled(): void
    {
        $user = User::factory()->create([
            'push_token' => null,
        ]);

        FirebasePushNotifications::shouldReceive('getStoredToken')
            ->never();

        $response = $this
            ->actingAs($user)
            ->withSession(['device_unlocked' => true])
            ->postJson(route('push.sync'));

        $response
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'updated' => false,
                'status' => 'not_enrolled',
            ]);

        $this->assertNull($user->fresh()->push_token);
    }

    public function test_matching_stored_token_does_not_write_the_user_again(): void
    {
        $user = User::factory()->create([
            'push_token' => 'current-fcm-token',
        ]);

        FirebasePushNotifications::shouldReceive('getStoredToken')
            ->once()
            ->andReturn((object) [
                'available' => true,
                'token' => 'current-fcm-token',
            ]);

        $response = $this
            ->actingAs($user)
            ->withSession(['device_unlocked' => true])
            ->postJson(route('push.sync'));

        $response
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'updated' => false,
                'status' => 'current',
            ]);

        $this->assertSame(
            'current-fcm-token',
            $user->fresh()->push_token,
        );
    }
}
