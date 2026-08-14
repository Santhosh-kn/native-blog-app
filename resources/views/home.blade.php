@extends('layouts.app')

@section('title', 'Home')

@section('content')
    <div class="card" style="margin-bottom: 16px;">
        <p style="margin: 0 0 4px; font-size: 14px; color: var(--text-muted);">Welcome back</p>
        <p style="margin: 0; font-size: 18px; font-weight: 700;">{{ auth()->user()->name }}</p>
    </div>

    <div style="display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 20px;">
        <a href="{{ route('posts.index') }}" class="btn btn-secondary btn-sm">📝 My Posts</a>
        <a href="{{ route('push.index') }}" class="btn btn-secondary btn-sm">🔔 Notifications</a>
        <form method="POST" action="{{ route('logout') }}" style="margin: 0;">
            @csrf
            <button type="submit" class="btn btn-secondary btn-sm">🚪 Logout</button>
        </form>
    </div>

    <p style="font-size: 13px; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.03em; margin-bottom: 8px;">Recent posts</p>

    @forelse ($posts->take(3) as $post)
        <div class="card" style="margin-bottom: 10px;">
            <p style="margin: 0 0 4px; font-weight: 700;">{{ $post->title }}</p>
            <p style="margin: 0; font-size: 13px; color: var(--text-muted);">{{ Str::limit($post->body, 80) }}</p>
        </div>
    @empty
        <div class="empty-state">
            <div class="icon">📭</div>
            <p>No posts yet</p>
        </div>
    @endforelse
@endsection