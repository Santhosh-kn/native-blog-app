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
        button { padding: 12px; font-size: 16px; background: #04ABA6; color: white; border: none; border-radius: 8px; margin-top: 8px; }
        .camera-btn { background: #666; }
        #photo-preview { max-width: 100%; height: auto; margin-top: 12px; border-radius: 8px; display: none; }
        .error { color: #d33; font-size: 13px; margin: 0; }
        .back { display: inline-block; margin-top: 16px; }
    </style>
</head>
<body>
    <h1>New Post</h1>

    <form method="POST" action="{{ route('posts.store') }}">
        @csrf

        <label for="title">Title</label>
        <input type="text" id="title" name="title" value="{{ old('title') }}">
        @error('title') <p class="error">{{ $message }}</p> @enderror

        <label for="body">Body</label>
        <textarea id="body" name="body">{{ old('body') }}</textarea>
        @error('body') <p class="error">{{ $message }}</p> @enderror

        <button type="button" class="camera-btn" id="camera-btn">Take Photo</button>
        <img id="photo-preview">
        <input type="hidden" id="photo_base64" name="photo_base64">

        <button type="submit">Publish</button>
    </form>

    <a href="{{ route('posts.index') }}" class="back">&larr; Back to posts</a>

    <script type="module">
        import { Camera } from '#nativephp';

        document.getElementById('camera-btn').addEventListener('click', async () => {
            try {
                const result = await Camera.getPhoto({
                    quality: 80,
                    resultType: 'base64',
                });

                if (result && result.base64String) {
                    const base64 = 'data:image/jpeg;base64,' + result.base64String;
                    const preview = document.getElementById('photo-preview');
                    const input = document.getElementById('photo_base64');
                    
                    preview.src = base64;
                    preview.style.display = 'block';
                    input.value = base64;
                } else {
                    alert('Failed to capture photo');
                }
            } catch (error) {
                alert('Camera error: ' + error.message);
            }
        });
    </script>
</body>
</html>