@extends('layouts.app')

@section('title', 'Posts')

@section('content')
    @forelse ($posts as $post)
        <div class="card" style="margin-bottom: 12px;">
            @if ($post->photo_url)
                <img src="{{ asset('storage/' . $post->photo_url) }}" style="width: 100%; height: auto; border-radius: 10px; margin-bottom: 12px; display: block;">
            @endif
            <p style="margin: 0 0 4px; font-weight: 700; font-size: 16px;">{{ $post->title }}</p>
            <p style="margin: 0 0 12px; font-size: 14px; color: var(--text-muted); line-height: 1.5;">{{ Str::limit($post->body, 100) }}</p>
            <div style="display: flex; gap: 6px; flex-wrap: wrap;">
                <a href="{{ route('posts.edit', $post->id) }}" class="btn btn-secondary btn-sm">Edit</a>
                <form method="POST" action="{{ route('posts.export', $post->id) }}" style="margin: 0;">
                    @csrf
                    <button type="submit" class="btn btn-secondary btn-sm">Export</button>
                </form>
                <form method="POST" action="{{ route('posts.share', $post->id) }}" style="margin: 0;">
                    @csrf
                    <button type="submit" class="btn btn-secondary btn-sm">Share</button>
                </form>
                <form method="POST" action="{{ route('posts.destroy', $post->id) }}" onsubmit="return confirm('Delete this post?');" style="margin: 0;">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-sm" style="background: #fdeced; color: var(--danger);">Delete</button>
                </form>
            </div>
        </div>
    @empty
        <div class="empty-state">
            <div class="icon">📝</div>
            <p>No posts yet — tap the button below to write your first one.</p>
        </div>
    @endforelse

    @if ($posts->hasPages())
        <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 16px; font-size: 14px; color: var(--text-muted);">
            <span>@if (! $posts->onFirstPage())<a href="{{ $posts->previousPageUrl() }}" style="color: var(--primary);">← Prev</a>@endif</span>
            <span>Page {{ $posts->currentPage() }} of {{ $posts->lastPage() }}</span>
            <span>@if ($posts->hasMorePages())<a href="{{ $posts->nextPageUrl() }}" style="color: var(--primary);">Next →</a>@endif</span>
        </div>
    @endif
@endsection

@section('fab')
    <a href="{{ route('posts.create') }}" class="fab">+</a>
@endsection