/**
 * Edit-lock keeper: keeps an open edit page's lease on its record alive, and
 * lets it go when the user leaves. Server side: App\Services\EditLocks\EditLockManager.
 *
 * Rendered by <x-edit-lock.keeper> inside the Livewire component that holds
 * the lock. The token and version are read from $wire on every beat — a morph
 * never refreshes x-data, and a dialog's keeper outlives the row it was opened for.
 *
 *  - Heartbeat every `intervalMs` (and when the tab becomes visible again, or is
 *    restored from the back/forward cache), reporting whether the user did
 *    anything since the last beat.
 *  - Anything but "still yours, same version" asks the component to sync, which
 *    reclaims a lapsed lease when nothing changed or shows the lost banner.
 *  - "idle" (no activity for the server's idle limit) pauses the keeper; the
 *    next keypress or click resumes it. Pausing matters: a beating tab would
 *    keep the session alive forever.
 *  - Leaving (wire:navigate, back/forward, a server redirect, closing the tab)
 *    sends a release beacon. Not in destroy(): a morph can re-create the
 *    keeper while the component still holds the lock.
 */

/**
 * What to do with one heartbeat outcome. `status` is null when the request
 * failed (network, redirect, non-JSON, 419/5xx).
 *
 * @param {{ status: string|null, version?: string|null, localVersion?: string|null, failures?: number }} beat
 * @returns {'continue'|'retry'|'pause'|'sync'}
 */
export function decideKeeperAction({ status, version = null, localVersion = null, failures = 0 }) {
    if (status === null || status === undefined) {
        return failures >= 2 ? 'sync' : 'retry';
    }

    if (status === 'held') {
        return version === localVersion ? 'continue' : 'sync';
    }

    if (status === 'idle') {
        return 'pause';
    }

    return 'sync';
}

/**
 * Whether the blocked panel should check again now: only while the tab is
 * visible and someone has used the page within the idle window, so a blocked
 * tab left open doesn't keep the session alive forever.
 *
 * @param {{ visible: boolean, lastActivityMs: number, nowMs: number, idleMs: number }} state
 */
export function shouldPollBlocked({ visible, lastActivityMs, nowMs, idleMs }) {
    return visible && nowMs - lastActivityMs < idleMs;
}

const ACTIVITY_EVENTS = ['keydown', 'pointerdown', 'input', 'change'];

/**
 * Alpine data factory: x-data="editLockKeeper({ heartbeatUrl, releaseUrl, csrf, intervalMs })".
 */
