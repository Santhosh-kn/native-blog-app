@extends('layouts.app')

@section('title', 'Native NFC')
@section('page-icon', 'nfc')
@section('subtitle', 'Read and write plain text using Android NFC tags.')

@section('content')
<div
    id="nfc-page"
    class="contacts-grid"
    data-availability="{{ route('native-nfc.availability') }}"
    data-diagnostics="{{ route('native-nfc.diagnostics') }}"
    data-scan="{{ route('native-nfc.scan') }}"
    data-write="{{ route('native-nfc.write') }}"
    data-status="{{ route('native-nfc.status', ['id' => '__ID__']) }}"
    data-stop="{{ route('native-nfc.stop', ['id' => '__ID__']) }}"
    data-consume="{{ route('native-nfc.consume', ['id' => '__ID__']) }}"
>
    <input id="nfc-csrf" type="hidden" value="{{ csrf_token() }}">

    <section class="card">
        <h2 class="card-heading">@include('partials.ui-icon', ['name' => 'nfc']) Android NFC readiness</h2>
        <p>Check whether NFC hardware is available and enabled on this Android device.</p>
        <button id="nfc-availability" type="button" class="btn btn-primary">
            Check availability
        </button>
        <pre id="nfc-readiness">Availability has not been checked.</pre>
    </section>

    <section class="card">
        <h2 class="card-heading">@include('partials.ui-icon', ['name' => 'nfc']) Safe native diagnostics</h2>
        <p>Verify status lookup and one-time result consumption using an unused request ID.</p>
        <button id="nfc-diagnostics" type="button" class="btn btn-secondary">
            Run safe diagnostics
        </button>
        <pre id="nfc-diagnostic-result">Diagnostics have not been run.</pre>
    </section>

    <section class="card">
        <h2 class="card-heading">@include('partials.ui-icon', ['name' => 'nfc']) Read NFC tag</h2>
        <p>Start a scan, then bring an NDEF text tag close to the phone.</p>
        <button id="nfc-scan" type="button" class="btn btn-primary">
            Start scan
        </button>
    </section>

    <section class="card">
        <h2 class="card-heading">@include('partials.ui-icon', ['name' => 'nfc']) Write NFC tag</h2>
        <p>Enter plain text, start writing, then bring a writable NDEF tag close to the phone.</p>
        <label for="nfc-write-text">Text to write</label>
        <textarea id="nfc-write-text" rows="4" maxlength="4096" placeholder="Enter text to write to the NFC tag"></textarea>
        <button id="nfc-write" type="button" class="btn btn-primary">
            Start write
        </button>
    </section>

    <section class="card">
        <h2 class="card-heading">@include('partials.ui-icon', ['name' => 'nfc']) Request status</h2>
        <label for="nfc-request">Request from this session</label>
        <select id="nfc-request">
            <option value="">Choose a request</option>
            @foreach ($requestIds as $requestId)
                <option value="{{ $requestId }}" @selected($loop->first)>
                    {{ $requestId }}
                </option>
            @endforeach
        </select>

        <div class="contacts-actions">
            <button id="nfc-refresh" type="button" class="btn btn-secondary" disabled>
                Refresh status
            </button>
            <button id="nfc-stop" type="button" class="btn btn-secondary" disabled>
                Stop operation
            </button>
            <button id="nfc-consume" type="button" class="btn btn-secondary" disabled>
                Consume scan result
            </button>
        </div>

        <p>Scan text is returned only when you explicitly consume a completed scan result. Status responses contain metadata only.</p>
        <pre id="nfc-result">No request selected.</pre>
        <pre id="nfc-scan-text" hidden></pre>
    </section>

    <p id="nfc-message" role="status" aria-live="polite">Ready.</p>
</div>
@endsection

