@extends('layouts.app')

@section('title', 'Login')

@section('content')
    <div style="max-width: 400px; margin: 40px auto 0;">
        <div style="text-align: center; margin-bottom: 32px;">
            <p style="font-size: 40px; margin: 0 0 8px;">📖</p>
            <p style="font-size: 22px; font-weight: 700; margin: 0;">Welcome back</p>
            <p style="font-size: 14px; color: var(--text-muted); margin: 4px 0 0;">Log in to continue</p>
        </div>

        <form method="POST" action="{{ route('login.store') }}">
            @csrf
            <div class="field">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" value="{{ old('email') }}" autocomplete="email">
                @error('email') <p class="error">{{ $message }}</p> @enderror
            </div>
            <div class="field">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" autocomplete="current-password">
            </div>
            <button type="submit" class="btn btn-primary btn-block">Login</button>
        </form>

        <div style="display: flex; align-items: center; gap: 12px; margin: 24px 0;">
            <span style="height: 1px; background: var(--border); flex: 1;"></span>

            <span style="font-size: 13px; color: var(--text-muted);">
                or
            </span>

            <span style="height: 1px; background: var(--border); flex: 1;"></span>
        </div>

        <button
            type="button"
            id="google-sign-in-button"
            class="btn btn-block"
            style="
                background: var(--surface);
                color: var(--text);
                border: 1px solid var(--border);
            "
        >
            Sign in with Google
        </button>

        <div
            id="google-auth-message"
            role="status"
            aria-live="polite"
            style="margin-top: 12px;"
            hidden
        ></div>
        <p style="text-align: center; margin-top: 20px; font-size: 14px; color: var(--text-muted);">
            Don't have an account? <a href="{{ route('register') }}" style="color: var(--primary); font-weight: 600; text-decoration: none;">Register</a>
        </p>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const button = document.getElementById('google-sign-in-button');
            const messageContainer = document.getElementById('google-auth-message');

            if (! button || ! messageContainer) {
                return;
            }

            const startUrl = @json(route('google.start', [], false));

            const statusUrlTemplate = @json(
                route(
                    'google.status',
                    ['requestId' => '__REQUEST_ID__'],
                    false
                )
            );

            const csrfToken = @json(csrf_token());

            const showMessage = (message, isError = false) => {
                messageContainer.textContent = message;
                messageContainer.className = isError
                    ? 'error-banner'
                    : 'status-banner';

                messageContainer.hidden = false;
            };

            const sleep = (milliseconds) => {
                return new Promise((resolve) => {
                    setTimeout(resolve, milliseconds);
                });
            };

            const pollForResult = async (requestId) => {
                const statusUrl = statusUrlTemplate.replace(
                    '__REQUEST_ID__',
                    encodeURIComponent(requestId)
                );

                for (let attempt = 0; attempt < 120; attempt++) {
                    await sleep(1000);

                    const response = await fetch(statusUrl, {
                        headers: {
                            'Accept': 'application/json',
                        },
                        credentials: 'same-origin',
                    });

                    const data = await response.json();

                    if (! response.ok) {
                        throw new Error(
                            data.message ?? 'Unable to check Google authentication.'
                        );
                    }

                    if (data.status === 'pending') {
                        continue;
                    }

                    if (data.status === 'authenticated') {
                        showMessage('Google authentication completed.');

                        window.location.assign(data.redirect);

                        return;
                    }

                    throw new Error(
                        data.message ?? 'Google authentication failed.'
                    );
                }

                throw new Error(
                    'Google authentication timed out. Please try again.'
                );
            };

            button.addEventListener('click', async () => {
                button.disabled = true;
                button.style.opacity = '0.65';

                showMessage('Opening Google Sign-In…');

                try {
                    const response = await fetch(startUrl, {
                        method: 'POST',

                        headers: {
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                        },

                        credentials: 'same-origin',
                    });

                    const data = await response.json();

                    if (
                        ! response.ok
                        || data.status !== 'started'
                        || ! data.request_id
                    ) {
                        throw new Error(
                            data.message ?? 'Unable to start Google authentication.'
                        );
                    }

                    showMessage('Waiting for Google authentication…');

                    await pollForResult(data.request_id);
                } catch (error) {
                    showMessage(
                        error.message ?? 'Google authentication failed.',
                        true
                    );
                } finally {
                    button.disabled = false;
                    button.style.opacity = '1';
                }
            });
        });
    </script>
@endsection