'use strict';

const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const view = fs.readFileSync(path.join(__dirname, '../../resources/views/partials/firebase-remote-config.blade.php'), 'utf8');
const source = view.match(/<script>([\s\S]*?)<\/script>/)[1];
const ready = (defaultsReady = true) => ({success: true, ready: true, defaultsReady,
    sdkBusy: false, activeOperationId: null, minimumFetchIntervalSeconds: 43200, fetchTimeoutSeconds: 60,
    lastFetchStatus: 'no_fetch_yet', lastSuccessfulFetchAtMs: null});
const snapshot = info => ({info, operations: [], values: {
    welcome_message: {value: 'Cached welcome', source: 'remote', fallbackReason: null},
    show_media_optimizer: {value: true, source: 'default', fallbackReason: null},
    media_image_quality: {value: 80, source: 'default', fallbackReason: null}
}});
const record = (id, operation, terminal = false, extra = {}) => ({id, operation,
    status: terminal ? 'succeeded' : 'pending', terminal, success: terminal,
    fetched: terminal && operation === 'Fetch' ? true : null, activated: null,
    startedAtMs: terminal ? 1000 : null, finishedAtMs: terminal ? 2000 : null,
    throttleEndTimeMs: null, errorCode: null,
    message: terminal && operation === 'Fetch' ? 'Fetch succeeded. Use Activate to apply the fetched configuration.' : (terminal ? 'Settings ready.' : 'Operation pending.'), ...extra});

function harness(initial, respond) {
    let now = 0, nextTimer = 1, nextRandom = 1;
    const timers = new Map(), elements = new Map(), requests = [];
    function element(id, dataset = {}) {
        const handlers = new Map();
        const item = {id, dataset, textContent: '', disabled: false, style: {}, value: '',
            addEventListener: (name, callback) => handlers.set(name, callback),
            click: () => handlers.get('click')?.()};
        Object.defineProperty(item, 'innerHTML', {set: () => {throw new Error('Unsafe HTML write');}});
        elements.set(id, item); return item;
    }
    element('rc-client', {snapshot: '/snapshot', start: '/start', status: '/status/__ID__', csrf: 'test', mode: 'demo'});
    const buttons = ['SetDefaults', 'Configure', 'Fetch', 'Activate', 'FetchAndActivate'].map(operation => element('action-' + operation, {rcOperation: operation}));
    const values = Object.keys(initial.values).map(key => element('value-' + key, {rcValue: key}));
    const sources = Object.keys(initial.values).map(key => element('source-' + key, {rcSource: key}));
    const reasons = Object.keys(initial.values).map(key => element('reason-' + key, {rcReason: key}));
    for (const id of ['rc-refresh', 'rc-message', 'rc-home-status', 'rc-welcome', 'rc-media-link', 'rc-ready',
        'rc-fetch-status', 'rc-fetch-time', 'rc-settings', 'rc-operation', 'rc-started', 'rc-finished', 'rc-fetched', 'rc-activated', 'rc-throttle', 'rc-interval', 'rc-timeout']) element(id);
    elements.get('rc-interval').value = '43200'; elements.get('rc-timeout').value = '60';
    const context = vm.createContext({
        console, Uint8Array, Map, Object, Array, JSON, Number, String, Error, Date, AbortController,
        performance: {now: () => now},
        crypto: {getRandomValues: bytes => {bytes.fill(nextRandom++); return bytes;}},
        setTimeout: (callback, delay = 0) => {const id = nextTimer++; timers.set(id, {callback, due: now + delay}); return id;},
        clearTimeout: id => timers.delete(id),
        document: {hidden: false, getElementById: id => elements.get(id), addEventListener: () => {},
            querySelectorAll: selector => ({'[data-rc-operation]': buttons, '[data-rc-value]': values,
                '[data-rc-source]': sources, '[data-rc-reason]': reasons})[selector] || []},
        window: {addEventListener: () => {}},
        fetch: async (url, options) => {
            const request = {url, method: options.method, body: options.body ? JSON.parse(options.body) : null};
            requests.push(request);
            const result = await respond(request, requests);
            return {ok: (result.httpStatus || 200) < 400, status: result.httpStatus || 200,
                headers: {get: () => 'application/json'}, json: async () => result.data};
        }
    });
    const script = source.replace(/const initial = \{\{[^\n]+\}\};/, 'const initial = ' + JSON.stringify(initial) + ';');
    assert.ok(!script.includes('{{'), 'Blade expression must be replaced in the executable fixture');
    vm.runInContext(script, context);
    const flush = async () => {for (let i = 0; i < 35; i++) await Promise.resolve();};
    return {requests, elements, timers,
        async step() {
            const next = [...timers.entries()].sort((a, b) => a[1].due - b[1].due)[0];
            assert.ok(next, 'Expected a scheduled client action');
            timers.delete(next[0]); now = Math.max(now, next[1].due); next[1].callback(); await flush();
        },
        async click(operation) {elements.get(operation === 'refresh' ? 'rc-refresh' : 'action-' + operation).click(); await flush();},
        advance: milliseconds => {now += milliseconds;},
        flush
    };
}

