@extends('layouts.app')

@section('title', 'Native Passkeys')
@section('page-icon', 'shield')
@section('subtitle', 'Check device support and passkey readiness.')

@section('content')
<div class="passkeys-grid">
        <section class="passkeys-warning">
            This is a local readiness and bridge diagnostic page.
            Passkey registration and authentication remain disabled
            until the app has a public HTTPS backend, relying-party ID,
            server-generated challenges, verification endpoints, and
            valid Android Digital Asset Links.
        </section>

        <section class="card">
            <h2 class="card-heading">@include('partials.ui-icon', ['name' => 'shield']) Android readiness</h2>

            <p
                id="passkeys-availability-message"
                class="passkeys-copy"
            >
                Checking Android Credential Manager...
            </p>

            <div class="passkeys-capabilities">
                <div class="passkeys-capability">
                    <strong id="passkeys-platform">Android</strong>
                    <span>Platform</span>
                </div>

                <div class="passkeys-capability">
                    <strong id="passkeys-api-level">Checking</strong>
                    <span>API level</span>
                </div>

                <div class="passkeys-capability">
                    <strong id="passkeys-availability">Checking</strong>
                    <span>Credential Manager</span>
                </div>
            </div>
        </section>

        <section class="card">
            <h2 class="card-heading">@include('partials.ui-icon', ['name' => 'shield']) Production prerequisites</h2>

            <ul class="passkeys-requirements">
                <li>Public HTTPS application backend</li>
                <li>Valid WebAuthn relying-party ID</li>
                <li>Server-generated registration and login options</li>
                <li>Server-side credential-response verification</li>
                <li>Digital Asset Links with SHA-256 fingerprints</li>
                <li>Separate release signing fingerprint</li>
            </ul>
        </section>

        <section class="card">
            <h2 class="card-heading">@include('partials.ui-icon', ['name' => 'shield']) Safe native diagnostics</h2>

            <p class="passkeys-copy" style="margin-bottom: 12px;">
                Uses a newly generated, unused request ID to verify
                status, cancellation, and one-time result-consumption
                bridge responses. No credential operation is started.
            </p>

            <button
                type="button"
                id="passkeys-diagnostics-button"
                class="btn btn-primary btn-block"
                disabled
            >
                Run safe diagnostics
            </button>
        </section>

        <section
            id="passkeys-status"
            class="passkeys-status"
            data-tone="neutral"
            role="status"
            aria-live="polite"
        >
            <strong
                id="passkeys-status-title"
                class="passkeys-status-title"
            >
                Waiting
            </strong>

            <p
                id="passkeys-status-message"
                class="passkeys-status-message"
            >
                Waiting for the availability check.
            </p>
        </section>
    </div>
@endsection

@push('scripts')
<script>
(() => {
    'use strict';

    const endpoints = Object.freeze({
        availability: @json(
            route('native-passkeys.availability')
        ),
        diagnostics: @json(
            route('native-passkeys.diagnostics')
        ),
    });

    const csrfToken = document
        .querySelector('meta[name="csrf-token"]')
        ?.getAttribute('content') ?? '';

    const availabilityMessage = document.getElementById(
        'passkeys-availability-message',
    );

    const platformValue = document.getElementById(
        'passkeys-platform',
    );

    const apiLevelValue = document.getElementById(
        'passkeys-api-level',
    );

    const availabilityValue = document.getElementById(
        'passkeys-availability',
    );

    const diagnosticsButton = document.getElementById(
        'passkeys-diagnostics-button',
    );

    const statusPanel = document.getElementById(
        'passkeys-status',
    );

    const statusTitle = document.getElementById(
        'passkeys-status-title',
    );

    const statusMessage = document.getElementById(
        'passkeys-status-message',
    );

    function setStatus(tone, title, message) {
        statusPanel.dataset.tone = tone;
        statusTitle.textContent = title;
        statusMessage.textContent = message;
    }

    async function fetchJson(url, options = {}) {
        const response = await fetch(url, {
            credentials: 'same-origin',
            ...options,
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken,
                ...(options.headers ?? {}),
            },
        });

        let result = {};

        try {
            result = await response.json();
        } catch {
            result = {};
        }

        if (! response.ok) {
            const message =
                result.error_message ??
                'The Passkeys diagnostic request failed.';

            const error = new Error(message);
            error.result = result;

            throw error;
        }

        return result;
    }

    function describeOperations(operations) {
        return Object.values(operations ?? {})
            .map((operation) => {
                const label = operation.method ?? 'Unknown method';
                const status = operation.status ?? 'unknown';
                const code = operation.error_code ?? 'none';

                return `${label}: ${status} (${code})`;
            })
            .join('\n');
    }

    async function loadAvailability() {
        try {
            const result = await fetchJson(
                endpoints.availability,
            );

            platformValue.textContent =
                result.platform ?? 'android';

            apiLevelValue.textContent =
                result.api_level ?? 'Unknown';

            availabilityValue.textContent =
                result.available === true
                    ? 'Available'
                    : 'Unavailable';

            availabilityMessage.textContent =
                result.available === true
                    ? (
                        'Credential Manager is available. ' +
                        'This does not confirm server or domain setup.'
                    )
                    : (
                        result.error_message ??
                        'Credential Manager is unavailable.'
                    );

            setStatus(
                result.available === true
                    ? 'success'
                    : 'warning',
                'Readiness check completed',
                (
                    `Minimum API: ${
                        result.minimum_api_level ?? 28
                    }.`
                ),
            );
        } catch (error) {
            availabilityValue.textContent = 'Unavailable';
            availabilityMessage.textContent = error.message;

            setStatus(
                'error',
                'Readiness check failed',
                error.message,
            );
        } finally {
            diagnosticsButton.disabled = false;
        }
    }

    async function runDiagnostics() {
        diagnosticsButton.disabled = true;

        setStatus(
            'neutral',
            'Running diagnostics',
            'Calling safe native lifecycle methods.',
        );

        try {
            const result = await fetchJson(
                endpoints.diagnostics,
                {
                    method: 'POST',
                },
            );

            const details = describeOperations(
                result.operations,
            );

            setStatus(
                result.passed === true
                    ? 'success'
                    : 'error',
                result.passed === true
                    ? 'Diagnostics passed'
                    : 'Diagnostics failed',
                details || 'No diagnostic results were returned.',
            );
        } catch (error) {
            setStatus(
                'error',
                'Diagnostics failed',
                error.message,
            );
        } finally {
            diagnosticsButton.disabled = false;
        }
    }

    diagnosticsButton.addEventListener(
        'click',
        runDiagnostics,
    );

    loadAvailability();
})();
</script>
@endpush