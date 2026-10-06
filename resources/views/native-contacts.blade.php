@extends('layouts.app')

@section('title', 'Native Contacts')

@section('app-bar-action')
    <a href="{{ route('home') }}" class="btn btn-secondary btn-sm">Back</a>
@endsection

@section('content')
<style>
    .contacts-grid { display: grid; gap: 12px; }
    .contacts-grid h2 { margin: 0 0 10px; font-size: 17px; }
    .contacts-actions { display: flex; flex-wrap: wrap; gap: 8px; margin: 12px 0; }
    .contacts-grid pre {
        white-space: pre-wrap;
        overflow-wrap: anywhere;
        font-size: 12px;
        margin: 10px 0 0;
    }
    .contacts-grid select { width: 100%; padding: 10px; }
    .contacts-grid p { font-size: 13px; line-height: 1.5; }
</style>

<div
    id="contacts-page"
    class="contacts-grid"
    data-availability="{{ route('native-contacts.availability') }}"
    data-diagnostics="{{ route('native-contacts.diagnostics') }}"
    data-pick="{{ route('native-contacts.pick') }}"
    data-create="{{ route('native-contacts.create') }}"
    data-status="{{ route('native-contacts.status', ['id' => '__ID__']) }}"
    data-consume="{{ route('native-contacts.consume', ['id' => '__ID__']) }}"
    data-open="{{ route('native-contacts.open-selection', ['id' => '__ID__']) }}"
>
    <input id="contacts-csrf" type="hidden" value="{{ csrf_token() }}">

    <section class="card">
        <h2>Android readiness</h2>
        <p>Check whether this device can select contacts and launch contact editors or viewers.</p>
        <button id="contacts-availability" type="button" class="btn btn-primary">
            Check availability
        </button>
        <pre id="contacts-readiness">Availability has not been checked.</pre>
    </section>

    <section class="card">
        <h2>Safe native diagnostics</h2>
        <p>Check status and result consumption using a fresh, unused request ID.</p>
        <button id="contacts-diagnostics" type="button" class="btn btn-secondary">
            Run safe diagnostics
        </button>
        <pre id="contacts-diagnostic-result">Diagnostics have not been run.</pre>
    </section>

    <section class="card">
        <h2>Manual contact checks</h2>
        <p>Use synthetic contacts for these checks. Selected contact details stay private.</p>
        <div class="contacts-actions">
            <button type="button" class="btn btn-primary" data-mode="contact" disabled>
                Pick contact
            </button>
            <button type="button" class="btn btn-primary" data-mode="phone" disabled>
                Pick phone
            </button>
            <button type="button" class="btn btn-primary" data-mode="email" disabled>
                Pick email
            </button>
            <button id="contacts-create" type="button" class="btn btn-secondary" disabled>
                Open demo contact editor
            </button>
        </div>
        <p>The editor is prefilled with demo values. A launched editor does not confirm that a contact was saved.</p>
    </section>

    <section class="card">
        <h2>Request status</h2>
        <label for="contacts-request">Request from this session</label>
        <select id="contacts-request">
            <option value="">Choose a request</option>
            @foreach ($requestIds as $requestId)
                <option value="{{ $requestId }}" @selected($loop->first)>
                    {{ $requestId }}
                </option>
            @endforeach
        </select>

        <div class="contacts-actions">
            <button id="contacts-refresh" type="button" class="btn btn-secondary" disabled>
                Refresh status
            </button>
            <button id="contacts-consume" type="button" class="btn btn-secondary" disabled>
                Consume result
            </button>
            <button id="contacts-open" type="button" class="btn btn-secondary" disabled>
                Consume and open selected contact
            </button>
        </div>

        <p>Consume receives and discards the selected details. Repeat consumption checks return metadata only. Opening consumes the contact selection first.</p>
        <pre id="contacts-result">No request selected.</pre>
    </section>

    <p id="contacts-message" role="status" aria-live="polite">Ready.</p>
</div>
@endsection

