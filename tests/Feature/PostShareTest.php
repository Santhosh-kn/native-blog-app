<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Native\Mobile\Facades\Share;
use Tests\TestCase;

class PostShareTest extends TestCase
{
    use RefreshDatabase;

    public function test_native_share_redirects_to_posts_index(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();

        $post = $user->posts()->create([
            'title' => 'Share redirect test',
            'slug' => 'share-redirect-test',
            'body' => 'Post content for the native share test.',
            'published_at' => now(),
        ]);

        $share = \Mockery::mock();

        $share->shouldReceive('file')
            ->once()
            ->with(
                $post->title,
                'Check out this post',
                \Mockery::on(
                    static fn (mixed $path): bool => is_string($path) &&
                        is_file($path)
                ),
            );

        Share::swap($share);

        $response = $this
            ->actingAs($user)
            ->withSession([
                'device_unlocked' => true,
            ])
            ->post(
                route('posts.share', [
                    'id' => $post->id,
                ])
            );

        $response->assertRedirect(
            route('posts.index')
        );

        Storage::disk('local')->assertExists(
            'exports/share-redirect-test.txt'
        );
    }
}