test('startup registers defaults and reads cache without fetching or activating', async () => {
    let registered = false, id = null;
    const h = harness(snapshot(ready(false)), request => {
        if (request.url === '/snapshot') return {data: snapshot(ready(registered))};
        if (request.url === '/start') {assert.equal(request.body.operation, 'SetDefaults'); id = request.body.id; return {data: record(id, 'SetDefaults')};}
        assert.equal(request.url, '/status/' + id); registered = true; return {data: record(id, 'SetDefaults', true)};
    });
    await h.step(); await h.step();
    assert.deepEqual(h.requests.filter(r => r.method === 'POST').map(r => r.body.operation), ['SetDefaults']);
    assert.equal(h.elements.get('rc-welcome').textContent, 'Cached welcome');
    assert.equal(h.elements.get('action-Fetch').disabled, false);
    assert.equal(h.timers.size, 0);
});

test('lost acknowledgement polls the same ID and never creates a replacement mutation', async () => {
    let registered = false, id = null;
    const h = harness(snapshot(ready(false)), request => {
        if (request.url === '/snapshot') return {data: snapshot(ready(registered))};
        if (request.url === '/start') {id = request.body.id; throw new Error('lost response');}
        assert.equal(request.url, '/status/' + id); registered = true; return {data: record(id, 'SetDefaults', true)};
    });
    await h.step(); await h.step();
    assert.equal(h.requests.filter(r => r.method === 'POST').length, 1);
    assert.equal(h.elements.get('action-Fetch').disabled, false);
});

test('Fetch success remains distinct from Activate and updates safe text', async () => {
    let id = null;
    const data = snapshot(ready()); data.values.welcome_message.value = '<img onerror="unsafe()">'; data.values.show_media_optimizer.value = false;
    const h = harness(data, request => {
        if (request.url === '/snapshot') return {data};
        if (request.url === '/start') {id = request.body.id; return {data: record(id, 'Fetch')};}
        return {data: record(id, 'Fetch', true)};
    });
    await h.step(); await h.click('Fetch'); await h.step();
    assert.deepEqual(h.requests.filter(r => r.method === 'POST').map(r => r.body.operation), ['Fetch']);
    assert.match(h.elements.get('rc-message').textContent, /Use Activate/);
    assert.equal(h.elements.get('rc-fetched').textContent, 'Yes');
    assert.equal(h.elements.get('rc-activated').textContent, 'Unknown');
    assert.equal(h.elements.get('rc-welcome').textContent, '<img onerror="unsafe()">');
    assert.equal(h.elements.get('rc-media-link').style.display, 'none');
});

test('automatic polling stops at its deadline and explicit refresh retains the ID', async () => {
    let id = null;
    const h = harness(snapshot(ready()), request => {
        if (request.url === '/snapshot') return {data: snapshot(ready())};
        if (request.url === '/start') {id = request.body.id; return {data: record(id, 'Fetch')};}
        assert.equal(request.url, '/status/' + id); return {data: record(id, 'Fetch')};
    });
    await h.step(); await h.click('Fetch'); h.advance(180001); await h.step();
    assert.match(h.elements.get('rc-message').textContent, /checking has paused/);
    assert.equal(h.timers.size, 0);
    await h.click('refresh');
    assert.equal(h.requests.filter(r => r.method === 'POST').length, 1);
    assert.ok(h.requests.some(r => r.url === '/status/' + id));
});