@push('scripts')
<script id="nfc-page-script">
(() => {
    'use strict';

    const root = document.getElementById('nfc-page');
    if (!root) return;

    const byId = id => document.getElementById(id);
    const selector = byId('nfc-request');
    const message = byId('nfc-message');
    const csrf = byId('nfc-csrf').value;

    const availabilityButton = byId('nfc-availability');
    const diagnosticsButton = byId('nfc-diagnostics');
    const scanButton = byId('nfc-scan');
    const writeButton = byId('nfc-write');
    const refreshButton = byId('nfc-refresh');
    const stopButton = byId('nfc-stop');
    const consumeButton = byId('nfc-consume');

    const metadataKeys = [
        'id', 'operation', 'status', 'consumed',
        'createdAtMs', 'completedAtMs', 'errorCode'
    ];

    const validId = value => typeof value === 'string' &&
        /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(value);

    let current = null;
    let busy = false;
    let timer = null;
    let pollsRemaining = 0;

    function metadata(value) {
        if (!value || !validId(value.id)) {
            throw new Error('Invalid NFC metadata');
        }

        const safe = {};

        for (const key of metadataKeys) {
            const item = value[key];

            if (
                item === null ||
                typeof item === 'boolean' ||
                (typeof item === 'number' && Number.isFinite(item)) ||
                (typeof item === 'string' && item.length <= 300)
            ) {
                safe[key] = item;
            }
        }

        return safe;
    }

    function remember(id) {
        if (![...selector.options].some(option => option.value === id)) {
            selector.add(new Option(id, id), 1);
        }

        selector.value = id;

        while (selector.options.length > 33) {
            selector.remove(selector.options.length - 1);
        }
    }

    function syncButtons() {
        availabilityButton.disabled = busy;
        diagnosticsButton.disabled = busy;
        scanButton.disabled = busy;
        writeButton.disabled = busy;
        selector.disabled = busy;

        refreshButton.disabled = busy || !validId(selector.value);

        stopButton.disabled = busy ||
            current?.id !== selector.value ||
            current?.status !== 'pending';

        consumeButton.disabled = busy ||
            current?.id !== selector.value ||
            current?.operation !== 'start_scan' ||
            current?.status !== 'read' ||
            current?.consumed !== false;
    }

    function showError(payload) {
        pollsRemaining = 0;
        current = null;

        if (validId(payload?.requestId)) {
            remember(payload.requestId);
        }

        byId('nfc-result').textContent = JSON.stringify({
            errorCode: payload?.errorCode ?? 'UNKNOWN_ERROR',
            errorMessage: payload?.errorMessage ?? 'The NFC request failed.',
            requestId: validId(payload?.requestId) ? payload.requestId : null
        }, null, 2);

        byId('nfc-scan-text').hidden = true;
        byId('nfc-scan-text').textContent = '';
        message.textContent = payload?.errorMessage ?? 'The NFC request failed.';
        syncButtons();
    }

    function showResult(payload) {
        current = metadata(payload.result);
        remember(current.id);

        byId('nfc-result').textContent = JSON.stringify({
            result: current
        }, null, 2);

        syncButtons();

        if (current.errorCode) {
            message.textContent = `Operation failed: ${current.errorCode}.`;
        } else {
            const messages = {
                pending: 'Waiting for an NFC tag. Bring the tag close to the phone.',
                read: 'NFC text was read. Select Consume scan result to retrieve it.',
                written: 'NDEF text write completed successfully.',
                cancelled: 'NFC operation cancelled.',
                failed: 'NFC operation failed.',
                unknown: 'The operation outcome is unknown. Do not assume a write succeeded.',
                not_found: 'Request was not found.'
            };

            message.textContent = messages[current.status] ??
                `Request status: ${current.status}.`;
        }
    }

    async function api(url, method = 'GET', body = null) {
        const headers = { Accept: 'application/json' };

        if (method === 'POST') {
            headers['Content-Type'] = 'application/json';
            headers['X-CSRF-TOKEN'] = csrf;
        }

        const response = await fetch(url, {
            method,
            headers,
            credentials: 'same-origin',
            cache: 'no-store',
            body: method === 'POST' ? JSON.stringify(body ?? {}) : null
        });

        if (response.redirected) {
            const destination = new URL(response.url);

            if (destination.origin === window.location.origin) {
                window.location.assign(destination.href);
            }

            throw new Error('Authentication required');
        }

        if (![200, 404, 409, 422, 500, 503].includes(response.status)) {
            throw new Error('Request failed');
        }

        return response.json();
    }

    function schedulePoll() {
        window.clearTimeout(timer);
        timer = null;

        if (
            current?.status !== 'pending' ||
            current.id !== selector.value ||
            pollsRemaining <= 0
        ) return;

        timer = window.setTimeout(() => {
            if (document.hidden || busy) {
                schedulePoll();
                return;
            }

            pollsRemaining--;
            run(() => refresh(false));
        }, 2000);
    }

    async function run(action) {
        if (busy) return;

        busy = true;
        syncButtons();

        try {
            await action();
        } catch {
            pollsRemaining = 0;
            message.textContent =
                'Request failed. Check that you are signed in and the device is unlocked.';
        } finally {
            busy = false;
            syncButtons();
            schedulePoll();
        }
    }

    async function refresh(resetPolling) {
        const id = selector.value;
        if (!validId(id)) return;

        if (resetPolling) pollsRemaining = 60;

        const payload = await api(
            root.dataset.status.replace('__ID__', id)
        );

        if (payload.result) {
            showResult(payload);
        } else {
            showError(payload);
        }
    }

    availabilityButton.addEventListener('click', () => run(async () => {
        const payload = await api(root.dataset.availability);

        byId('nfc-readiness').textContent = JSON.stringify({
            supported: payload.supported === true,
            enabled: payload.enabled === true,
            availability: payload.availability ?? 'unknown',
            platform: payload.platform ?? 'android'
        }, null, 2);

        message.textContent = payload.supported === true
            ? (payload.enabled === true
                ? 'NFC hardware is available and enabled.'
                : 'NFC hardware is available but disabled.')
            : 'NFC hardware is not available on this device.';
    }));

    diagnosticsButton.addEventListener('click', () => run(async () => {
        const payload = await api(root.dataset.diagnostics, 'POST');
        const operations = {};

        const statusOperation = payload.operations?.getStatus;

        if (statusOperation) {
            operations.getStatus = {
                method: statusOperation.method,
                idMatches: statusOperation.idMatches === true,
                result: metadata(statusOperation.result)
            };
        }

        const consumeOperation = payload.operations?.consumeResult;

        if (consumeOperation) {
            operations.consumeResult = {
                method: consumeOperation.method,
                expectedErrorCode: consumeOperation.expectedErrorCode,
                actualErrorCode: consumeOperation.actualErrorCode,
                passed: consumeOperation.passed === true
            };
        }

        byId('nfc-diagnostic-result').textContent = JSON.stringify({
            passed: payload.passed === true,
            operations
        }, null, 2);

        message.textContent = payload.passed === true
            ? 'Safe NFC diagnostics passed.'
            : 'Safe NFC diagnostics failed.';
    }));

    scanButton.addEventListener('click', () => run(async () => {
        pollsRemaining = 60;
        byId('nfc-scan-text').hidden = true;
        byId('nfc-scan-text').textContent = '';

        const payload = await api(root.dataset.scan, 'POST');

        if (payload.result) {
            showResult(payload);
        } else {
            showError(payload);
        }
    }));

    writeButton.addEventListener('click', () => run(async () => {
        const text = byId('nfc-write-text').value;

        if (!text.trim()) {
            message.textContent = 'Enter some text before starting a write.';
            return;
        }

        pollsRemaining = 60;

        const payload = await api(root.dataset.write, 'POST', { text });

        if (payload.result) {
            showResult(payload);
        } else {
            showError(payload);
        }
    }));

    refreshButton.addEventListener('click', () => run(() => refresh(true)));

    stopButton.addEventListener('click', () => run(async () => {
        const id = selector.value;
        if (!validId(id)) return;

        const payload = await api(
            root.dataset.stop.replace('__ID__', id),
            'POST'
        );

        if (payload.result) {
            pollsRemaining = 0;
            showResult(payload);
        } else {
            showError(payload);
        }
    }));

    consumeButton.addEventListener('click', () => run(async () => {
        const id = selector.value;
        if (!validId(id)) return;

        const payload = await api(
            root.dataset.consume.replace('__ID__', id),
            'POST'
        );

        if (payload.result) {
            current = metadata(payload.result);
            remember(current.id);

            if (typeof payload.text === 'string') {
                byId('nfc-scan-text').textContent = payload.text;
                byId('nfc-scan-text').hidden = false;
            }

            byId('nfc-result').textContent = JSON.stringify({
                result: current,
                textReturned: typeof payload.text === 'string'
            }, null, 2);

            message.textContent = typeof payload.text === 'string'
                ? 'Scan result consumed. The text is displayed below.'
                : 'No scan text was returned.';

        } else {
            showError(payload);
        }
    }));

    selector.addEventListener('change', () => {
        current = null;
        pollsRemaining = 0;
        window.clearTimeout(timer);
        byId('nfc-scan-text').hidden = true;
        byId('nfc-scan-text').textContent = '';
        syncButtons();

        if (validId(selector.value)) {
            run(() => refresh(true));
        } else {
            byId('nfc-result').textContent = 'No request selected.';
        }
    });

    document.addEventListener('visibilitychange', () => {
        if (!document.hidden && current?.status === 'pending') {
            run(() => refresh(true));
        }
    });

    window.addEventListener('pagehide', () => {
        window.clearTimeout(timer);
    });

    syncButtons();

    if (validId(selector.value)) {
        run(() => refresh(true));
    }
})();
</script>
@endpush