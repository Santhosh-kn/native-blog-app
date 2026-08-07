<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Posts</title>
    <style>
        body { font-family: -apple-system, sans-serif; padding: 24px; background: #f5f5f5; }
        button, .btn { padding: 8px 14px; font-size: 14px; border: none; border-radius: 6px; cursor: pointer; text-decoration: none; display: inline-block; }
        .btn-primary { background: #04ABA6; color: white; padding: 10px 16px; }
        .btn-edit { background: #04ABA6; color: white; }
        .btn-delete { background: #999; color: white; }
        .btn-share { background: #666; color: white; }
        .post-card { background: white; border-radius: 8px; padding: 16px; margin-top: 12px; }
        .post-card h3 { margin: 0 0 6px; }
        .post-card p { margin: 0 0 10px; font-size: 14px; color: #555; }
        .post-card img { width: 100%; height: auto; border-radius: 6px; margin-bottom: 12px; }
        .actions { display: flex; gap: 6px; flex-wrap: wrap; }
        .pagination { margin-top: 16px; display: flex; justify-content: space-between; align-items: center; font-size: 14px; }
        .status { background: #d4edda; color: #155724; padding: 10px; border-radius: 6px; margin-top: 16px; }
        .top-bar { display: flex; justify-content: space-between; align-items: center; }
        .back { display: inline-block; margin-top: 16px; }
    </style>
</head>
<body>
    <div class="top-bar">
        <h1>Posts</h1>
        <a href="{{ route('posts.create') }}" class="btn btn-primary">New Post</a>
    </div>

    @if (session('status'))
        <p class="status">{{ session('status') }}</p>
    @endif

    @forelse ($posts as $post)
        <div class="post-card">
            @if ($post['photo_url'])
                <img src="{{ $post['photo_url'] }}">
            @endif
            <h3>{{ $post['title'] }}</h3>
            <p>{{ Str::limit($post['body'], 100) }}</p>
            <div class="actions">
                <a href="{{ route('posts.edit', $post['id']) }}" class="btn btn-edit">Edit</a>
                <form method="POST" action="{{ route('posts.destroy', $post['id']) }}" onsubmit="return confirm('Delete this post?');" style="display: inline;">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-delete">Delete</button>
                </form>
            </div>
        </div>
    @empty
        <p>No posts yet.</p>
    @endforelse

    <div class="pagination">
        <span>@if ($meta['current_page'] > 1)<a href="{{ route('posts.index', ['page' => $meta['current_page'] - 1]) }}">&laquo; Prev</a>@endif</span>
        <span>Page {{ $meta['current_page'] }} of {{ $meta['last_page'] }}</span>
        <span>@if ($meta['current_page'] < $meta['last_page'])<a href="{{ route('posts.index', ['page' => $meta['current_page'] + 1]) }}">Next &raquo;</a>@endif</span>
    </div>

    <a href="{{ route('home') }}" class="back">&larr; Back to home</a>
</body>
</html>