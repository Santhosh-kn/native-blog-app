@extends('layouts.app')

@section('title', 'Document Picker')

@section('content')
    <style>
        .document-picker-page {
            display: grid;
            gap: 16px;
        }

        .document-picker-intro h2,
        .document-picker-form h2,
        .document-picker-selection h2 {
            margin: 0 0 8px;
            font-size: 18px;
        }

        .document-picker-intro p,
        .document-picker-form p,
        .document-picker-selection p {
            margin: 0;
            color: var(--text-muted);
            font-size: 13px;
            line-height: 1.5;
        }

        .document-picker-badge {
            display: inline-flex;
            align-items: center;
            margin-top: 12px;
            padding: 5px 9px;
            border: 1px solid var(--border);
            border-radius: 999px;
            color: var(--primary);
            background: color-mix(in srgb, var(--primary) 8%, transparent);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.03em;
            text-transform: uppercase;
        }

        .document-picker-fieldset {
            margin: 18px 0 0;
            padding: 0;
            border: 0;
        }

        .document-picker-fieldset legend,
        .document-picker-label {
            display: block;
            margin-bottom: 8px;
            color: var(--text);
            font-size: 13px;
            font-weight: 700;
        }

        .document-picker-types {
            display: grid;
            gap: 8px;
        }

        .document-picker-type {
            display: grid;
            grid-template-columns: auto minmax(0, 1fr);
            gap: 10px;
            align-items: start;
            padding: 11px 12px;
            border: 1px solid var(--border);
            border-radius: 10px;
            background: var(--surface);
            cursor: pointer;
        }

        .document-picker-type:has(input:checked) {
            border-color: var(--primary);
            background: color-mix(in srgb, var(--primary) 7%, var(--surface));
        }

        .document-picker-type input {
            width: 18px;
            height: 18px;
            margin: 1px 0 0;
            accent-color: var(--primary);
        }

        .document-picker-type code {
            display: block;
            overflow-wrap: anywhere;
            color: var(--text);
            font-family: ui-monospace, SFMono-Regular, Consolas, monospace;
            font-size: 12px;
            line-height: 1.45;
        }

        .document-picker-size-field {
            margin-top: 18px;
        }

        .document-picker-size-row {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 8px;
            align-items: center;
        }

        .document-picker-size-input {
            width: 100%;
            min-width: 0;
            padding: 11px 12px;
            border: 1px solid var(--border);
            border-radius: 10px;
            color: var(--text);
            background: var(--surface);
            font: inherit;
        }

        .document-picker-size-unit {
            color: var(--text-muted);
            font-size: 13px;
            font-weight: 700;
        }

        .document-picker-help {
            margin-top: 7px !important;
        }

        .document-picker-submit {
            margin-top: 18px;
        }

        .document-picker-submit:disabled {
            cursor: wait;
            opacity: 0.65;
        }

        .document-picker-status {
            padding: 14px;
            border: 1px solid var(--border);
            border-radius: 12px;
            background: var(--surface);
        }

        .document-picker-status[data-tone="info"] {
            border-color: color-mix(in srgb, var(--primary) 55%, var(--border));
            background: color-mix(in srgb, var(--primary) 8%, var(--surface));
        }

        .document-picker-status[data-tone="success"] {
            border-color: #86c89a;
            background: #effaf2;
        }

        .document-picker-status[data-tone="warning"] {
            border-color: #e2bd6b;
            background: #fff8e8;
        }

        .document-picker-status[data-tone="error"] {
            border-color: #df8e8e;
            background: #fff1f1;
        }

        .document-picker-status strong {
            display: block;
            margin-bottom: 4px;
            color: var(--text);
            font-size: 14px;
        }

        .document-picker-status p {
            margin: 0;
            color: var(--text-muted);
            font-size: 13px;
            line-height: 1.45;
        }

        .document-picker-selection[hidden] {
            display: none;
        }

        .document-picker-metadata {
            display: grid;
            gap: 10px;
            margin: 14px 0 0;
        }

        .document-picker-metadata div {
            display: grid;
            grid-template-columns: minmax(90px, 0.35fr) minmax(0, 1fr);
            gap: 12px;
            padding-top: 10px;
            border-top: 1px solid var(--border);
        }

        .document-picker-metadata dt {
            color: var(--text-muted);
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .document-picker-metadata dd {
            min-width: 0;
            margin: 0;
            overflow-wrap: anywhere;
            color: var(--text);
            font-size: 13px;
            font-weight: 600;
        }
    </style>

    <div class="document-picker-page">
        <section class="card document-picker-intro">
            <h2>Choose one document securely</h2>
            <p>
                Android's system picker grants access to one selected document.
                The plugin copies it immediately into application-private storage
                without requesting broad storage permission.
            </p>
            <span class="document-picker-badge">
                Android Storage Access Framework
            </span>
        </section>

        <section class="card document-picker-form">
            <h2>Selection limits</h2>
            <p>
                Choose the document types and maximum size accepted by this request.
            </p>

            <form id="document-picker-form" novalidate>
                <fieldset class="document-picker-fieldset">
                    <legend>Allowed MIME types</legend>

                    <div class="document-picker-types">
                        @foreach ($allowedMimeTypes as $mimeType)
                            <label class="document-picker-type">
                                <input
                                    type="checkbox"
                                    name="mime_types[]"
                                    value="{{ $mimeType }}"
                                    checked
                                >
                                <code>{{ $mimeType }}</code>
                            </label>
                        @endforeach
                    </div>
                </fieldset>

                <div class="document-picker-size-field">
                    <label
                        for="document-picker-max-size"
                        class="document-picker-label"
                    >
                        Maximum document size
                    </label>

                    <div class="document-picker-size-row">
                        <input
                            type="number"
                            id="document-picker-max-size"
                            class="document-picker-size-input"
                            min="1"
                            max="{{ intdiv($maximumMaxSize, 1_048_576) }}"
                            step="1"
                            value="{{ intdiv($defaultMaxSize, 1_048_576) }}"
                            inputmode="numeric"
                            required
                        >
                        <span class="document-picker-size-unit">MB</span>
                    </div>

                    <p class="document-picker-help">
                        Maximum supported by this demo:
                        {{ intdiv($maximumMaxSize, 1_048_576) }} MB.
                    </p>
                </div>

                <button
                    type="submit"
                    id="document-picker-submit"
                    class="btn btn-primary btn-block document-picker-submit"
                >
                    Open system document picker
                </button>
            </form>
        </section>

        <section
            id="document-picker-status"
            class="document-picker-status"
            data-tone="neutral"
            role="status"
            aria-live="polite"
        >
            <strong id="document-picker-status-title">Ready</strong>
            <p id="document-picker-status-message">
                Configure the request, then open Android's document picker.
            </p>
        </section>

        <section
            id="document-picker-selection"
            class="card document-picker-selection"
            @if ($selection === null) hidden @endif
        >
            <h2>Selected document</h2>
            <p>
                Only safe metadata is shown. The private storage path remains
                server-side and is never added to this page.
            </p>

            <dl class="document-picker-metadata">
                <div>
                    <dt>Name</dt>
                    <dd id="document-picker-file-name">
                        {{ $selection['original_name'] ?? 'Not available' }}
                    </dd>
                </div>
                <div>
                    <dt>Type</dt>
                    <dd id="document-picker-mime-type">
                        {{ $selection['mime_type'] ?? 'Not available' }}
                    </dd>
                </div>
                <div>
                    <dt>Size</dt>
                    <dd id="document-picker-file-size">
                        @if ($selection !== null)
                            {{ number_format($selection['size'] / 1_048_576, 2) }} MB
                        @else
                            Not available
                        @endif
                    </dd>
                </div>
            </dl>
        </section>
    </div>
@endsection

@push('scripts')
<script>
(() => {
    'use strict';

    const endpoints = Object.freeze({
        pick: {{ Illuminate\Support\Js::from(
            route('native-document-picker.pick'),
        ) }},
        status: {{ Illuminate\Support\Js::from(
            route(
                'native-document-picker.status',
                ['requestId' => '__REQUEST_ID__'],
            ),
        ) }},
    });

    const allowedMimeTypes = new Set(
        {{ Illuminate\Support\Js::from(array_values($allowedMimeTypes)) }},
    );
    const maximumMaxSize = {{ Illuminate\Support\Js::from($maximumMaxSize) }};
    const initialSelection = {{ Illuminate\Support\Js::from($selection) }};

    const mebibyte = 1024 * 1024;
    const pollIntervalMilliseconds = 750;
    const maximumPollAttempts = 240;
    const activeRequestStorageKey =
        'native-document-picker.active-request-id';

    const form = document.getElementById('document-picker-form');
    const submitButton = document.getElementById(
        'document-picker-submit',
    );
    const maxSizeInput = document.getElementById(
        'document-picker-max-size',
    );
    const statusPanel = document.getElementById(
        'document-picker-status',
    );
    const statusTitle = document.getElementById(
        'document-picker-status-title',
    );
    const statusMessage = document.getElementById(
        'document-picker-status-message',
    );
    const selectionPanel = document.getElementById(
        'document-picker-selection',
    );
    const fileName = document.getElementById(
        'document-picker-file-name',
    );
    const mimeType = document.getElementById(
        'document-picker-mime-type',
    );
    const fileSize = document.getElementById(
        'document-picker-file-size',
    );
    const csrfToken = document
        .querySelector('meta[name="csrf-token"]')
        ?.getAttribute('content') ?? '';

    let activeRequestId = null;
    let pollAttempts = 0;
    let pollTimer = null;

    function isObject(value) {
        return value !== null &&
            typeof value === 'object' &&
            !Array.isArray(value);
    }

    function validRequestId(value) {
        return typeof value === 'string' &&
            /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i
                .test(value);
    }

    function storedRequestId() {
        try {
            const requestId = window.sessionStorage.getItem(
                activeRequestStorageKey,
            );

            if (validRequestId(requestId)) {
                return requestId;
            }

            window.sessionStorage.removeItem(activeRequestStorageKey);
        } catch {
            // The controller session still protects request ownership.
        }

        return null;
    }

    function rememberActiveRequest(requestId) {
        activeRequestId = requestId;

        try {
            window.sessionStorage.setItem(
                activeRequestStorageKey,
                requestId,
            );
        } catch {
            // Polling still works while the current page remains alive.
        }
    }

    function forgetActiveRequest() {
        activeRequestId = null;

        try {
            window.sessionStorage.removeItem(activeRequestStorageKey);
        } catch {
            // The stored value contains only a request UUID.
        }
    }

    function setBusy(busy) {
        submitButton.disabled = busy;

        document
            .querySelectorAll('input[name="mime_types[]"]')
            .forEach((input) => {
                input.disabled = busy;
            });

        maxSizeInput.disabled = busy;
    }

    function setStatus(tone, title, message) {
        statusPanel.dataset.tone = tone;
        statusTitle.textContent = title;
        statusMessage.textContent = message;
    }

    function formatBytes(bytes) {
        if (!Number.isInteger(bytes) || bytes < 0) {
            return 'Not available';
        }

        if (bytes < 1024) {
            return `${bytes} B`;
        }

        if (bytes < mebibyte) {
            return `${(bytes / 1024).toFixed(1)} KB`;
        }

        return `${(bytes / mebibyte).toFixed(2)} MB`;
    }

    function validSelection(selection) {
        return isObject(selection) &&
            typeof selection.request_id === 'string' &&
            typeof selection.original_name === 'string' &&
            typeof selection.mime_type === 'string' &&
            Number.isInteger(selection.size) &&
            selection.size >= 0 &&
            !Object.prototype.hasOwnProperty.call(selection, 'path');
    }

    function renderSelection(selection) {
        if (!validSelection(selection)) {
            return false;
        }

        fileName.textContent = selection.original_name;
        mimeType.textContent = selection.mime_type;
        fileSize.textContent = formatBytes(selection.size);
        selectionPanel.hidden = false;

        return true;
    }

    function clearSelection() {
        fileName.textContent = 'Not available';
        mimeType.textContent = 'Not available';
        fileSize.textContent = 'Not available';
        selectionPanel.hidden = true;
    }

    function messageFromPayload(payload, fallback) {
        if (!isObject(payload)) {
            return fallback;
        }

        for (const key of ['error_message', 'message']) {
            if (
                typeof payload[key] === 'string' &&
                payload[key].trim() !== ''
            ) {
                return payload[key].trim();
            }
        }

        if (isObject(payload.errors)) {
            for (const messages of Object.values(payload.errors)) {
                if (
                    Array.isArray(messages) &&
                    typeof messages[0] === 'string'
                ) {
                    return messages[0];
                }
            }
        }

        return fallback;
    }

    async function requestJson(url, options = {}) {
        const response = await fetch(url, {
            credentials: 'same-origin',
            ...options,
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                ...(options.headers ?? {}),
            },
        });

        const responseText = await response.text();
        let payload = {};

        if (responseText !== '') {
            try {
                payload = JSON.parse(responseText);
            } catch {
                payload = {};
            }
        }

        if (!response.ok) {
            const error = new Error(messageFromPayload(
                payload,
                `Request failed with status ${response.status}.`,
            ));

            error.httpStatus = response.status;
            throw error;
        }

        return payload;
    }

    function selectedMimeTypes() {
        return Array.from(
            document.querySelectorAll(
                'input[name="mime_types[]"]:checked',
            ),
        )
            .map((input) => input.value)
            .filter((value) => allowedMimeTypes.has(value));
    }

    function requestOptions() {
        const mimeTypes = selectedMimeTypes();
        const maximumMegabytes = Number(maxSizeInput.value);
        const maxSize = maximumMegabytes * mebibyte;

        if (mimeTypes.length === 0) {
            throw new Error('Select at least one allowed MIME type.');
        }

        if (
            !Number.isInteger(maximumMegabytes) ||
            maximumMegabytes < 1 ||
            !Number.isSafeInteger(maxSize) ||
            maxSize > maximumMaxSize
        ) {
            throw new Error(
                `Enter a whole-number size between 1 and ` +
                `${maximumMaxSize / mebibyte} MB.`,
            );
        }

        return {
            mime_types: mimeTypes,
            max_size: maxSize,
        };
    }

    function clearPollTimer() {
        if (pollTimer !== null) {
            window.clearTimeout(pollTimer);
            pollTimer = null;
        }
    }

    function finishRequest() {
        clearPollTimer();
        forgetActiveRequest();
        pollAttempts = 0;
        setBusy(false);
    }

    function schedulePoll(delay = pollIntervalMilliseconds) {
        clearPollTimer();

        if (activeRequestId === null) {
            return;
        }

        pollTimer = window.setTimeout(() => {
            void pollStatus();
        }, delay);
    }

    async function pollStatus() {
        const requestId = activeRequestId;

        if (requestId === null) {
            return;
        }

        pollAttempts += 1;

        if (pollAttempts > maximumPollAttempts) {
            finishRequest();
            setStatus(
                'warning',
                'Status polling stopped',
                'The document picker did not return within the expected time.',
            );

            return;
        }

        const statusUrl = endpoints.status.replace(
            '__REQUEST_ID__',
            encodeURIComponent(requestId),
        );

        try {
            const result = await requestJson(statusUrl);

            if (requestId !== activeRequestId) {
                return;
            }

            if (result.status === 'pending' && result.terminal !== true) {
                setStatus(
                    'info',
                    'Waiting for Android',
                    'Complete or cancel the system document picker.',
                );
                schedulePoll();

                return;
            }

            if (
                result.status === 'succeeded' &&
                result.terminal === true &&
                renderSelection(result.document)
            ) {
                finishRequest();
                setStatus(
                    'success',
                    'Document copied privately',
                    'Safe metadata is available below.',
                );

                return;
            }

            if (result.status === 'cancelled' && result.terminal === true) {
                clearSelection();
                finishRequest();
                setStatus('warning', 'Selection cancelled', 'No document was selected.');
                return;
            }

            finishRequest();
            setStatus(
                'error',
                'Document selection failed',
                messageFromPayload(
                    result,
                    'The native document result could not be accepted.',
                ),
            );
        } catch (error) {
            if (requestId !== activeRequestId) {
                return;
            }

            if (
                error?.httpStatus === 503 &&
                pollAttempts <= maximumPollAttempts
            ) {
                setStatus(
                    'warning',
                    'Native status temporarily unavailable',
                    'The app will retry automatically.',
                );
                schedulePoll(1500);

                return;
            }

            finishRequest();
            setStatus(
                'error',
                'Status check failed',
                error instanceof Error
                    ? error.message
                    : 'The native document status could not be read.',
            );
        }
    }

    async function startPicker(event) {
        event.preventDefault();

        if (activeRequestId !== null) {
            return;
        }

        let options;

        try {
            options = requestOptions();
        } catch (error) {
            setStatus(
                'error',
                'Invalid selection limits',
                error instanceof Error
                    ? error.message
                    : 'Review the document-selection limits.',
            );

            return;
        }

        setBusy(true);
        setStatus(
            'info',
            'Opening Android picker',
            'Choose one document or cancel the system picker.',
        );

        try {
            const result = await requestJson(endpoints.pick, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
                body: JSON.stringify(options),
            });

            if ( result.accepted !== true || !validRequestId(result.request_id)) {
                throw new Error(messageFromPayload(result, 'The native picker did not accept the request.' ));
            }

            clearSelection();
            rememberActiveRequest(result.request_id);
            pollAttempts = 0;
            setStatus(
                'info',
                'Waiting for Android',
                'Complete or cancel the system document picker.',
            );
            schedulePoll();
        } catch (error) {
            finishRequest();
            setStatus(
                'error',
                'Could not open document picker',
                error instanceof Error
                    ? error.message
                    : 'The document picker could not be started.',
            );
        }
    }

    form.addEventListener('submit', (event) => {
        void startPicker(event);
    });

    document.addEventListener('visibilitychange', () => {
        if (
            document.visibilityState === 'visible' &&
            activeRequestId !== null
        ) {
            schedulePoll(0);
        }
    });

    window.addEventListener('pageshow', () => {
        if (activeRequestId !== null) {
            schedulePoll(0);
        }
    });

    if (validSelection(initialSelection)) {
        renderSelection(initialSelection);
    }

    const resumedRequestId = storedRequestId();

    if (resumedRequestId !== null) {
        rememberActiveRequest(resumedRequestId);
        setBusy(true);
        setStatus(
            'info',
            'Resuming document status',
            'Checking the last document-picker request.',
        );
        schedulePoll(0);
    }
})();
</script>
@endpush
