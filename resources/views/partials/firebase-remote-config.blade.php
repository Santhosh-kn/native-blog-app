<div id="rc-client" hidden
    data-mode="{{ $remoteConfigMode }}"
    data-snapshot="{{ route('firebase-remote-config.snapshot') }}"
    data-start="{{ route('firebase-remote-config.start') }}"
    data-status="{{ route('firebase-remote-config.status', ['id' => '__ID__']) }}"
    data-csrf="{{ csrf_token() }}"></div>
@push('scripts')
<script>
(() => {
    const root = document.getElementById('rc-client');
    if (!root) return;
    const initial = {{ Illuminate\Support\Js::from($remoteConfig) }};
    let snapshot = initial;
    let flight = null;
    let lastId = null;
    let busy = false;
    let stopped = false;
    let timer = null;
    let automaticDefaultsAttempted = false;
    let bootstrapUntil = performance.now() + 25000;
    const byId = id => document.getElementById(id);
    const set = (id, text) => { const element = byId(id); if (element) element.textContent = text; };
    const message = text => { set('rc-message', text); set('rc-home-status', text); };
    const date = value => Number.isSafeInteger(value) && value > 0 ? new Date(value).toLocaleString() : 'None';
    const known = value => value === true ? 'Yes' : (value === false ? 'No' : 'Unknown');
    const sources = {remote: 'remote', default: 'default (Firebase)', static: 'static (SDK)', local: 'local (PHP fallback)'};

    function controls() {
        const info = snapshot.info || {};
        for (const button of document.querySelectorAll('[data-rc-operation]')) {
            const needsDefaults = !['Configure', 'SetDefaults'].includes(button.dataset.rcOperation);
            button.disabled = busy || !!flight || info.sdkBusy === true || info.ready !== true || (needsDefaults && info.defaultsReady !== true);
        }
    }

    function render(data) {
        snapshot = data;
        for (const [key, entry] of Object.entries(data.values || {})) {
            for (const element of document.querySelectorAll('[data-rc-value]')) {
                if (element.dataset.rcValue === key) element.textContent = String(entry.value);
            }
            for (const element of document.querySelectorAll('[data-rc-source]')) {
                if (element.dataset.rcSource === key) element.textContent = sources[entry.source] || 'Unknown';
            }
            for (const element of document.querySelectorAll('[data-rc-reason]')) {
                if (element.dataset.rcReason === key) element.textContent = entry.fallbackReason || '';
            }
        }
        const welcome = data.values?.welcome_message;
        if (welcome) set('rc-welcome', welcome.value);
        const link = byId('rc-media-link');
        if (link) link.style.display = data.values?.show_media_optimizer?.value === false ? 'none' : '';
        const info = data.info || {};
        set('rc-ready', !info.success ? 'Native settings unavailable; local defaults are in use.'
            : (!info.ready ? 'Initializing cached settings' : (!info.defaultsReady ? 'Local default registration needed' : (info.sdkBusy ? 'Ready; a native task is running or settling' : 'Ready'))));
        set('rc-fetch-status', info.lastFetchStatus || 'Unknown');
        set('rc-fetch-time', date(info.lastSuccessfulFetchAtMs));
        set('rc-settings', info.success ? `Current interval: ${info.minimumFetchIntervalSeconds} seconds · timeout: ${info.fetchTimeoutSeconds} seconds.` : 'Native fetch settings unavailable.');
        controls();
    }

    async function api(name, method = 'GET', body = null, id = null) {
        const endpoint = name === 'status' ? root.dataset.status.replace('__ID__', encodeURIComponent(id)) : root.dataset[name];
        const abort = new AbortController();
        const timeout = setTimeout(() => abort.abort(), 10000);
        try {
            const response = await fetch(endpoint, {method, credentials: 'same-origin', cache: 'no-store', signal: abort.signal,
                headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': root.dataset.csrf},
                ...(method === 'GET' ? {} : {body: JSON.stringify(body)})});
            if (!(response.headers.get('content-type') || '').includes('application/json')) throw new Error('Sign in and unlock the app, then reopen Remote Config.');
            const data = await response.json();
            if (!response.ok && !(data.id === id && typeof data.terminal === 'boolean')) {
                const error = new Error(data.message || 'The settings response is unavailable. Refresh status.');
                error.data = data; error.httpStatus = response.status;
                throw error;
            }
            return data;
        } finally { clearTimeout(timeout); }
    }

    function requestId() {
        // getRandomValues works in Android WebView even when randomUUID is unavailable.
        const bytes = new Uint8Array(16);
        crypto.getRandomValues(bytes);
        bytes[6] = (bytes[6] & 15) | 64; bytes[8] = (bytes[8] & 63) | 128;
        const hex = [...bytes].map(value => value.toString(16).padStart(2, '0')).join('');
        return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
    }

    function schedule(delay = 1000) {
        clearTimeout(timer);
        if (!stopped && !document.hidden) timer = setTimeout(tick, delay);
    }

    function record(data) {
        if (!flight || data.id !== flight.id || typeof data.terminal !== 'boolean') throw new Error('The operation response could not be correlated. Refresh this same request.');
        message(data.message || 'Reading operation status.');
        set('rc-operation', `${data.operation || flight.operation}: ${data.status}`);
        set('rc-started', date(data.startedAtMs)); set('rc-finished', date(data.finishedAtMs));
        set('rc-fetched', known(data.fetched)); set('rc-activated', known(data.activated)); set('rc-throttle', date(data.throttleEndTimeMs));
        if (data.terminal) { lastId = flight.id; flight = null; }
        controls();
    }

    async function begin(operation, settings = null) {
        if (busy || flight) return;
        if (operation === 'SetDefaults') automaticDefaultsAttempted = true;
        let id;
        try { id = requestId(); } catch (_) { message('A secure request ID could not be generated. Settings remain usable.'); return; }
        flight = {id, operation, until: performance.now() + 180000, failures: 0, paused: false};
        busy = true; controls(); message('Submitting settings operation...');
        let acknowledged = false;
        try {
            const reply = await api('start', 'POST', {id, operation, ...(operation === 'Configure' ? {settings} : {})}, id);
            record(reply);
            acknowledged = true;
            render(await api('snapshot'));
        } catch (error) {
            if (acknowledged) {
                message('The operation response was received, but current values could not be refreshed. Use Refresh values and status.');
            } else if ([409, 413, 422, 429].includes(error.httpStatus) && error.data?.success === false && !error.data?.id) {
                flight = null;
                message(error.message);
            } else {
                message('The acknowledgement could not be confirmed. Checking the same request ID; no replacement request will be submitted.');
            }
        } finally { busy = false; controls(); schedule(); }
    }

    async function tick() {
        if (stopped || busy) return;
        if (flight?.paused) return;
        if (flight && performance.now() >= flight.until) {
            flight.paused = true;
            message('Automatic status checking has paused. The outcome is unconfirmed; use Refresh values and status to check the same request.');
            controls(); return;
        }
        busy = true; controls();
        try {
            if (flight) {
                const reply = await api('status', 'GET', null, flight.id);
                if (flight) flight.failures = 0;
                record(reply);
            }
            const data = await api('snapshot'); render(data);
            if (!flight && !lastId) {
                const latest = (data.operations || []).filter(entry => !entry.terminal).at(-1) || (data.operations || []).at(-1);
                if (latest) {
                    flight = {...latest, until: performance.now() + 180000, failures: 0, paused: false};
                    if (!latest.terminal && latest.operation === 'SetDefaults') automaticDefaultsAttempted = true;
                }
            }
        } catch (error) {
            message(error.name === 'AbortError' ? 'The response timed out. Current settings remain usable; refresh the same request.' : error.message);
            if (flight && ++flight.failures >= 3) flight.paused = true;
            else if (!flight) bootstrapUntil = 0;
        } finally { busy = false; controls(); }
        if (flight) { if (!flight.paused) schedule(); return; }
        const info = snapshot.info || {};
        if (!info.success) return;
        if (info.ready && !info.defaultsReady && !info.sdkBusy && !automaticDefaultsAttempted) {
            await begin('SetDefaults'); return;
        }
        if ((!info.ready || info.sdkBusy) && performance.now() < bootstrapUntil) { schedule(); return; }
        if (!info.ready || !info.defaultsReady) message('Local defaults are in use. Open Remote Config and refresh status or register defaults when native settings are ready.');
    }

    for (const button of document.querySelectorAll('[data-rc-operation]')) button.addEventListener('click', () => {
        const operation = button.dataset.rcOperation;
        const settings = operation === 'Configure' ? {minimum_fetch_interval_seconds: Number(byId('rc-interval').value), fetch_timeout_seconds: Number(byId('rc-timeout').value)} : null;
        begin(operation, settings);
    });
    byId('rc-refresh')?.addEventListener('click', async () => {
        if (busy) return;
        bootstrapUntil = performance.now() + 25000;
        if (flight) { flight.paused = false; flight.failures = 0; flight.until = performance.now() + 180000; }
        else if (lastId) {
            flight = {id: lastId, operation: null, until: performance.now() + 180000, failures: 0, paused: false};
        }
        await tick();
    });
    document.addEventListener('visibilitychange', () => { if (document.hidden) clearTimeout(timer); else schedule(0); });
    window.addEventListener('pagehide', () => { stopped = true; clearTimeout(timer); });
    window.addEventListener('pageshow', event => { if (event.persisted) { stopped = false; schedule(0); } });
    render(initial); schedule(0);
})();
</script>
@endpush
