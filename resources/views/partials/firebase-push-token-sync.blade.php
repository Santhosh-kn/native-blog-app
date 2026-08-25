<script>
    (() => {
        const syncUrl = @json(route('push.sync'));
        const csrfToken =
            document.querySelector('meta[name="csrf-token"]')
                ?.content;

        let syncInProgress = false;
        let lastAttemptAt = 0;

        const minimumInterval = 30_000;

        async function synchronizeStoredPushToken() {
            const now = Date.now();

            if (
                !csrfToken ||
                syncInProgress ||
                document.visibilityState === 'hidden' ||
                now - lastAttemptAt < minimumInterval
            ) {
                return;
            }

            syncInProgress = true;
            lastAttemptAt = now;

            try {
                await fetch(syncUrl, {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                    cache: 'no-store',
                });
            } catch {
                // Synchronization will be attempted again later.
            } finally {
                syncInProgress = false;
            }
        }

        function scheduleSynchronization() {
            window.setTimeout(
                synchronizeStoredPushToken,
                500,
            );
        }

        if (document.readyState === 'loading') {
            document.addEventListener(
                'DOMContentLoaded',
                scheduleSynchronization,
                { once: true },
            );
        } else {
            scheduleSynchronization();
        }

        document.addEventListener(
            'visibilitychange',
            () => {
                if (document.visibilityState === 'visible') {
                    scheduleSynchronization();
                }
            },
        );

        window.addEventListener(
            'focus',
            scheduleSynchronization,
        );

        window.addEventListener(
            'pageshow',
            scheduleSynchronization,
        );
    })();
</script>