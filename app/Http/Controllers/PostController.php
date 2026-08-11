<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Post;
use Illuminate\Support\Str;
use Native\Mobile\Facades\Camera;
use Illuminate\Support\Facades\Gate;

class PostController extends Controller
{
    public function index(Request $request)
    {
        $posts = auth()->user()->posts()->latest()->paginate(10);

        return view('posts.index', [
            'posts' => $posts,
        ]);
    }

    public function create()
    {
        return view('posts.create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
        ]);

        $photoUrl = null;

        if (session('temp_photo_base64')) {
            $photoData = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', session('temp_photo_base64')));
            $filename = 'posts/' . uniqid() . '.jpg';
            \Storage::disk('local')->put($filename, $photoData);
            $photoUrl = $filename;
            session()->forget('temp_photo_base64');
        }

        auth()->user()->posts()->create([
            'title' => $validated['title'],
            'slug' => Str::slug($validated['title']) . '-' . uniqid(),
            'body' => $validated['body'],
            'photo_url' => $photoUrl,
            'published_at' => now(),
        ]);

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
}