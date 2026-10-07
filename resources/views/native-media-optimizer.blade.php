@extends('layouts.app')
@section('title', 'Media Optimizer')
@section('app-bar-action')
    <a href="{{ route('home') }}" class="btn btn-secondary btn-sm">Back</a>
@endsection
@section('content')
<style>
    .media-demo { display: grid; gap: 12px; }
    .media-demo h2 { margin: 0 0 10px; font-size: 17px; }
    .media-demo p, .media-demo label { font-size: 13px; line-height: 1.5; }
    .media-actions { display: flex; flex-wrap: wrap; gap: 8px; margin: 12px 0; }
    .media-fields { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
    .media-fields label { display: grid; gap: 4px; }
    .media-fields input:not([type="checkbox"]), .media-fields select { width: 100%; box-sizing: border-box; padding: 9px; }
    .media-demo pre { white-space: pre-wrap; overflow-wrap: anywhere; font-size: 12px; }
    .media-demo button:disabled { opacity: .5; }
    .media-job { margin-top: 12px; border-top: 1px solid var(--border, #ddd); padding-top: 12px; }
    .media-job h3 { font-size: 15px; margin: 0 0 8px; }
    .media-job progress { width: 100%; }
    .media-job img, .media-job video { display: block; max-width: 100%; max-height: 380px; margin-top: 12px; }
    #media-message { overflow-wrap: anywhere; }
</style>
<div id="media-demo" class="media-demo"
    data-availability="{{ route('native-media-optimizer.availability') }}"
    data-pick="{{ route('native-media-optimizer.pick') }}"
    data-pick-status="{{ route('native-media-optimizer.pick-status', ['id' => '__ID__']) }}"
    data-start="{{ route('native-media-optimizer.start') }}"
    data-status="{{ route('native-media-optimizer.status', ['id' => '__ID__']) }}"
    data-cancel="{{ route('native-media-optimizer.cancel', ['id' => '__ID__']) }}"
    data-preview="{{ route('native-media-optimizer.preview', ['id' => '__ID__']) }}"
    data-share="{{ route('native-media-optimizer.share', ['id' => '__ID__']) }}"
    data-delete="{{ route('native-media-optimizer.delete', ['id' => '__ID__']) }}">
    <input id="media-csrf" type="hidden" value="{{ csrf_token() }}">
    <section class="card">
        <h2>Android readiness</h2>
        <p id="media-readiness" role="status">Checking availability automatically...</p>
        <button id="media-check" type="button" class="btn btn-secondary">Check availability</button>
    </section>
    <section class="card">
        <h2>Select media</h2>
        <p>Android Files opens for selection. Your file is copied into the app's private storage; the original is preserved.</p>
        <div class="media-actions">
            <button id="media-pick-image" type="button" class="btn btn-primary" disabled>Select image</button>
            <button id="media-pick-video" type="button" class="btn btn-primary" disabled>Select video</button>
        </div>
        <p id="media-selection">No media selected.</p>
        <button id="media-inspect" type="button" class="btn btn-secondary" disabled>Inspect media</button>
        <p>Images: static JPEG, PNG or WebP, up to 100 MiB. Videos: MP4, WebM or QuickTime, up to 512 MiB and one hour. Actual codec support is checked during processing.</p>
    </section>
    <section class="card">
        <h2>Image optimization</h2>
        <div class="media-fields">
            <label>Maximum width<input id="image-width" type="number" min="1" max="4096" value="1920"></label>
            <label>Maximum height<input id="image-height" type="number" min="1" max="4096" value="1920"></label>
            <label>Output format<select id="image-format"><option value="jpeg">JPEG</option><option value="png">PNG</option><option value="webp">WebP</option></select></label>
            <label>Quality (1–100)<input id="image-quality" type="number" min="1" max="100" value="80"></label>
        </div>
        <p>Fit inside the requested box without enlarging. Orientation is applied once. PNG preserves transparency; JPEG uses a white background. PNG ignores quality.</p>
        <button id="media-image" type="button" class="btn btn-primary" disabled>Optimize image</button>
    </section>
    <section class="card">
        <h2>Video optimization</h2>
        <div class="media-fields">
            <label>Maximum width (even)<input id="video-width" type="number" min="16" max="1920" step="2" value="1920"></label>
            <label>Maximum height (even)<input id="video-height" type="number" min="16" max="1920" step="2" value="1080"></label>
            <label>Video bitrate (bits/s)<input id="video-bitrate" type="number" min="128000" max="20000000" value="2500000"></label>
            <label>Audio bitrate (bits/s)<input id="audio-bitrate" type="number" min="32000" max="320000" value="128000"></label>
            <label>Trim start (milliseconds)<input id="video-start" type="number" min="0" max="3599999" value="0"></label>
            <label>Trim end (optional, milliseconds)<input id="video-end" type="number" min="1" max="3600000" placeholder="End of source"></label>
            <label><span><input id="video-remove-audio" type="checkbox"> Remove audio</span></label>
        </div>
        <p>SDR video is encoded as H.264 MP4 with AAC audio when retained. The output fits the box without enlarging, with even dimensions and at most 1920 × 1080 pixels in total. Trim boundaries follow frame and audio packet timing.</p>
        <button id="media-video" type="button" class="btn btn-primary" disabled>Optimize video</button>
    </section>
    <section class="card">
        <h2>Video thumbnail</h2>
        <div class="media-fields">
            <label>Maximum width<input id="thumb-width" type="number" min="1" max="2048" value="512"></label>
            <label>Maximum height<input id="thumb-height" type="number" min="1" max="2048" value="512"></label>
            <label>Timestamp (milliseconds)<input id="thumb-time" type="number" min="0" max="3599999" value="0"></label>
            <label>Output format<select id="thumb-format"><option value="jpeg">JPEG</option><option value="png">PNG</option><option value="webp">WebP</option></select></label>
            <label>Quality (1–100)<input id="thumb-quality" type="number" min="1" max="100" value="80"></label>
        </div>
        <p>The closest decoded frame is fitted inside the requested box. Its orientation is preserved.</p>
        <button id="media-thumbnail" type="button" class="btn btn-primary" disabled>Generate thumbnail</button>
    </section>
    <section class="card">
        <h2>Processing and results</h2>
        <p>One processing job runs at a time. You can leave this page and return to recover status. If Android stops the app process, unfinished jobs become interrupted.</p>
        <p>Inline previews support verified outputs up to 12 MiB. Larger files can be opened through Android's share sheet. Output size may increase for some inputs.</p>
        <button id="media-refresh" type="button" class="btn btn-secondary">Refresh status</button>
        <p id="media-message" role="status" aria-live="polite"></p>
        <div id="media-jobs"></div>
    </section>
</div>
@endsection

@push('scripts')
<script>
(() => {
    const root = document.getElementById('media-demo');
    if (!root) return;
    const initial = {{ Illuminate\Support\Js::from($demoState) }};
    const byId = id => document.getElementById(id);
    const jobs = new Map();
    const picks = new Map(Object.entries(initial.picks).filter(([, pick]) => !pick.terminal));
    const names = {InspectMedia: 'Media inspection', OptimizeImage: 'Image optimization', OptimizeVideo: 'Video optimization', GenerateThumbnail: 'Video thumbnail'};
    let selection = initial.selection;
    let capabilities = null;
    let submitting = false;
    let refreshing = false;
    let timer = null;
    let paused = false;
    const maximumPreview = 12 * 1024 * 1024;
    const message = text => { byId('media-message').textContent = text; };
    const size = bytes => bytes < 1048576 ? `${(bytes / 1024).toFixed(1)} KiB` : `${(bytes / 1048576).toFixed(2)} MiB`;
    const node = (tag, text = '', className = '') => {
        const element = document.createElement(tag);
        element.textContent = text;
        if (className) element.className = className;
        return element;
    };
    const url = (name, id) => root.dataset[name].replace('__ID__', encodeURIComponent(id || ''));

    async function api(name, method = 'GET', body = null, id = null) {
        const response = await fetch(url(name, id), {
            method, credentials: 'same-origin', cache: 'no-store',
            headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': byId('media-csrf').value},
            ...(method === 'GET' ? {} : {body: JSON.stringify(body || {})})
        });
        if (!(response.headers.get('content-type') || '').includes('application/json')) {
            throw new Error('Sign in and unlock the app, then reopen Media Optimizer.');
        }
        const data = await response.json();
        if (!response.ok) {
            const error = new Error(data.message || data.errorMessage || 'This action could not be completed.');
            error.data = data;
            throw error;
        }
        return data;
    }

    function controls() {
        const ready = capabilities?.available === true;
        const busy = submitting || [...jobs.values()].some(job => job.metadata?.terminal === false || (!job.metadata && !job.terminal));
        const image = selection?.mime_type.startsWith('image/') === true;
        const video = selection?.mime_type.startsWith('video/') === true;
        byId('media-pick-image').disabled = !ready || submitting || picks.size > 0;
        byId('media-pick-video').disabled = !ready || submitting || picks.size > 0;
        byId('media-inspect').disabled = !ready || !selection || busy;
        byId('media-image').disabled = !capabilities?.images || !image || busy;
        byId('media-video').disabled = !capabilities?.video || !video || busy;
        byId('media-thumbnail').disabled = !capabilities?.thumbnails || !video || busy;
        byId('media-selection').textContent = selection ? `${selection.mime_type} • ${size(selection.size)}` : 'No media selected.';
    }

    function releasePreview(job) {
        if (job.objectUrl) { URL.revokeObjectURL(job.objectUrl); job.objectUrl = null; }
        job.preview.replaceChildren();
    }

    function addJob(id, info) {
        if (jobs.has(id)) return jobs.get(id);
        const container = node('article', '', 'media-job');
        const heading = node('h3', names[info.operation] || 'Media processing');
        const status = node('p', 'Reading persisted status...');
        const progress = node('progress'); progress.max = 100; progress.hidden = true;
        const stats = node('p');
        const actions = node('div', '', 'media-actions');
        const preview = node('div');
        const details = node('details'); details.append(node('summary', 'Request metadata'));
        const metadataText = node('pre'); details.append(metadataText);
        const job = {id, operation: info.operation, terminal: info.terminal, container, status, progress, stats, preview, metadataText, metadata: null, objectUrl: null, errors: 0};
        for (const [name, label] of [['cancel', 'Cancel processing'], ['preview', 'Preview output'], ['share', 'Open / Share output'], ['delete', 'Delete output']]) {
            const button = node('button', label, 'btn btn-secondary btn-sm'); button.type = 'button'; button.disabled = true;
            button.addEventListener('click', () => act(job, name));
            actions.append(button); job[name + 'Button'] = button;
        }
        container.append(heading, status, progress, stats, actions, preview, details);
        byId('media-jobs').prepend(container);
        jobs.set(id, job);
        return job;
    }

    function show(job, data) {
        job.metadata = data; job.terminal = data.terminal === true;
        job.status.textContent = `${data.status} • ${data.phase}${data.progress == null ? '' : ` • ${data.progress}%`}${data.errorMessage ? ` — ${data.errorMessage}` : ''}`;
        const processing = data.accepted === true && !job.terminal && ['pending', 'running', 'cancelling'].includes(data.status);
        job.progress.hidden = !processing && data.status !== 'succeeded';
        if (data.progress == null) job.progress.removeAttribute('value'); else job.progress.value = data.progress;
        const describe = meta => `${size(meta.size)} • ${meta.width} × ${meta.height}${meta.duration_ms == null ? '' : ` • ${(meta.duration_ms / 1000).toFixed(2)} s`}`;
        job.stats.textContent = [data.input ? `Original: ${describe(data.input)}` : '', data.output ? `Output: ${describe(data.output)}` : ''].filter(Boolean).join(' | ');
        job.metadataText.textContent = JSON.stringify(data, null, 2);
        const output = data.status === 'succeeded' && data.outputAvailable === true;
        job.cancelButton.disabled = !data.accepted || job.terminal || data.status === 'cancelling';
        job.previewButton.disabled = !output || data.output.size > maximumPreview;
        job.shareButton.disabled = !output; job.deleteButton.disabled = !output;
        if (output && data.output.size > maximumPreview) job.stats.textContent += ' | Inline preview exceeds 12 MiB; use Open / Share output.';
        if (!output) releasePreview(job);
        controls();
    }

    async function readJob(job) {
        try { show(job, await api('status', 'GET', null, job.id)); job.errors = 0; }
        catch (error) {
            if (error.data?.id === job.id && error.data.status) show(job, error.data);
            job.errors++; job.status.textContent = `${error.message} Use Refresh status to retry.`;
        }
    }

    async function act(job, action) {
        const button = job[action + 'Button']; button.disabled = true;
        try {
            if (action === 'preview') {
                const data = await api('preview', 'GET', null, job.id);
                if (!['image/jpeg', 'image/png', 'image/webp', 'video/mp4'].includes(data.mime_type) || data.id !== job.id || typeof data.base64 !== 'string' || data.base64.length > Math.ceil(maximumPreview / 3) * 4) throw new Error('Invalid preview response.');
                releasePreview(job);
                const binary = atob(data.base64);
                const bytes = new Uint8Array(binary.length);
                for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
                job.objectUrl = URL.createObjectURL(new Blob([bytes], {type: data.mime_type}));
                const media = document.createElement(data.mime_type === 'video/mp4' ? 'video' : 'img');
                media.src = job.objectUrl;
                if (media.tagName === 'VIDEO') { media.controls = true; media.playsInline = true; media.preload = 'metadata'; }
                else media.alt = 'Verified optimized output';
                job.preview.append(media);
            } else {
                if (action === 'delete') releasePreview(job);
                const data = await api(action, 'POST', {}, job.id);
                if (action === 'cancel') show(job, data);
                else { message(data.message || (data.deleted ? 'Owned output deleted. The original is preserved.' : 'Action completed.')); await readJob(job); }
            }
        } catch (error) { message(error.message); }
        finally { if (job.metadata) show(job, job.metadata); schedule(); }
    }

    async function checkAvailability() {
        try {
            capabilities = await api('availability');
            byId('media-readiness').textContent = capabilities.available
                ? `Images: ${capabilities.images ? 'available' : 'unavailable'} • Video encoding: ${capabilities.video ? 'available' : 'unavailable'} • Thumbnails: ${capabilities.thumbnails ? 'available' : 'unavailable'}`
                : (capabilities.errorMessage || 'Media processing is unavailable on this runtime.');
        } catch (error) { capabilities = null; byId('media-readiness').textContent = error.message; }
        controls();
    }

    async function pick(kind) {
        submitting = true; controls();
        try {
            const reply = await api('pick', 'POST', {kind});
            if (reply.accepted) picks.set(reply.id, {kind});
            message(reply.message);
        } catch (error) {
            if (error.data?.id && error.data.retryable) picks.set(error.data.id, {kind});
            message(error.message);
        } finally { submitting = false; controls(); schedule(); }
    }

    function imageOptions(prefix) {
        const format = byId(prefix + '-format').value;
        const options = {max_width: Number(byId(prefix + '-width').value), max_height: Number(byId(prefix + '-height').value), format};
        if (format !== 'png') options.quality = Number(byId(prefix + '-quality').value);
        return options;
    }

    async function start(operation, options = {}) {
        if (!selection) return;
        submitting = true; controls();
        try {
            const reply = await api('start', 'POST', {operation, source_document_id: selection.id, options});
            show(addJob(reply.id, {operation, terminal: reply.terminal}), reply);
            message('Job accepted. Status is persisted while processing continues.');
        } catch (error) {
            if (error.data?.id) {
                const job = addJob(error.data.id, {operation, terminal: false});
                if (error.data.status) show(job, error.data);
            }
            message(error.message);
        } finally { submitting = false; controls(); schedule(); }
    }

    function schedule() {
        clearTimeout(timer);
        if (!paused && !document.hidden) timer = setTimeout(() => refresh(false), 1500);
    }

    async function refresh(all) {
        if (refreshing) return;
        refreshing = true;
        try {
            for (const [id] of picks) {
                try {
                    const reply = await api('pickStatus', 'GET', null, id);
                    if (reply.terminal) {
                        picks.delete(id); if (reply.selection) selection = reply.selection;
                        message(reply.message || 'Media selection finished.');
                    }
                } catch (error) { picks.delete(id); message(error.message + ' Reopen the page to retry selection recovery.'); }
            }
            for (const job of jobs.values()) {
                if (all) job.errors = 0;
                if (all || ((!job.metadata || !job.terminal) && job.errors < 3)) await readJob(job);
            }
        } finally { refreshing = false; controls(); schedule(); }
    }

    byId('media-check').addEventListener('click', checkAvailability);
    byId('media-pick-image').addEventListener('click', () => pick('image'));
    byId('media-pick-video').addEventListener('click', () => pick('video'));
    byId('media-inspect').addEventListener('click', () => start('InspectMedia'));
    byId('media-image').addEventListener('click', () => start('OptimizeImage', imageOptions('image')));
    byId('media-thumbnail').addEventListener('click', () => start('GenerateThumbnail', {...imageOptions('thumb'), timestamp_ms: Number(byId('thumb-time').value)}));
    byId('media-video').addEventListener('click', () => {
        const options = {max_width: Number(byId('video-width').value), max_height: Number(byId('video-height').value), video_bitrate: Number(byId('video-bitrate').value), audio_bitrate: Number(byId('audio-bitrate').value), start_ms: Number(byId('video-start').value), remove_audio: byId('video-remove-audio').checked};
        if (byId('video-end').value.trim() !== '') options.end_ms = Number(byId('video-end').value);
        start('OptimizeVideo', options);
    });
    byId('media-refresh').addEventListener('click', () => refresh(true));
    for (const prefix of ['image', 'thumb']) byId(prefix + '-format').addEventListener('change', () => { byId(prefix + '-quality').disabled = byId(prefix + '-format').value === 'png'; });
    document.addEventListener('native-event', event => {
        const detail = event.detail || {};
        if (detail.event !== 'Bbs\\NativeMediaOptimizer\\Events\\NativeMediaOptimizerCompleted') return;
        let payload = detail.payload;
        if (typeof payload === 'string') { try { payload = JSON.parse(payload); } catch (_) { return; } }
        if (jobs.has(payload?.id)) refresh(false);
    });
    document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(false); else clearTimeout(timer); });
    window.addEventListener('pagehide', () => { paused = true; clearTimeout(timer); for (const job of jobs.values()) releasePreview(job); });
    window.addEventListener('pageshow', () => { paused = false; refresh(true); });
    for (const [id, info] of Object.entries(initial.jobs)) addJob(id, info);
    controls();
    checkAvailability();
    refresh(true);
})();
</script>
@endpush
