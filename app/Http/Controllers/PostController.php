<?php

namespace App\Http\Controllers;

use App\Models\Post;
use App\Support\NativeImagePayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Native\Mobile\Facades\Dialog;
use Native\Mobile\Facades\File;
use Native\Mobile\Facades\Share;
use RuntimeException;

class PostController extends Controller
{
    public function index(Request $request)
    {
        $posts = auth()->user()->posts()->latest()->paginate(10);

        return view('posts.index', [
            'posts' => $posts,
        ]);
    }

    public function photo(Request $request, int $id): JsonResponse
    {
        $post = $request->user()
            ->posts()
            ->findOrFail($id);

        if (
            ! is_string($post->photo_url) ||
            trim($post->photo_url) === ''
        ) {
            return response()->json([
                'message' => 'The post image is unavailable.',
            ], 404);
        }

        try {
            $payload = NativeImagePayload::fromPath(
                Storage::disk('local')->path($post->photo_url),
            );
        } catch (RuntimeException) {
            return response()->json([
                'message' => 'The post image is unavailable.',
            ], 404);
        }

        return response()
            ->json($payload)
            ->withHeaders([
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ]);
    }

    public function create()
    {
        return view('posts.create', [
            'capturedPhoto' => Cache::get('pending_photo_path'),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
        ]);

        $photoUrl = null;
        $capturedPath = Cache::get('pending_photo_path');

        if (is_string($capturedPath) && trim($capturedPath) !== '') {
            try {
                $image = NativeImagePayload::read($capturedPath);
            } catch (RuntimeException) {
                return back()
                    ->withInput()
                    ->withErrors([
                        'photo' => 'The selected image could not be read.',
                    ]);
            }

            $filename = 'posts/'.Str::uuid().'.'.$image['extension'];

            $stored = Storage::disk('local')->put(
                $filename,
                $image['contents'],
            );

            if (! $stored) {
                return back()
                    ->withInput()
                    ->withErrors([
                        'photo' => 'The selected image could not be saved.',
                    ]);
            }

            $photoUrl = $filename;
            Cache::forget('pending_photo_path');
        }

        auth()->user()->posts()->create([
            'title' => $validated['title'],
            'slug' => Str::slug($validated['title']).'-'.uniqid(),
            'body' => $validated['body'],
            'photo_url' => $photoUrl,
            'published_at' => now(),
        ]);

        Cache::forget('pending_photo_selection');

        return redirect()->route('posts.index')->with('status', 'Post created.');
    }

    public function edit($id)
    {
        $post = Post::findOrFail($id);

        if (Gate::denies('update', $post)) {
            abort(403);
        }

        return view('posts.edit', ['post' => $post]);
    }

    public function update(Request $request, $id)
    {
        $post = Post::findOrFail($id);

        if (Gate::denies('update', $post)) {
            abort(403);
        }

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
        ]);

        $post->update($validated);

        return redirect()->route('posts.index')->with('status', 'Post updated.');
    }

    public function destroy($id)
    {
        $post = Post::findOrFail($id);

        if (Gate::denies('delete', $post)) {
            abort(403);
        }

        $post->delete();

        return redirect()->route('posts.index')->with('status', 'Post deleted.');
    }

    public function export($id)
    {
        $post = Post::findOrFail($id);
        if (Gate::denies('update', $post)) {
            abort(403);
        }

        $filename = Str::slug($post->title).'.txt';
        $content = "{$post->title}\n\n{$post->body}\n\nPublished: {$post->published_at}";

        Storage::disk('local')->put("exports/{$filename}", $content);
        $sourcePath = Storage::disk('local')->path("exports/{$filename}");
        $destinationPath = '/storage/emulated/0/Download/'.$filename;

        $result = File::copy($sourcePath, $destinationPath);

        if ($result) {
            Dialog::toast('Exported to Downloads: '.$filename);

            return redirect()->route('posts.index');
        } else {
            Dialog::toast('Export failed.');
        }

        return redirect()->route('posts.index');
    }

    public function share($id)
    {
        $post = Post::findOrFail($id);

        if (Gate::denies('update', $post)) {
            abort(403);
        }

        $filename = Str::slug($post->title).'.txt';
        $content = "{$post->title}\n\n{$post->body}\n\nPublished: {$post->published_at}";

        Storage::disk('local')->put("exports/{$filename}", $content);
        $path = Storage::disk('local')->path("exports/{$filename}");

        Share::file($post->title, 'Check out this post', $path);

        return redirect()->route('posts.index');
    }
}
