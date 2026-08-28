@extends('layouts.app')

@section('title', 'Selecting Image')

@section('content')
    <div class="card" style="text-align: center; margin-top: 48px;">
        <div
            id="selection-spinner"
            style="width: 48px; height: 48px; border: 4px solid var(--border); border-top-color: var(--primary); border-radius: 50%; margin: 8px auto 20px; animation: spin 0.8s linear infinite;"
        ></div>

        <p id="selection-title" style="font-weight: 700; margin: 0 0 8px;">
            Waiting for image...
        </p>

        <p id="selection-message" style="color: var(--text-muted); font-size: 14px; line-height: 1.5; margin: 0 0 20px;">
            Complete the native image action or return here to continue.
        </p>

        <a href="{{ route('posts.create') }}" class="btn btn-secondary btn-block">
            Back to New Post
        </a>

        <div id="selection-recovery" hidden style="margin-top: 10px;">
            <a href="{{ route('camera.capture') }}" class="btn btn-secondary btn-block" style="margin-bottom: 10px;">
                Try Camera Again
            </a>

            <a href="{{ route('camera.pick') }}" class="btn btn-secondary btn-block">
                Choose from Gallery
            </a>
        </div>
    </div>

    <style>
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
    </style>

    <script>
        (() => {
            const statusUrl = @json(route('camera.status'));
            const createPostUrl = @json(route('posts.create'));
            const spinner = document.getElementById('selection-spinner');
            const title = document.getElementById('selection-title');
            const message = document.getElementById('selection-message');
            const recovery = document.getElementById('selection-recovery');

            let stopped = false;
            let consecutiveErrors = 0;

            const showRecovery = (heading, detail) => {
                stopped = true;
                spinner.hidden = true;
                title.textContent = heading;
                message.textContent = detail;
                recovery.hidden = false;
            };

            const check = async () => {
                if (stopped) {
                    return;
                }

                try {
                    const response = await fetch(statusUrl, {
                        cache: 'no-store',
                        credentials: 'same-origin',
                        headers: {
                            Accept: 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                    });

                    if (! response.ok) {
                        throw new Error('Image status request failed.');
                    }

                    const data = await response.json();
                    consecutiveErrors = 0;

                    if (data.ready) {
                        window.location.replace(createPostUrl);

                        return;
                    }

                    if (data.state === 'cancelled') {
                        showRecovery(
                            'No image selected',
                            data.message || 'You can retry or return to the post form.',
                        );

                        return;
                    }

                    if (data.state === 'failed') {
                        showRecovery(
                            'Image selection failed',
                            data.message || 'Please retry the image action.',
                        );

                        return;
                    }

                    window.setTimeout(check, 1000);
                } catch (error) {
                    consecutiveErrors += 1;

                    if (consecutiveErrors >= 5) {
                        showRecovery(
                            'Still waiting for the app',
                            'Return to the post form or retry the image action.',
                        );

                        return;
                    }

                    window.setTimeout(check, 1000);
                }
            };

            check();
        })();
    </script>
@endsection
