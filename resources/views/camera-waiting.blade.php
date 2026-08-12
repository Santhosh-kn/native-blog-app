<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Capturing...</title>
    <style>
        body { font-family: -apple-system, sans-serif; padding: 24px; background: #f5f5f5; text-align: center; }
        p { margin-top: 60px; font-size: 16px; color: #555; }
    </style>
</head>
<body>
    <p>Waiting for photo...</p>
    <a href="/debug/cache" style="display:block; margin-top:20px; font-size:12px; color:#999;">Debug: Check Cache</a>
    <script>
        const check = async () => {
            try {
                const response = await fetch('{{ route("camera.status") }}');
                const data = await response.json();

                if (data.ready) {
                    window.location.href = '{{ route("posts.create") }}';
                } else {
                    setTimeout(check, 1000);
                }
            } catch (e) {
                setTimeout(check, 1000);
            }
        };

        check();
    </script>
</body>
</html>