export function editLockKeeper(config = {}) {
    return {
        active: true,
        paused: false,
        failures: 0,
        busy: false,
        timer: null,

        init() {
            this.onActivity = () => {
                this.active = true;

                if (this.paused) {
                    this.resume();
                }
            };
            ACTIVITY_EVENTS.forEach((name) => document.addEventListener(name, this.onActivity, true));

            this.onVisible = () => {
                if (document.visibilityState === 'visible') {
                    this.beat();
                }
            };
            document.addEventListener('visibilitychange', this.onVisible);

            this.onPageShow = (event) => {
                if (event.persisted) {
                    this.beat();
                }
            };
            window.addEventListener('pageshow', this.onPageShow);

            this.onLeave = () => this.release();
            document.addEventListener('livewire:navigating', this.onLeave);
            window.addEventListener('pagehide', this.onLeave);

            this.start();
            this.beat();
        },

        destroy() {
            this.stop();
            ACTIVITY_EVENTS.forEach((name) => document.removeEventListener(name, this.onActivity, true));
            document.removeEventListener('visibilitychange', this.onVisible);
            window.removeEventListener('pageshow', this.onPageShow);
            document.removeEventListener('livewire:navigating', this.onLeave);
            window.removeEventListener('pagehide', this.onLeave);
        },

        start() {
            this.stop();
            this.timer = window.setInterval(() => this.beat(), Math.max(5000, Number(config.intervalMs) || 30000));
        },

        stop() {
            if (this.timer !== null) {
                window.clearInterval(this.timer);
                this.timer = null;
            }
        },

        token() {
            return this.$wire?.editLockToken ?? null;
        },

        /** Only a page that still holds (or is merely paused on) its lock beats. */
        holding() {
            const state = this.$wire?.editLockState;

            return state === undefined || state === 'held' || state === 'idle';
        },

        async beat() {
            const token = this.token();

            if (!token || this.paused || this.busy || !this.holding()) {
                return;
            }

            this.busy = true;
            const reportedActive = this.active;
            let status = null;
            let version = null;

            try {
                const response = await fetch(config.heartbeatUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    redirect: 'error',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': config.csrf ?? '',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({ token, active: reportedActive }),
                });

                if (response.ok && (response.headers.get('content-type') || '').includes('application/json')) {
                    const data = await response.json();
                    status = typeof data.status === 'string' ? data.status : null;
                    version = typeof data.version === 'string' ? data.version : null;
                }
            } catch (error) {
                // Offline, redirected, or blocked — treated as a transient failure below.
            } finally {
                this.busy = false;
            }

            if (token !== this.token()) {
                return; // the component took a new lock while this beat was in flight
            }

            if (status === null) {
                this.failures += 1;
            } else {
                this.failures = 0;
                this.active = false;
            }

            const action = decideKeeperAction({
                status,
                version,
                localVersion: this.$wire?.editLockVersion ?? null,
                failures: this.failures,
            });

            if (action === 'pause') {
                this.pause();
                this.$wire.syncEditLock(false);
            } else if (action === 'sync') {
                // Tell the server whether the user really did anything, and pause
                // if it decides the page has gone idle (e.g. heartbeats keep failing).
                const active = reportedActive || this.active;
                this.failures = 0;
                this.active = false;
                Promise.resolve(this.$wire.syncEditLock(active)).then(() => {
                    if (this.$wire?.editLockState === 'idle') {
                        this.pause();
                    }
                });
            }
        },

        pause() {
            this.paused = true;
            this.stop();
        },

        resume() {
            this.paused = false;
            this.$wire.resumeEditLock();
            this.start();
        },

        release() {
            const token = this.token();

            if (!token || typeof navigator.sendBeacon !== 'function') {
                return;
            }

            const body = new FormData();
            body.append('token', token);
            body.append('_token', config.csrf ?? '');
            navigator.sendBeacon(config.releaseUrl, body);
        },
    };
}

/**
 * Alpine data factory for the blocked panel: x-data="editLockBlockedPoller({ intervalMs, idleMs })".
 * Refreshes the panel (so it notices when the lock frees up) while the viewer is
 * around; stops quietly once they've gone idle, and checks again as soon as they
 * come back.
 */
export function editLockBlockedPoller(config = {}) {
    return {
        lastActivityMs: Date.now(),
        timer: null,

        init() {
            const idleMs = Number(config.idleMs) || 15 * 60 * 1000;

            this.onActivity = () => {
                const wasIdle = !shouldPollBlocked({
                    visible: true,
                    lastActivityMs: this.lastActivityMs,
                    nowMs: Date.now(),
                    idleMs,
                });
                this.lastActivityMs = Date.now();

                if (wasIdle && document.visibilityState === 'visible') {
                    this.$wire.$refresh();
                }
            };
            ACTIVITY_EVENTS.forEach((name) => document.addEventListener(name, this.onActivity, true));
            document.addEventListener('visibilitychange', this.onActivity);

            this.timer = window.setInterval(() => {
                if (shouldPollBlocked({
                    visible: document.visibilityState === 'visible',
                    lastActivityMs: this.lastActivityMs,
                    nowMs: Date.now(),
                    idleMs,
                })) {
                    this.$wire.$refresh();
                }
            }, Math.max(5000, Number(config.intervalMs) || 15000));
        },

        destroy() {
            window.clearInterval(this.timer);
            ACTIVITY_EVENTS.forEach((name) => document.removeEventListener(name, this.onActivity, true));
            document.removeEventListener('visibilitychange', this.onActivity);
        },
    };
}
