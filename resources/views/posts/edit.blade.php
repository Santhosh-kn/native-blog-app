<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Post</title>
    <style>
        body { font-family: -apple-system, sans-serif; padding: 24px; background: #f5f5f5; }
        form { display: flex; flex-direction: column; gap: 12px; }
        label { font-size: 14px; font-weight: 600; }
        input, textarea { padding: 12px; font-size: 16px; border: 1px solid #ccc; border-radius: 8px; width: 100%; box-sizing: border-box; font-family: inherit; }
        textarea { min-height: 160px; resize: vertical; }
        button { padding: 12px; font-size: 16px; background: #04ABA6; color: white; border: none; border-radius: 8px; margin-top: 8px; }
        .error { color: #d33; font-size: 13px; margin: 0; }
        .back { display: inline-block; margin-top: 16px; }
    </style>
</head>
<body>
    <h1>Edit Post</h1>

    <form method="POST" action="{{ route('posts.update', $post->id) }}">
        @csrf
        @method('PUT')

        <label for="title">Title</label>
        <input type="text" id="title" name="title" value="{{ old('title', $post->title) }}">
        @error('title') <p class="error">{{ $message }}</p> @enderror

        <label for="body">Body</label>
        <textarea id="body" name="body">{{ old('body', $post->body) }}</textarea>
        @error('body') <p class="error">{{ $message }}</p> @enderror

        <button type="submit">Save Changes</button>
    </form>

    <a href="{{ route('posts.index') }}" class="back">&larr; Back to posts</a>
</body>
</html>