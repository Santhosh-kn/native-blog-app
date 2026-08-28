@extends('layouts.app')

@section('title', 'Native Printing')

@section('app-bar-action')
    <a href="{{ route('home') }}" class="btn btn-secondary btn-sm">
        Back
    </a>
@endsection

@section('content')
    <style>
        .printing-grid {
            display: grid;
            gap: 12px;
        }

        .printing-heading {
            margin: 0 0 6px;
            font-size: 16px;
            font-weight: 700;
        }

        .printing-copy {
            margin: 0;
            color: var(--text-muted);
            font-size: 13px;
            line-height: 1.5;
        }

        .printing-file-name {
            margin-top: 10px;
            padding: 10px 12px;
            border: 1px solid var(--border);
            border-radius: 10px;
            overflow-wrap: anywhere;
            font-size: 13px;
            font-weight: 600;
        }

        .printing-capabilities {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
            margin-top: 12px;
        }

        .printing-capability {
            padding: 10px;
            border: 1px solid var(--border);
            border-radius: 10px;
            text-align: center;
        }

        .printing-capability span {
            display: block;
            margin-top: 3px;
            color: var(--text-muted);
            font-size: 12px;
        }

        .printing-actions {
            display: grid;
            gap: 10px;
        }

        .printing-actions button:disabled {
            cursor: not-allowed;
            opacity: 0.5;
        }

        .printing-status-panel {
            padding: 14px;
            border: 1px solid var(--border);
            border-radius: 10px;
            background: var(--surface);
        }

        .printing-status-panel[data-tone="success"] {
            border-color: #9dd9bc;
            background: #e3f7ee;
            color: #0f7a4b;
        }

        .printing-status-panel[data-tone="error"] {
            border-color: #efb5bf;
            background: #fdeced;
            color: #b3273f;
        }

        .printing-status-panel[data-tone="warning"] {
            border-color: #e7cd89;
            background: #fff8df;
            color: #785b0a;
        }

        .printing-status-title {
            display: block;
            font-size: 14px;
            font-weight: 700;
        }

        .printing-status-message {
            margin: 5px 0 0;
            font-size: 13px;
            line-height: 1.45;
            overflow-wrap: anywhere;
        }
    </style>

    <div class="printing-grid">
        <section class="card">
            <h2 class="printing-heading">Android capabilities</h2>

            <p
                id="printing-availability-message"
                class="printing-copy"
            >
                Checking native PDF support...
            </p>

            <div class="printing-capabilities">
                <div class="printing-capability">
                    <strong id="preview-capability">Checking</strong>
                    <span>PDF preview</span>
                </div>

                <div class="printing-capability">
                    <strong id="print-capability">Checking</strong>
                    <span>System printing</span>
                </div>
            </div>
        </section>

        <section class="card">
            <h2 class="printing-heading">Private sample document</h2>

            <p class="printing-copy">
                A deterministic two-page PDF is generated inside
                application-local storage when an action starts.
                It is never shared through broad storage permissions.
            </p>

            <div class="printing-file-name">
                {{ $sampleFileName }}
            </div>
        </section>

        <section class="card printing-actions">
            <button
                type="button"
                id="preview-pdf-button"
                class="btn btn-primary btn-block"
                disabled
            >
                Preview sample PDF
            </button>

            <button
                type="button"
                id="print-pdf-button"
                class="btn btn-secondary btn-block"
                disabled
            >
                Open system print dialog
            </button>
        </section>

        <section
            id="printing-status-panel"
            class="printing-status-panel"
            data-tone="neutral"
            role="status"
            aria-live="polite"
        >
            <strong
                id="printing-status-title"
                class="printing-status-title"
            >
                Ready
            </strong>

            <p
                id="printing-status-message"
                class="printing-status-message"
            >
                Waiting for a native printing action.
            </p>
        </section>
    </div>
@endsection

