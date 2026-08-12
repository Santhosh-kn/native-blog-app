<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Post</title>
    <style>
        body { font-family: -apple-system, sans-serif; padding: 24px; background: #f5f5f5; }
        form { display: flex; flex-direction: column; gap: 12px; }
        label { font-size: 14px; font-weight: 600; }
        input, textarea { padding: 12px; font-size: 16px; border: 1px solid #ccc; border-radius: 8px; width: 100%; box-sizing: border-box; font-family: inherit; }
        textarea { min-height: 160px; resize: vertical; }
        button, .btn { padding: 12px; font-size: 16px; background: #04ABA6; color: white; border: none; border-radius: 8px; margin-top: 8px; text-align: center; text-decoration: none; display: block; }
        .camera-btn { background: #666; }
        #photo-preview { max-width: 100%; height: auto; margin-top: 12px; border-radius: 8px; }
        .error { color: #d33; font-size: 13px; margin: 0; }
        .back { display: inline-block; margin-top: 16px; }
    </style>
</head>
<body>
    <h1>New Post</h1>
    <a href="/debug/cache" style="display:block; margin-top:20px; font-size:12px; color:#999;">Debug: Check Cache</a>
    <a href="{{ route('camera.capture') }}" class="btn camera-btn">Take Photo</a>
    <a href="{{ route('camera.pick') }}" class="btn camera-btn">Pick from Gallery</a>

    @if ($capturedPhoto)
        <img id="photo-preview" src="{{ route('camera.preview') }}?t={{ time() }}" onerror="document.getElementById('debug-info').style.display='block'">
        <div id="debug-info" style="display:none; background:#fee; padding:10px; margin-top:8px; font-size:12px; word-break:break-all;">
            <strong>Image failed to load. Path in cache:</strong><br>
            {{ $capturedPhoto }}<br><br>
            <strong>File exists on disk:</strong> {{ file_exists($capturedPhoto) ? 'YES' : 'NO' }}
        </div>
        <p style="font-size: 13px; color: #555;">Photo captured — fill in the form and publish.</p>
    @endif

    <form method="POST" action="{{ route('posts.store') }}">
        @csrf

        <label for="title">Title</label>
        <input type="text" id="title" name="title" value="{{ old('title') }}">
        @error('title') <p class="error">{{ $message }}</p> @enderror

        <label for="body">Body</label>
        <textarea id="body" name="body">{{ old('body') }}</textarea>
        @error('body') <p class="error">{{ $message }}</p> @enderror

        <input type="hidden" name="captured_photo_path" value="{{ $capturedPhoto }}">

        <button type="submit">Publish</button>
    </form>

    <a href="{{ route('posts.index') }}" class="back">&larr; Back to posts</a>
</body>
</html>