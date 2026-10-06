@extends('layouts.app')

@section('title', 'Native Calendar')

@section('app-bar-action')
    <a href="{{ route('home') }}" class="btn btn-secondary btn-sm">Back</a>
@endsection

@section('content')
<style>
    .calendar-grid { display: grid; gap: 12px; }
    .calendar-grid h2 { margin: 0 0 10px; font-size: 17px; }
    .calendar-grid p { font-size: 13px; line-height: 1.5; }
    .calendar-actions { display: flex; flex-wrap: wrap; gap: 8px; margin: 12px 0; }
    .calendar-grid input:not([type="hidden"]), .calendar-grid select {
        width: 100%; padding: 10px; box-sizing: border-box;
    }
    .calendar-grid pre {
        white-space: pre-wrap; overflow-wrap: anywhere;
        font-size: 12px; margin: 10px 0 0;
    }
</style>

<div
    id="calendar-page"
    class="calendar-grid"
    data-availability="{{ route('native-calendar.availability') }}"
    data-diagnostics="{{ route('native-calendar.diagnostics') }}"
    data-create="{{ route('native-calendar.create') }}"
    data-open-date="{{ route('native-calendar.open-date') }}"
    data-open-event="{{ route('native-calendar.open-event') }}"
    data-status="{{ route('native-calendar.status', ['id' => '__ID__']) }}"
>
    <input id="calendar-csrf" type="hidden" value="{{ csrf_token() }}">

    <section class="card">
        <h2>Calendar readiness</h2>
        <p>Availability is checked automatically when this page opens. You can check again using the button below.</p>
        <button id="calendar-availability" type="button" class="btn btn-primary">
            Check availability
        </button>
        <pre id="calendar-readiness">Checking calendar availability...</pre>
    </section>

    <section class="card">
        <h2>Sample event editor</h2>
        <p>Each button opens Calendar with the title “Native Calendar Demo”, a sample description, and a demo location. Review the event before saving.</p>
        <div class="calendar-actions">
            <button type="button" class="btn btn-primary" data-preset="timed" disabled>
                Timed event
            </button>
            <button type="button" class="btn btn-primary" data-preset="all-day" disabled>
                All-day event
            </button>
            <button type="button" class="btn btn-primary" data-preset="recurring" disabled>
                Weekly event
            </button>
        </div>
        <p>Timed events start tomorrow at 10:00 in Asia/Kolkata and last one hour. The weekly preset requests three occurrences. The all-day preset covers tomorrow using UTC date boundaries.</p>
        <p>Calendar controls saving and discarding. A <strong>launched</strong> result confirms that the Calendar UI opened. Time-zone and recurrence hints depend on the receiving app.</p>
    </section>

    <section class="card">
        <h2>Calendar viewer</h2>
        <div class="calendar-actions">
            <button id="calendar-open-date" type="button" class="btn btn-secondary" disabled>
                Open tomorrow
            </button>
        </div>
        <label for="calendar-event-id">Known test event ID</label>
        <input
            id="calendar-event-id"
            type="text"
            inputmode="numeric"
            maxlength="16"
            autocomplete="off"
            spellcheck="false"
            placeholder="Enter a known event ID"
        >
        <div class="calendar-actions">
            <button id="calendar-open-event" type="button" class="btn btn-secondary" disabled>
                Open event
            </button>
        </div>
        <p>Use an ID from a test event. Availability confirms a viewer exists; it does not confirm that a particular event exists. The app does not search your calendar.</p>
    </section>

    <section class="card">
        <h2>Request status</h2>
        <label for="calendar-request">Request from this session</label>
        <select id="calendar-request">
            <option value="">Choose a request</option>
            @foreach ($requestIds as $requestId)
                <option value="{{ $requestId }}" @selected($loop->first)>
                    {{ $requestId }}
                </option>
            @endforeach
        </select>
        <div class="calendar-actions">
            <button id="calendar-refresh" type="button" class="btn btn-secondary" disabled>
                Refresh status
            </button>
        </div>
        <p>Status contains request metadata. An <strong>unknown</strong> outcome requires checking Calendar before attempting another event.</p>
        <pre id="calendar-result">No request selected.</pre>
    </section>

    <section class="card">
        <h2>Safe diagnostics</h2>
        <p>Check status for a fresh request ID without opening Calendar.</p>
        <button id="calendar-diagnostics" type="button" class="btn btn-secondary">
            Check unused request
        </button>
        <pre id="calendar-diagnostic-result">Diagnostics have not been run.</pre>
    </section>

    <p id="calendar-message" role="status" aria-live="polite">Ready.</p>