test('three failed status responses pause recovery without replacing the request', async () => {
    let id = null;
    const h = harness(snapshot(ready()), request => {
        if (request.url === '/snapshot') return {data: snapshot(ready())};
        if (request.url === '/start') {id = request.body.id; return {data: record(id, 'Fetch')};}
        assert.equal(request.url, '/status/' + id); throw new Error('offline');
    });
    await h.step(); await h.click('Fetch'); await h.step(); await h.step(); await h.step();
    assert.equal(h.timers.size, 0);
    assert.equal(h.requests.filter(r => r.method === 'POST').length, 1);
    assert.equal(h.elements.get('action-Fetch').disabled, true);
});

test('SDK work still settling after timeout prevents new mutation controls', async () => {
    let id = null, settling = false;
    const h = harness(snapshot(ready()), request => {
        if (request.url === '/snapshot') return {data: snapshot({...ready(), sdkBusy: settling})};
        if (request.url === '/start') {id = request.body.id; return {data: record(id, 'Fetch')};}
        settling = true; return {data: record(id, 'Fetch', true, {status: 'timed_out', success: false, fetched: null,
            errorCode: 'OPERATION_TIMEOUT', message: 'Native task may still be settling.'})};
    });
    await h.step(); await h.click('Fetch'); await h.step();
    assert.equal(h.elements.get('action-Fetch').disabled, true);
    assert.match(h.elements.get('rc-ready').textContent, /settling/);
    assert.equal(h.requests.filter(r => r.method === 'POST').length, 1);
});

test('known validation rejection releases controls without polling an unowned ID', async () => {
    const h = harness(snapshot(ready()), request => request.url === '/snapshot'
        ? {data: snapshot(ready())} : {httpStatus: 422, data: {success: false, errorCode: 'INVALID_SETTINGS', message: 'Invalid fetch timeout.'}});
    await h.step(); await h.click('Configure'); await h.step();
    assert.equal(h.elements.get('action-Fetch').disabled, false);
    assert.equal(h.requests.filter(r => r.url.startsWith('/status/')).length, 0);
    assert.match(h.elements.get('rc-message').textContent, /Invalid fetch timeout/);
});

test('restored owned operation is polled before automatic default registration', async () => {
    const id = '123e4567-e89b-42d3-a456-426614174000';
    const data = snapshot(ready(false)); data.operations = [{id, operation: 'Fetch', terminal: false}];
    const h = harness(data, request => {
        if (request.url === '/snapshot') return {data};
        if (request.url.startsWith('/status/')) return {data: record(id, 'Fetch', true)};
        return {data: record(request.body.id, 'SetDefaults')};
    });
    await h.step(); assert.equal(h.requests.filter(r => r.method === 'POST').length, 0);
    await h.step();
    assert.equal(h.requests[1].url, '/status/' + id);
    assert.deepEqual(h.requests.filter(r => r.method === 'POST').map(r => r.body.operation), ['SetDefaults']);
});

test('a completed acknowledgement is retained when a later value refresh fails', async () => {
    let snapshots = 0;
    const h = harness(snapshot(ready()), request => {
        if (request.url === '/snapshot') {
            if (++snapshots > 1) throw new Error('value read failed');
            return {data: snapshot(ready())};
        }
        return {data: record(request.body.id, 'Activate', true, {activated: true})};
    });
    await h.step(); await h.click('Activate');
    assert.match(h.elements.get('rc-message').textContent, /operation response was received/);
    assert.equal(h.elements.get('rc-activated').textContent, 'Yes');
    assert.equal(h.requests.filter(r => r.method === 'POST').length, 1);
});

test('reopening the page reads its last completed operation without repeating it', async () => {
    const id = '123e4567-e89b-42d3-a456-426614174000';
    const data = snapshot(ready()); data.operations = [{id, operation: 'Fetch', terminal: true}];
    const h = harness(data, request => request.url === '/snapshot'
        ? {data} : {data: record(id, 'Fetch', true)});
    await h.step(); await h.step();
    assert.equal(h.requests.filter(r => r.method === 'POST').length, 0);
    assert.match(h.elements.get('rc-operation').textContent, /Fetch: succeeded/);
});
