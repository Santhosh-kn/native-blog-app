@extends('layouts.app')

@section('title', 'Push Notifications')

@section('content')
    <div class="card" style="margin-bottom: 16px; text-align: center;">
        <p style="margin: 0 0 4px; font-size: 13px; color: var(--text-muted);">
            Permission status
        </p>

        <p
            id="push-permission"
            style="margin: 0; font-weight: 700; font-size: 16px;"
        >
            {{ $permission ?? 'unknown' }}
        </p>
    </div>

    <form
        method="POST"
        action="{{ route('push.enroll') }}"
        style="margin-bottom: 10px;"
    >
        @csrf

        <button type="submit" class="btn btn-primary btn-block">
            Enable Push Notifications
        </button>
    </form>

    @error('push')
        <div class="error-banner">{{ $message }}</div>
    @enderror

    @if ($requestId)
        <div
            id="push-registration-status"
            class="card"
            data-request-id="{{ $requestId }}"
            style="margin-top: 16px;"
        >
            <p style="margin: 0 0 4px; font-size: 13px; color: var(--text-muted);">
                Registration status
            </p>

            <p id="push-registration-message" style="margin: 0;">
                Waiting for Firebase registration…
            </p>
        </div>
    @endif

    <div class="card" style="margin-top: 16px;">
        <p style="margin: 0 0 4px; font-size: 13px; color: var(--text-muted);"> Device registration </p>
        <p id="push-registration" style="margin: 0; font-size: 13px; color: var(--text-muted);"> {{ $registered ? 'Registered' : 'Not yet registered' }} </p>
    </div>
@endsection
@push('scripts')
    @if ($requestId)
        <script>
            document.addEventListener('DOMContentLoaded', () => {
                const statusCard =
                    document.getElementById('push-registration-status');

                if (!statusCard) {
                    return;
                }

                const requestId = statusCard.dataset.requestId;
                const statusUrl = @json(route('push.status'));
                const permissionElement = document.getElementById('push-permission');
                const messageElement = document.getElementById('push-registration-message');
                const registrationElement = document.getElementById('push-registration');

                let attempts = 0;
                const maximumAttempts = 30;

                async function pollRegistration() {
                    attempts += 1;

                    try {
                        const response = await fetch(
                            `${statusUrl}?id=${encodeURIComponent(requestId)}`,
                            {
                                headers: {
                                    Accept: 'application/json',
                                },
                                credentials: 'same-origin',
                                cache: 'no-store',
                            },
                        );

                        const result = await response.json();

                        if (result.permission) {
                            permissionElement.textContent =
                                result.permission;
                        }

                        if (
                            response.status === 202 ||
                            result.pending === true
                        ) {
                            if (attempts < maximumAttempts) {
                                window.setTimeout(
                                    pollRegistration,
                                    1000,
                                );

                                return;
                            }

                            messageElement.textContent =
                                'Registration is taking longer than expected.';

                            return;
                        }

                        if (!response.ok) {
                            throw new Error(
                                result.message ||
                                'Unable to check push registration.',
                            );
                        }

                        if (result.success) {
                            registrationElement.textContent = result.registered ? 'Registered' : 'Not yet registered';

                            messageElement.textContent =
                                result.permission === 'granted'
                                    ? 'Push notifications are enabled.'
                                    : 'The device token was received, but notification permission is not granted.';

                            return;
                        }

                        messageElement.textContent =
                            result.error ||
                            'Firebase token registration failed.';
                    } catch (error) {
                        if (attempts < maximumAttempts) {
                            window.setTimeout(
                                pollRegistration,
                                1000,
                            );

                            return;
                        }

                        messageElement.textContent =
                            error instanceof Error
                                ? error.message
                                : 'Unable to check push registration.';
                    }
                }

                pollRegistration();
            });
        </script>
    @endif
@endpush