@extends('layouts.app')

@section('title', 'Background Transfers')

@section('app-bar-action')
    <a href="{{ route('home') }}" class="btn btn-secondary btn-sm">
        Back
    </a>
@endsection

@section('content')
    <style>
        .background-transfer-page {
            display: grid;
            gap: 14px;
        }

        .background-transfer-heading {
            margin: 0 0 7px;
            font-size: 17px;
            font-weight: 700;
        }

        .background-transfer-copy {
            margin: 0;
            color: var(--text-muted);
            font-size: 13px;
            line-height: 1.5;
        }

        .background-transfer-meta {
            display: grid;
            gap: 8px;
            margin-top: 14px;
        }

        .background-transfer-meta div {
            display: grid;
            grid-template-columns: 105px minmax(0, 1fr);
            gap: 10px;
            padding-top: 8px;
            border-top: 1px solid var(--border);
        }

        .background-transfer-meta dt {
            color: var(--text-muted);
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .background-transfer-meta dd {
            min-width: 0;
            margin: 0;
            overflow-wrap: anywhere;
            font-size: 13px;
            font-weight: 600;
        }

        .background-transfer-actions {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 9px;
        }

        .background-transfer-actions .full-width {
            grid-column: 1 / -1;
        }

        .background-transfer-actions button:disabled {
            cursor: not-allowed;
            opacity: 0.5;
        }

        .background-transfer-status {
            padding: 14px;
            border: 1px solid var(--border);
            border-radius: 11px;
            background: var(--surface);
        }

        .background-transfer-status[data-tone="success"] {
            border-color: #93cca5;
            background: #effaf2;
        }

        .background-transfer-status[data-tone="warning"] {
            border-color: #dfbd68;
            background: #fff8e6;
        }

        .background-transfer-status[data-tone="error"] {
            border-color: #dd8e8e;
            background: #fff1f1;
        }

        .background-transfer-status[data-tone="info"] {
            border-color: color-mix(
                in srgb,
                var(--primary) 50%,
                var(--border)
            );
        }

        .background-transfer-status strong {
            display: block;
            margin-bottom: 5px;
            font-size: 14px;
        }

        .background-transfer-status p {
            margin: 0;
            color: var(--text-muted);
            font-size: 13px;
            line-height: 1.45;
        }

        .background-transfer-progress {
            height: 9px;
            margin-top: 12px;
            overflow: hidden;
            border-radius: 999px;
            background: var(--border);
        }

        .background-transfer-progress-bar {
            width: 0;
            height: 100%;
            border-radius: inherit;
            background: var(--primary);
            transition: width 180ms ease;
        }

        .background-transfer-list {
            display: grid;
            gap: 8px;
            margin-top: 12px;
        }

        .background-transfer-item {
            padding: 11px;
            border: 1px solid var(--border);
            border-radius: 9px;
            background: var(--surface);
        }

        .background-transfer-item strong {
            display: block;
            font-size: 13px;
        }

        .background-transfer-item span {
            display: block;
            margin-top: 4px;
            overflow-wrap: anywhere;
            color: var(--text-muted);
            font-size: 11px;
        }

        .background-transfer-empty {
            margin-top: 12px;
            color: var(--text-muted);
            font-size: 13px;
        }
    </style>

    <div class="background-transfer-page">
        <section class="card">
            <h2 class="background-transfer-heading">
                Native background download
            </h2>

            <p class="background-transfer-copy">
                This first device test downloads one fixed HTTPS PDF through
                Android WorkManager into application-private storage.
                The page receives safe metadata only.
            </p>

            <dl class="background-transfer-meta">
                <div>
                    <dt>Test file</dt>
                    <dd>{{ $testFileName }}</dd>
                </div>

                <div>
                    <dt>Allowed type</dt>
                    <dd>application/pdf</dd>
                </div>

                <div>
                    <dt>Maximum size</dt>
                    <dd>
                        {{ number_format($testMaxSize / 1_048_576, 0) }} MB
                    </dd>
                </div>
            </dl>
        </section>

        <section class="card background-transfer-actions">
            <button
                type="button"
                id="background-transfer-start"
                class="btn btn-primary full-width"
            >
                Start test download
            </button>

            <button
                type="button"
                id="background-transfer-cancel"
                class="btn btn-secondary"
                disabled
            >
                Cancel
            </button>

            <button
                type="button"
                id="background-transfer-consume"
                class="btn btn-secondary"
                disabled
            >
                Consume result
            </button>

            <button
                type="button"
                id="background-transfer-refresh"
                class="btn btn-secondary full-width"
            >
                Refresh transfer list
            </button>
        </section>

        <section
            id="background-transfer-status"
            class="background-transfer-status"
            data-tone="neutral"
            role="status"
            aria-live="polite"
        >
            <strong id="background-transfer-status-title">
                Ready
            </strong>

            <p id="background-transfer-status-message">
                Start the fixed PDF download when ready.
            </p>

            <div class="background-transfer-progress">
                <div
                    id="background-transfer-progress-bar"
                    class="background-transfer-progress-bar"
                ></div>
            </div>
        </section>

        <section class="card">
            <h2 class="background-transfer-heading">
                Current transfer
            </h2>

            <dl class="background-transfer-meta">
                <div>
                    <dt>ID</dt>
                    <dd id="background-transfer-id">None</dd>
                </div>

                <div>
                    <dt>Status</dt>
                    <dd id="background-transfer-state">None</dd>
                </div>

                <div>
                    <dt>Progress</dt>
                    <dd id="background-transfer-progress">Not available</dd>
                </div>

                <div>
                    <dt>Transferred</dt>
                    <dd id="background-transfer-bytes">Not available</dd>
                </div>

                <div>
                    <dt>File</dt>
                    <dd id="background-transfer-file">Not available</dd>
                </div>

                <div>
                    <dt>MIME type</dt>
                    <dd id="background-transfer-mime">Not available</dd>
                </div>

                <div>
                    <dt>Consumed</dt>
                    <dd id="background-transfer-consumed">No</dd>
                </div>
            </dl>
        </section>

        <section class="card">
            <h2 class="background-transfer-heading">
                Session transfers
            </h2>

            <p class="background-transfer-copy">
                Only transfer IDs created by this Laravel session are shown.
            </p>

            <div
                id="background-transfer-list"
                class="background-transfer-list"
            ></div>

            <p
                id="background-transfer-empty"
                class="background-transfer-empty"
            >
                No transfers yet.
            </p>
        </section>
    </div>
@endsection

@push('scripts')
<script>
(() => {
    'use strict';

    const endpoints = Object.freeze({
        start: @json(route('native-background-transfer.start')),
        transfers: @json(route('native-background-transfer.transfers')),
        status: @json(route(
            'native-background-transfer.status',
            ['transferId' => '__TRANSFER_ID__'],
        )),
        cancel: @json(route(
            'native-background-transfer.cancel',
            ['transferId' => '__TRANSFER_ID__'],
        )),
        consume: @json(route(
            'native-background-transfer.consume',
            ['transferId' => '__TRANSFER_ID__'],
        )),
    });

    const csrfToken = document
        .querySelector('meta[name="csrf-token"]')
        ?.getAttribute('content') ?? '';

    const activeTransferStorageKey =
        'native-background-transfer.active-id';

    const pollIntervalMilliseconds = 1000;

    const terminalStatuses = new Set([
        'succeeded',
        'failed',
        'cancelled',
    ]);

    const startButton =
        document.getElementById('background-transfer-start');

    const cancelButton =
        document.getElementById('background-transfer-cancel');

    const consumeButton =
        document.getElementById('background-transfer-consume');

    const refreshButton =
        document.getElementById('background-transfer-refresh');

    const statusPanel =
        document.getElementById('background-transfer-status');

    const statusTitle =
        document.getElementById('background-transfer-status-title');

    const statusMessage =
        document.getElementById('background-transfer-status-message');

    const progressBar =
        document.getElementById('background-transfer-progress-bar');

    const transferIdElement =
        document.getElementById('background-transfer-id');

    const transferStateElement =
        document.getElementById('background-transfer-state');

    const progressElement =
        document.getElementById('background-transfer-progress');

    const transferredElement =
        document.getElementById('background-transfer-bytes');

    const fileElement =
        document.getElementById('background-transfer-file');

    const mimeElement =
        document.getElementById('background-transfer-mime');

    const consumedElement =
        document.getElementById('background-transfer-consumed');

    const transferList =
        document.getElementById('background-transfer-list');

    const transferEmpty =
        document.getElementById('background-transfer-empty');

    let activeTransferId = null;
    let pollTimer = null;

    function validUuid(value) {
        return typeof value === 'string' &&
            /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i
                .test(value);
    }

    function isObject(value) {
        return value !== null &&
            typeof value === 'object' &&
            !Array.isArray(value);
    }

    function endpoint(template, transferId) {
        return template.replace(
            '__TRANSFER_ID__',
            encodeURIComponent(transferId),
        );
    }

    function setStatus(tone, title, message) {
        statusPanel.dataset.tone = tone;
        statusTitle.textContent = title;
        statusMessage.textContent = message;
    }

    function formatBytes(value) {
        if (!Number.isInteger(value) || value < 0) {
            return 'Not available';
        }

        if (value < 1024) {
            return `${value} B`;
        }

        if (value < 1024 * 1024) {
            return `${(value / 1024).toFixed(1)} KB`;
        }

        return `${(value / (1024 * 1024)).toFixed(2)} MB`;
    }

    function rememberActiveTransfer(transferId) {
        activeTransferId = transferId;

        try {
            window.sessionStorage.setItem(
                activeTransferStorageKey,
                transferId,
            );
        } catch {
            // Laravel session still protects transfer ownership.
        }
    }

    function forgetActiveTransfer() {
        activeTransferId = null;

        try {
            window.sessionStorage.removeItem(
                activeTransferStorageKey,
            );
        } catch {
            // Stored value contains only a transfer UUID.
        }
    }

    function storedTransferId() {
        try {
            const value = window.sessionStorage.getItem(
                activeTransferStorageKey,
            );

            return validUuid(value)
                ? value
                : null;
        } catch {
            return null;
        }
    }

    function updateButtons(result = null) {
        const status =
            isObject(result) &&
            typeof result.status === 'string'
                ? result.status
                : null;

        const terminal =
            status !== null &&
            terminalStatuses.has(status);

        startButton.disabled =
            activeTransferId !== null &&
            !terminal;

        cancelButton.disabled =
            activeTransferId === null ||
            terminal;

        consumeButton.disabled =
            activeTransferId === null ||
            !terminal ||
            result?.consumed === true;
    }

    function renderTransfer(result) {
        if (!isObject(result)) {
            return;
        }

        const transferId =
            validUuid(result.transfer_id)
                ? result.transfer_id
                : (
                    validUuid(result.id)
                        ? result.id
                        : null
                );

        if (transferId !== null) {
            transferIdElement.textContent = transferId;
        }

        const status =
            typeof result.status === 'string'
                ? result.status
                : 'unknown';

        transferStateElement.textContent = status;

        if (
            Number.isInteger(result.progress) &&
            result.progress >= 0 &&
            result.progress <= 100
        ) {
            progressElement.textContent =
                `${result.progress}%`;

            progressBar.style.width =
                `${result.progress}%`;
        } else {
            progressElement.textContent =
                'Not available';

            progressBar.style.width = '0%';
        }

        const transferred =
            formatBytes(result.transferred_bytes);

        const total =
            formatBytes(result.total_bytes);

        transferredElement.textContent =
            result.total_bytes === null ||
            result.total_bytes === undefined
                ? transferred
                : `${transferred} / ${total}`;

        fileElement.textContent =
            typeof result.display_name === 'string'
                ? (
                    Number.isInteger(result.size)
                        ? `${result.display_name} (${formatBytes(result.size)})`
                        : result.display_name
                )
                : 'Not available';

        mimeElement.textContent =
            typeof result.mime_type === 'string'
                ? result.mime_type
                : 'Not available';

        consumedElement.textContent =
            result.consumed === true
                ? 'Yes'
                : 'No';

        updateButtons(result);
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

        if (
            typeof payload.error_code === 'string' &&
            payload.error_code !== ''
        ) {
            return `Code: ${payload.error_code}`;
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

        const text = await response.text();

        let payload = {};

        if (text !== '') {
            try {
                payload = JSON.parse(text);
            } catch {
                payload = {};
            }
        }

        if (!response.ok) {
            const error = new Error(
                messageFromPayload(
                    payload,
                    `Request failed with status ${response.status}.`,
                ),
            );

            error.httpStatus = response.status;
            throw error;
        }

        return payload;
    }

    function clearPoll() {
        if (pollTimer !== null) {
            window.clearTimeout(pollTimer);
            pollTimer = null;
        }
    }

    function schedulePoll(delay = pollIntervalMilliseconds) {
        clearPoll();

        if (activeTransferId === null) {
            return;
        }

        pollTimer = window.setTimeout(
            () => void pollStatus(),
            delay,
        );
    }

    function toneForStatus(status) {
        if (status === 'succeeded') {
            return 'success';
        }

        if (status === 'cancelled') {
            return 'warning';
        }

        if (status === 'failed') {
            return 'error';
        }

        return 'info';
    }

    function titleForStatus(status) {
        const labels = {
            queued: 'Download queued',
            running: 'Download running',
            succeeded: 'Download completed',
            cancelled: 'Download cancelled',
            failed: 'Download failed',
        };

        return labels[status] ??
            'Transfer status updated';
    }

    async function pollStatus() {
        const transferId = activeTransferId;

        if (transferId === null) {
            return;
        }

        try {
            const result = await requestJson(
                endpoint(
                    endpoints.status,
                    transferId,
                ),
            );

            if (transferId !== activeTransferId) {
                return;
            }

            renderTransfer(result);

            const status =
                typeof result.status === 'string'
                    ? result.status
                    : 'unknown';

            setStatus(
                toneForStatus(status),
                titleForStatus(status),
                messageFromPayload(
                    result,
                    status === 'running'
                        ? 'Android is downloading in the background.'
                        : `Transfer state: ${status}.`,
                ),
            );

            await loadTransfers();

            if (terminalStatuses.has(status)) {
                clearPoll();
                updateButtons(result);

                return;
            }

            schedulePoll();
        } catch (error) {
            if (transferId !== activeTransferId) {
                return;
            }

            if (error?.httpStatus === 503) {
                setStatus(
                    'warning',
                    'Status temporarily unavailable',
                    'The app will retry automatically.',
                );

                schedulePoll(1500);

                return;
            }

            clearPoll();

            setStatus(
                'error',
                'Status check failed',
                error instanceof Error
                    ? error.message
                    : 'The native transfer status could not be read.',
            );
        }
    }

    async function loadTransfers() {
        try {
            const payload =
                await requestJson(endpoints.transfers);

            const transfers =
                Array.isArray(payload.transfers)
                    ? payload.transfers
                    : [];

            transferList.replaceChildren();

            transferEmpty.hidden =
                transfers.length !== 0;

            for (const transfer of transfers) {
                if (!isObject(transfer)) {
                    continue;
                }

                const item =
                    document.createElement('div');

                item.className =
                    'background-transfer-item';

                const title =
                    document.createElement('strong');

                title.textContent =
                    typeof transfer.status === 'string'
                        ? transfer.status
                        : 'unknown';

                const details =
                    document.createElement('span');

                details.textContent =
                    `${transfer.transfer_id ?? transfer.id ?? ''}` +
                    (
                        Number.isInteger(transfer.progress)
                            ? ` - ${transfer.progress}%`
                            : ''
                    );

                item.append(title, details);
                transferList.append(item);
            }
        } catch {
            // Current transfer polling remains independent.
        }
    }

    async function startDownload() {
        if (activeTransferId !== null) {
            return;
        }

        startButton.disabled = true;

        setStatus(
            'info',
            'Starting download',
            'Waiting for Android WorkManager to accept the transfer.',
        );

        try {
            const result =
                await requestJson(
                    endpoints.start,
                    {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': csrfToken,
                        },
                    },
                );

            if (
                result.accepted !== true ||
                !validUuid(result.transfer_id)
            ) {
                throw new Error(
                    messageFromPayload(
                        result,
                        'The native transfer was not accepted.',
                    ),
                );
            }

            rememberActiveTransfer(
                result.transfer_id,
            );

            renderTransfer(result);

            setStatus(
                'info',
                'Download queued',
                'Android accepted the background transfer.',
            );

            await loadTransfers();
            schedulePoll(0);
        } catch (error) {
            forgetActiveTransfer();
            updateButtons();

            setStatus(
                'error',
                'Download could not start',
                error instanceof Error
                    ? error.message
                    : 'The background transfer could not be started.',
            );
        }
    }

    async function cancelDownload() {
        const transferId = activeTransferId;

        if (transferId === null) {
            return;
        }

        cancelButton.disabled = true;

        try {
            const result =
                await requestJson(
                    endpoint(
                        endpoints.cancel,
                        transferId,
                    ),
                    {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': csrfToken,
                        },
                    },
                );

            renderTransfer(result);

            setStatus(
                toneForStatus(result.status),
                titleForStatus(result.status),
                messageFromPayload(
                    result,
                    'Cancellation state received.',
                ),
            );

            await loadTransfers();

            if (
                terminalStatuses.has(
                    result.status,
                )
            ) {
                clearPoll();
            } else {
                schedulePoll();
            }
        } catch (error) {
            setStatus(
                'error',
                'Cancellation failed',
                error instanceof Error
                    ? error.message
                    : 'The transfer could not be cancelled.',
            );

            schedulePoll();
        }
    }

    async function consumeResult() {
        const transferId = activeTransferId;

        if (transferId === null) {
            return;
        }

        consumeButton.disabled = true;

        try {
            const result =
                await requestJson(
                    endpoint(
                        endpoints.consume,
                        transferId,
                    ),
                    {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': csrfToken,
                        },
                    },
                );

            renderTransfer(result);

            setStatus(
                'success',
                'Result consumed',
                'The terminal native result has been marked as consumed.',
            );

            forgetActiveTransfer();
            updateButtons(result);

            await loadTransfers();
        } catch (error) {
            setStatus(
                'error',
                'Result could not be consumed',
                error instanceof Error
                    ? error.message
                    : 'The native terminal result could not be consumed.',
            );
        }
    }

    startButton.addEventListener(
        'click',
        () => void startDownload(),
    );

    cancelButton.addEventListener(
        'click',
        () => void cancelDownload(),
    );

    consumeButton.addEventListener(
        'click',
        () => void consumeResult(),
    );

    refreshButton.addEventListener(
        'click',
        () => void loadTransfers(),
    );

    document.addEventListener(
        'visibilitychange',
        () => {
            if (
                document.visibilityState === 'visible' &&
                activeTransferId !== null
            ) {
                schedulePoll(0);
            }
        },
    );

    window.addEventListener(
        'pageshow',
        () => {
            if (activeTransferId !== null) {
                schedulePoll(0);
            }
        },
    );

    const resumedTransferId =
        storedTransferId();

    if (resumedTransferId !== null) {
        rememberActiveTransfer(
            resumedTransferId,
        );

        setStatus(
            'info',
            'Restoring transfer',
            'Reading the persisted Android transfer state.',
        );

        updateButtons();
        schedulePoll(0);
    } else {
        updateButtons();
    }

    void loadTransfers();
})();
</script>
@endpush