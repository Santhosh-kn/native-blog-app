<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

class FirebaseGoogleAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_completed_google_authentication_creates_and_logs_in_a_local_user(): void
    {
        $requestId = (string) Str::uuid();
        $cacheKey = "firebase_google_auth_result:{$requestId}";

        Cache::put(
            $cacheKey,
            [
                'success' => true,
                'id_token' => 'test-firebase-id-token',
                'firebase_uid' => 'test-firebase-uid',
                'email' => 'google-user@example.com',
                'name' => 'Google User',
                'avatar_url' => 'https://example.com/avatar.jpg',
                'error' => null,
                'cancelled' => false,
            ],
            now()->addMinutes(2),
        );

        $response = $this
            ->withSession([
                'firebase_google_auth_request_id' => $requestId,
            ])
            ->getJson(
                route('google.status', [
                    'requestId' => $requestId,
                ]),
            );

        $response
            ->assertOk()
            ->assertJson([
                'status' => 'authenticated',
                'redirect' => route('home'),
            ]);

        $this->assertAuthenticated();

        $this->assertDatabaseHas('users', [
            'name' => 'Google User',
            'email' => 'google-user@example.com',
            'firebase_uid' => 'test-firebase-uid',
            'avatar_url' => 'https://example.com/avatar.jpg',
        ]);

        $this->assertNull(Cache::get($cacheKey));
    }
}