@push('scripts')
<script>
(() => {
    'use strict';

    const endpoints = Object.freeze({
        availability: @json(route('native-printing.availability')),
        preview: @json(route('native-printing.preview')),
        print: @json(route('native-printing.print')),
        status: @json(route(
            'native-printing.status',
            ['requestId' => '__REQUEST_ID__'],
        )),
    });

    const csrfToken = document
        .querySelector('meta[name="csrf-token"]')
        ?.getAttribute('content') ?? '';

    const availabilityMessage = document.getElementById(
        'printing-availability-message',
    );

    const previewCapability = document.getElementById(
        'preview-capability',
    );

    const printCapability = document.getElementById(
        'print-capability',
    );

    const previewButton = document.getElementById(
        'preview-pdf-button',
    );

    const printButton = document.getElementById(
        'print-pdf-button',
    );

    const statusPanel = document.getElementById(
        'printing-status-panel',
    );

    const statusTitle = document.getElementById(
        'printing-status-title',
    );

    const statusMessage = document.getElementById(
        'printing-status-message',
    );

    const statusLabels = Object.freeze({
        accepted: 'Request accepted',
        presented: 'Preview presented',
        closed: 'Preview closed',
        submitted: 'Print job submitted',
        blocked: 'Print job blocked',
        cancelled: 'Print cancelled',
        completed: 'Print completed',
        failed: 'Native action failed',
        pending: 'Waiting for native status',
    });

    const terminalStatuses = new Set([
        'closed',
        'cancelled',
        'completed',
        'failed',
    ]);

    const capabilities = {
        preview: false,
        print: false,
    };

    let busy = false;
    let pollTimer = null;
    let pollAttempts = 0;

    const maximumPollAttempts = 400;
    const pollIntervalMilliseconds = 1500;

    function updateButtons() {
        previewButton.disabled = busy || ! capabilities.preview;
        printButton.disabled = busy || ! capabilities.print;
    }

    function setStatus(tone, title, message) {
        statusPanel.dataset.tone = tone;
        statusTitle.textContent = title;
        statusMessage.textContent = message;
    }

    function describeResult(result) {
        const details = [];

        if (result.error_message) {
            details.push(result.error_message);
        }

        if (result.error_code) {
            details.push(`Code: ${result.error_code}`);
        }

        if (result.job_id) {
            details.push(`Job: ${result.job_id}`);
        }

        if (result.request_id) {
            details.push(`Request: ${result.request_id}`);
        }

        return details.join(' ');
    }

    function toneForStatus(status) {
        if (status === 'failed') {
            return 'error';
        }

        if (status === 'blocked' || status === 'cancelled') {
            return 'warning';
        }

        if (
            status === 'presented' ||
            status === 'submitted' ||
            status === 'completed'
        ) {
            return 'success';
        }

        return 'neutral';
    }

    async function requestJson(url, options = {}) {
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
                result.message ??
                'The native printing request failed.';

            const error = new Error(message);
            error.result = result;

            throw error;
        }

        return result;
    }

    async function loadAvailability() {
        try {
            const result = await requestJson(
                endpoints.availability,
            );

            capabilities.preview =
                result.preview_supported === true;

            capabilities.print =
                result.printing_supported === true;

            previewCapability.textContent = capabilities.preview
                ? 'Available'
                : 'Unavailable';

            printCapability.textContent = capabilities.print
                ? 'Available'
                : 'Unavailable';

            availabilityMessage.textContent = result.available
                ? 'Native PDF preview and Android printing are ready.'
                : (
                    result.error_message ??
                    'Native PDF features are unavailable.'
                );
        } catch (error) {
            capabilities.preview = false;
            capabilities.print = false;

            previewCapability.textContent = 'Unavailable';
            printCapability.textContent = 'Unavailable';
            availabilityMessage.textContent = error.message;

            setStatus(
                'error',
                'Availability check failed',
                error.message,
            );
        } finally {
            updateButtons();
        }
    }

    function schedulePoll(requestId, action) {
        window.clearTimeout(pollTimer);

        pollTimer = window.setTimeout(
            () => pollStatus(requestId, action),
            pollIntervalMilliseconds,
        );
    }

    async function pollStatus(requestId, action) {
        pollAttempts += 1;

        if (pollAttempts > maximumPollAttempts) {
            busy = false;
            updateButtons();

            setStatus(
                'warning',
                'Status polling stopped',
                'The native action exceeded the ten-minute polling window.',
            );

            return;
        }

        const statusUrl = endpoints.status.replace(
            '__REQUEST_ID__',
            encodeURIComponent(requestId),
        );

        try {
            const result = await requestJson(statusUrl);
            const status = result.status ?? 'pending';

            if (status !== 'pending') {
                setStatus(
                    toneForStatus(status),
                    statusLabels[status] ?? 'Native status received',
                    describeResult(result) || (
                        action === 'preview'
                            ? 'Preview state updated.'
                            : 'Print state updated.'
                    ),
                );
            }

            const terminal =
                result.terminal === true ||
                terminalStatuses.has(status);

            if (terminal) {
                busy = false;
                updateButtons();

                return;
            }

            schedulePoll(requestId, action);
        } catch (error) {
            busy = false;
            updateButtons();

            setStatus(
                'error',
                'Status check failed',
                error.message,
            );
        }
    }

    async function startAction(action) {
        if (busy) {
            return;
        }

        busy = true;
        pollAttempts = 0;
        updateButtons();

        setStatus(
            'neutral',
            action === 'preview'
                ? 'Starting preview'
                : 'Starting print dialog',
            'Waiting for the native bridge to accept the request.',
        );

        try {
            const result = await requestJson(
                endpoints[action],
                {
                    method: 'POST',
                },
            );

            if (result.accepted !== true) {
                throw new Error(
                    result.error_message ??
                    'The native action was rejected.',
                );
            }

            setStatus(
                'neutral',
                statusLabels.accepted,
                describeResult(result),
            );

            schedulePoll(result.request_id, action);
        } catch (error) {
            busy = false;
            updateButtons();

            setStatus(
                'error',
                action === 'preview'
                    ? 'Preview could not start'
                    : 'Print could not start',
                error.message,
            );
        }
    }

    previewButton.addEventListener('click', () => {
        startAction('preview');
    });

    printButton.addEventListener('click', () => {
        startAction('print');
    });

    window.addEventListener('beforeunload', () => {
        window.clearTimeout(pollTimer);
    });

    loadAvailability();
})();
</script>
@endpush