</div>
@endsection

@push('scripts')
<script>
(() => {
    'use strict';

    const root = document.getElementById('calendar-page');
    if (!root) return;

    const byId = id => document.getElementById(id);
    const selector = byId('calendar-request');
    const message = byId('calendar-message');
    const presetButtons = [...root.querySelectorAll('[data-preset]')];
    const availabilityButton = byId('calendar-availability');
    const diagnosticsButton = byId('calendar-diagnostics');
    const dateButton = byId('calendar-open-date');
    const eventButton = byId('calendar-open-event');
    const refreshButton = byId('calendar-refresh');
    const eventInput = byId('calendar-event-id');
    const csrf = byId('calendar-csrf').value;

    const keys = [
        'id', 'operation', 'target', 'status', 'accepted', 'success',
        'errorCode', 'errorMessage', 'createdAtMs', 'completedAtMs'
    ];
    const validId = value => typeof value === 'string' &&
        /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/.test(value);
    const validEventId = value => /^[1-9][0-9]{0,15}$/.test(value) &&
        (value.length < 16 || value <= '9007199254740991');

    let capabilities = {};
    let current = null;
    let busy = false;
    let timer = null;
    let pollsRemaining = 0;

    function metadata(value) {
        if (!value || typeof value !== 'object' || !validId(value.id)) {
            throw new Error('Calendar returned an invalid response.');
        }
        const safe = {};
        for (const key of keys) {
            const item = value[key];
            safe[key] = item === null ||
                typeof item === 'string' ||
                typeof item === 'boolean' ||
                (typeof item === 'number' && Number.isSafeInteger(item))
                ? item : null;
        }
        return safe;
    }

    function syncButtons() {
        const pending = current?.status === 'pending';
        availabilityButton.disabled = busy;
        diagnosticsButton.disabled = busy || pending;
        refreshButton.disabled = busy || !validId(selector.value);
        selector.disabled = busy;
        for (const button of presetButtons) {
            button.disabled = busy || pending || capabilities.createEvent !== true;
        }
        dateButton.disabled = busy || pending || capabilities.openDate !== true;
        eventButton.disabled = busy || pending ||
            capabilities.openEvent !== true || !validEventId(eventInput.value);
    }

    function remember(id) {
        if (![...selector.options].some(option => option.value === id)) {
            selector.insertBefore(new Option(id, id), selector.options[1] || null);
        }
        while (selector.options.length > 33) {
            selector.remove(selector.options.length - 1);
        }
        selector.value = id;
    }

    async function api(url, method = 'GET', body = null) {
        const abort = new AbortController();
        const timeout = window.setTimeout(() => abort.abort(), 15000);
        try {
            const response = await fetch(url, {
                method,
                credentials: 'same-origin',
                cache: 'no-store',
                signal: abort.signal,
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf
                },
                body: method === 'GET' ? null : JSON.stringify(body || {})
            });
            if (!response.ok) {
                throw new Error('Calendar request was rejected. Check your session and input.');
            }
            return await response.json();
        } catch (error) {
            if (error instanceof Error && error.message.startsWith('Calendar request was rejected')) {
                throw error;
            }
            throw new Error('Response unavailable. Refresh this page to recover tracked requests.');
        } finally {
            window.clearTimeout(timeout);
        }
    }

    function showResult(payload) {
        current = metadata(payload.result);
        remember(current.id);
        byId('calendar-result').textContent = JSON.stringify(current, null, 2);

        const descriptions = {
            pending: 'Waiting for Calendar UI to open.',
            launched: 'Calendar UI opened. Saving or discarding is handled in Calendar.',
            failed: 'The Calendar operation failed. Check the result code.',
            unknown: 'Outcome unknown. Check Calendar before creating another event.',
            not_found: 'No stored result exists for this request.'
        };
        message.textContent = descriptions[current.status] || 'Request status received.';
    }

    function schedulePoll() {
        window.clearTimeout(timer);
        if (document.hidden || current?.status !== 'pending' || pollsRemaining <= 0) return;
        timer = window.setTimeout(() => {
            pollsRemaining--;
            run(() => refresh(false));
        }, 2000);
    }

    async function run(action) {
        if (busy) return;
        window.clearTimeout(timer);
        busy = true;
        syncButtons();
        try {
            await action();
        } catch (error) {
            pollsRemaining = 0;
            message.textContent = error instanceof Error
                ? error.message : 'Calendar request failed.';
        } finally {
            busy = false;
            syncButtons();
            schedulePoll();
        }
    }

    async function refresh(resetPolling) {
        const id = selector.value;
        if (!validId(id)) return;
        if (resetPolling) pollsRemaining = 30;
        showResult(await api(root.dataset.status.replace('__ID__', id)));
    }

    async function checkAvailability() {
        capabilities = {};
        byId('calendar-readiness').textContent = 'Checking calendar availability...';
        message.textContent = 'Checking calendar availability...';
        let payload;
        try {
            payload = await api(root.dataset.availability);
        } catch (error) {
            byId('calendar-readiness').textContent =
                'Availability check failed. Use Check availability to retry.';
            throw error;
        }
        for (const key of ['createEvent', 'openDate', 'openEvent']) {
            capabilities[key] = payload.capabilities?.[key] === true;
        }
        byId('calendar-readiness').textContent = JSON.stringify({
            available: payload.available === true,
            platform: 'android',
            apiLevel: Number.isInteger(payload.apiLevel) ? payload.apiLevel : null,
            minimumApiLevel: 33,
            capabilities,
            errorCode: typeof payload.errorCode === 'string' ? payload.errorCode : null
        }, null, 2);
        message.textContent = payload.available === true
            ? 'Calendar operations are available.' : 'Calendar operations are unavailable.';
    }

    availabilityButton.addEventListener('click', () => run(checkAvailability));

    for (const button of presetButtons) {
        button.addEventListener('click', () => run(async () => {
            pollsRemaining = 30;
            showResult(await api(root.dataset.create, 'POST', {
                preset: button.dataset.preset
            }));
        }));
    }

    dateButton.addEventListener('click', () => run(async () => {
        pollsRemaining = 30;
        showResult(await api(root.dataset.openDate, 'POST'));
    }));

    eventButton.addEventListener('click', () => run(async () => {
        const eventId = eventInput.value;
        if (!validEventId(eventId)) return;
        eventInput.value = '';
        pollsRemaining = 30;
        showResult(await api(root.dataset.openEvent, 'POST', { eventId }));
    }));

    diagnosticsButton.addEventListener('click', () => run(async () => {
        const payload = await api(root.dataset.diagnostics, 'POST');
        showResult(payload);
        byId('calendar-diagnostic-result').textContent = JSON.stringify({
            passed: payload.passed === true,
            result: current
        }, null, 2);
        message.textContent = payload.passed === true
            ? 'Unused-request diagnostic passed.' : 'Diagnostic failed. Check bridge readiness.';
    }));

    refreshButton.addEventListener('click', () => run(() => refresh(true)));
    eventInput.addEventListener('input', syncButtons);

    selector.addEventListener('change', () => {
        current = null;
        pollsRemaining = 0;
        window.clearTimeout(timer);
        syncButtons();
        if (validId(selector.value)) run(() => refresh(true));
    });

    document.addEventListener('visibilitychange', () => {
        window.clearTimeout(timer);
        if (!document.hidden && validId(selector.value)) run(() => refresh(true));
    });

    window.addEventListener('pagehide', () => window.clearTimeout(timer));
    window.addEventListener('pageshow', () => {
        if (validId(selector.value)) run(() => refresh(true));
    });

    syncButtons();
    run(checkAvailability).then(() => {
        if (validId(selector.value)) run(() => refresh(true));
    });
})();
</script>
@endpush