@push('scripts')
<script>
(() => {
    'use strict';

    const root = document.getElementById('contacts-page');
    if (!root) return;

    const byId = id => document.getElementById(id);
    const selector = byId('contacts-request');
    const message = byId('contacts-message');
    const modeButtons = [...root.querySelectorAll('[data-mode]')];
    const availabilityButton = byId('contacts-availability');
    const diagnosticsButton = byId('contacts-diagnostics');
    const createButton = byId('contacts-create');
    const refreshButton = byId('contacts-refresh');
    const consumeButton = byId('contacts-consume');
    const openButton = byId('contacts-open');
    const csrf = byId('contacts-csrf').value;

    const metadataKeys = [
        'id', 'operation', 'mode', 'status', 'accepted', 'success',
        'cancelled', 'consumed', 'errorCode', 'errorMessage',
        'createdAtMs', 'completedAtMs'
    ];

    const validId = value => typeof value === 'string' &&
        /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/.test(value);

    let capabilities = {};
    let current = null;
    let busy = false;
    let timer = null;
    let pollsRemaining = 0;

    function metadata(value) {
        if (!value || !validId(value.id)) throw new Error('Invalid metadata');

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

    function syncButtons() {
        availabilityButton.disabled = busy;
        diagnosticsButton.disabled = busy;
        selector.disabled = busy;
        createButton.disabled = busy || capabilities.create !== true;

        for (const button of modeButtons) {
            const capability = {
                contact: 'pickContact',
                phone: 'pickPhone',
                email: 'pickEmail'
            }[button.dataset.mode];
            button.disabled = busy || capabilities[capability] !== true;
        }

        refreshButton.disabled = busy || !validId(selector.value);

        consumeButton.disabled = busy ||
            current?.id !== selector.value ||
            current?.operation !== 'pick' ||
            current?.status !== 'selected';

        openButton.disabled = busy ||
            capabilities.open !== true ||
            current?.id !== selector.value ||
            current?.operation !== 'pick' ||
            current?.mode !== 'contact' ||
            current?.status !== 'selected' ||
            current?.consumed !== false ||
            current?.errorCode !== null;
    }

    function remember(id) {
        if (![...selector.options].some(option => option.value === id)) {
            selector.add(new Option(id, id), 1);
        }
        selector.value = id;

        // Mirror the server's bounded request list.
        while (selector.options.length > 33) {
            selector.remove(selector.options.length - 1);
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

        if (![200, 409, 422, 500].includes(response.status)) {
            throw new Error('Request failed');
        }

        return response.json();
    }

    function showResult(payload) {
        current = metadata(payload.result);
        remember(current.id);

        const safe = { result: current };

        if (payload.selectionResult) {
            safe.selectionResult = metadata(payload.selectionResult);
        }
        if (typeof payload.selectionReceived === 'boolean') {
            safe.selectionReceived = payload.selectionReceived;
        }
        if (typeof payload.openAttempted === 'boolean') {
            safe.openAttempted = payload.openAttempted;
        }

        byId('contacts-result').textContent = JSON.stringify(safe, null, 2);

        if (payload.openAttempted === false) {
            message.textContent = 'Open requires an unconsumed contact selection.';
        } else if (current.errorCode) {
            message.textContent = current.errorMessage ?? 'The operation failed.';
        } else if (payload.selectionReceived === true) {
            message.textContent = 'Selection received and discarded.';
        } else if (current.status === 'launched') {
            message.textContent = 'Editor or viewer launched. Saving is not confirmed.';
        } else {
            message.textContent = `Request status: ${current.status}.`;
        }
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
            message.textContent = 'Request failed. Check that you are signed in and the device is unlocked.';
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

        showResult(await api(root.dataset.status.replace('__ID__', id)));
    }

    availabilityButton.addEventListener('click', () => run(async () => {
        const payload = await api(root.dataset.availability);
        const safeCapabilities = {};

        for (const key of ['pickContact', 'pickPhone', 'pickEmail', 'create', 'open']) {
            safeCapabilities[key] = payload.capabilities?.[key] === true;
        }
        capabilities = safeCapabilities;

        byId('contacts-readiness').textContent = JSON.stringify({
            available: payload.available === true,
            platform: 'android',
            apiLevel: Number.isInteger(payload.apiLevel) ? payload.apiLevel : null,
            minimumApiLevel: 33,
            capabilities
        }, null, 2);

        message.textContent = payload.available === true
            ? 'Android contact operations are available.'
            : 'Android contact operations are unavailable.';
    }));

    diagnosticsButton.addEventListener('click', () => run(async () => {
        const payload = await api(root.dataset.diagnostics, 'POST');
        const operations = {};

        for (const key of ['getStatus', 'consumeResult']) {
            const operation = payload.operations?.[key];
            if (!operation) continue;

            operations[key] = {
                idMatches: operation.idMatches === true,
                result: metadata(operation.result)
            };
        }

        byId('contacts-diagnostic-result').textContent = JSON.stringify({
            passed: payload.passed === true,
            operations
        }, null, 2);

        message.textContent = payload.passed === true
            ? 'Safe diagnostics passed.'
            : 'Safe diagnostics failed. Check Android bridge readiness.';
    }));

    for (const button of modeButtons) {
        button.addEventListener('click', () => run(async () => {
            pollsRemaining = 60;
            showResult(await api(root.dataset.pick, 'POST', {
                mode: button.dataset.mode
            }));
        }));
    }

    createButton.addEventListener('click', () => run(async () => {
        pollsRemaining = 60;
        showResult(await api(root.dataset.create, 'POST'));
    }));

    refreshButton.addEventListener('click', () => run(() => refresh(true)));

    consumeButton.addEventListener('click', () => run(async () => {
        showResult(await api(
            root.dataset.consume.replace('__ID__', selector.value),
            'POST'
        ));
    }));

    openButton.addEventListener('click', () => run(async () => {
        pollsRemaining = 60;
        showResult(await api(
            root.dataset.open.replace('__ID__', selector.value),
            'POST'
        ));
    }));

    selector.addEventListener('change', () => {
        current = null;
        pollsRemaining = 0;
        window.clearTimeout(timer);
        syncButtons();
        if (validId(selector.value)) run(() => refresh(true));
    });

    document.addEventListener('visibilitychange', () => {
        if (!document.hidden && current?.status === 'pending') {
            run(() => refresh(true));
        }
    });

    window.addEventListener('pagehide', () => window.clearTimeout(timer));

    syncButtons();
    if (validId(selector.value)) run(() => refresh(true));
})();
</script>
@endpush