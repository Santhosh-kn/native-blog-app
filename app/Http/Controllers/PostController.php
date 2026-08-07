<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class PostController extends Controller
{
    public function index(Request $request)
    {
        $response = Http::baseUrl(config('api.base_url'))
            ->acceptJson()
            ->withToken(session('api_token'))
            ->get('/posts', ['page' => $request->query('page', 1)]);

        $data = $response->json();

        return view('posts.index', [
            'posts' => $data['data'],
            'meta' => [
                'current_page' => $data['current_page'],
                'last_page' => $data['last_page'],
            ],
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

        $payload = $validated;

        if ($request->input('photo_base64')) {
            $payload['photo_base64'] = $request->input('photo_base64');
        }

        $response = Http::baseUrl(config('api.base_url'))
            ->acceptJson()
            ->withToken(session('api_token'))
            ->post('/posts', $payload);

        if ($response->failed()) {
            return back()
                ->withErrors($response->json('errors') ?? ['title' => 'Could not create post.'])
                ->withInput();
        }

        return redirect()->route('posts.index')->with('status', 'Post created.');
    }

    public function edit($id)
    {
        $response = Http::baseUrl(config('api.base_url'))
            ->acceptJson()
            ->withToken(session('api_token'))
            ->get("/posts/{$id}");

        if ($response->failed()) {
            abort(404);
        }

        return view('posts.edit', ['post' => $response->json()]);
    }

    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
        ]);

        $response = Http::baseUrl(config('api.base_url'))
            ->acceptJson()
            ->withToken(session('api_token'))
            ->put("/posts/{$id}", $validated);

        if ($response->failed()) {
            return back()
                ->withErrors($response->json('errors') ?? ['title' => 'Could not update post.'])
                ->withInput();
        }

        return redirect()->route('posts.index')->with('status', 'Post updated.');
    }

    public function destroy($id)
    {
        Http::baseUrl(config('api.base_url'))
            ->acceptJson()
            ->withToken(session('api_token'))
            ->delete("/posts/{$id}");

        return redirect()->route('posts.index')->with('status', 'Post deleted.');
    }
}