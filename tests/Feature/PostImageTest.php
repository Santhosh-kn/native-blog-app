<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Native\Mobile\Events\Camera\PhotoCancelled;
use Native\Mobile\Events\Gallery\MediaSelected;
use Tests\TestCase;

class PostImageTest extends TestCase
{
    use RefreshDatabase;

    private const PNG_BASE64 =
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwC'.
        'AAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Cache::flush();
    }

    public function test_camera_preview_uses_text_safe_json(): void
    {
        $user = User::factory()->create();
        $path = 'pending/camera.png';

        Storage::disk('local')->put(
            $path,
            base64_decode(self::PNG_BASE64, true),
        );

        Cache::forever(
            'pending_photo_path',
            Storage::disk('local')->path($path),
        );

        $response = $this
            ->actingAs($user)
            ->withSession(['device_unlocked' => true])
            ->getJson(route('camera.preview'));

        $response
            ->assertOk()
            ->assertExactJson([
                'mime_type' => 'image/png',
                'base64' => self::PNG_BASE64,
            ]);

        $this->assertStringNotContainsString(
            base64_decode(self::PNG_BASE64, true),
            $response->getContent(),
        );
    }

    public function test_post_store_uses_the_cached_native_path(): void
    {
        $user = User::factory()->create();
        $path = 'pending/gallery.png';
        $contents = base64_decode(self::PNG_BASE64, true);

        Storage::disk('local')->put($path, $contents);

        Cache::forever(
            'pending_photo_path',
            Storage::disk('local')->path($path),
        );

        $response = $this
            ->actingAs($user)
            ->withSession(['device_unlocked' => true])
            ->post(route('posts.store'), [
                'title' => 'Gallery post',
                'body' => 'Post with a selected image.',
                'captured_photo_path' => '/untrusted/client/path.jpg',
            ]);

        $response->assertRedirect(route('posts.index'));

        $post = Post::query()->sole();

        $this->assertSame($user->id, $post->user_id);
        $this->assertStringEndsWith('.png', $post->photo_url);
        $this->assertSame(
            $contents,
            Storage::disk('local')->get($post->photo_url),
        );
        $this->assertFalse(Cache::has('pending_photo_path'));
    }

    public function test_posts_page_uses_private_photo_endpoint(): void
    {
        $user = User::factory()->create();
        $post = $this->createPostWithImage($user);

        $response = $this
            ->actingAs($user)
            ->withSession(['device_unlocked' => true])
            ->get(route('posts.index'));

        $response
            ->assertOk()
            ->assertSee(
                'data-native-image="'.route(
                    'posts.photo',
                    ['id' => $post->id],
                ).'"',
                false,
            )
            ->assertDontSee(
                asset('storage/'.$post->photo_url),
                false,
            );
    }

    public function test_owner_can_load_post_photo_as_text_safe_json(): void
    {
        $user = User::factory()->create();
        $post = $this->createPostWithImage($user);

        $response = $this
            ->actingAs($user)
            ->withSession(['device_unlocked' => true])
            ->getJson(route('posts.photo', ['id' => $post->id]));

        $response
            ->assertOk()
            ->assertExactJson([
                'mime_type' => 'image/png',
                'base64' => self::PNG_BASE64,
            ]);
    }

    public function test_user_cannot_load_another_users_post_photo(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $post = $this->createPostWithImage($owner);

        $response = $this
            ->actingAs($otherUser)
            ->withSession(['device_unlocked' => true])
            ->getJson(route('posts.photo', ['id' => $post->id]));

        $response->assertNotFound();
    }

    public function test_create_page_offers_gallery_selection(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->withSession(['device_unlocked' => true])
            ->get(route('posts.create'));

        $response
            ->assertOk()
            ->assertSee(route('camera.pick'), false)
            ->assertSee('Choose from Gallery');
    }

    public function test_camera_cancellation_is_reported(): void
    {
        $user = User::factory()->create();

        event(new PhotoCancelled);

        $response = $this
            ->actingAs($user)
            ->withSession(['device_unlocked' => true])
            ->getJson(route('camera.status'));

        $response
            ->assertOk()
            ->assertExactJson([
                'state' => 'cancelled',
                'ready' => false,
                'cancelled' => true,
                'message' => 'No image was selected.',
            ]);
    }

    public function test_gallery_cancellation_is_reported(): void
    {
        $user = User::factory()->create();

        event(new MediaSelected(
            success: false,
            cancelled: true,
        ));

        $response = $this
            ->actingAs($user)
            ->withSession(['device_unlocked' => true])
            ->getJson(route('camera.status'));

        $response
            ->assertOk()
            ->assertExactJson([
                'state' => 'cancelled',
                'ready' => false,
                'cancelled' => true,
                'message' => 'No image was selected.',
            ]);
    }

    public function test_waiting_page_has_recovery_actions(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->withSession(['device_unlocked' => true])
            ->get(route('camera.waiting'));

        $response
            ->assertOk()
            ->assertSee(route('posts.create'), false)
            ->assertSee(route('camera.capture'), false)
            ->assertSee(route('camera.pick'), false)
            ->assertSee("data.state === 'cancelled'", false);
    }

    private function createPostWithImage(User $user): Post
    {
        $path = 'posts/test-image.png';

        Storage::disk('local')->put(
            $path,
            base64_decode(self::PNG_BASE64, true),
        );

        return $user->posts()->create([
            'title' => 'Photo post',
            'slug' => 'photo-post',
            'body' => 'Post body.',
            'photo_url' => $path,
            'published_at' => now(),
        ]);
    }
}
