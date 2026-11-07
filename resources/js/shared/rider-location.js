import { getDevicePosition, rememberDevicePosition } from './device-location';

const LOCATION_UPDATE_INTERVAL_MS = 10000;
const LOCATION_MOVEMENT_THRESHOLD_METERS = 25;
const MAX_ACCURACY_METERS = 1500;
const MAX_TIMESTAMP_SKEW_MS = 120000;

function distanceMeters(first, second) {
    if (!first || !second) return Number.POSITIVE_INFINITY;
    const toRadians = (value) => value * Math.PI / 180;
    const earthRadius = 6371000;
    const dLat = toRadians(second[1] - first[1]);
    const dLng = toRadians(second[0] - first[0]);
    const a = Math.sin(dLat / 2) ** 2
        + Math.cos(toRadians(first[1])) * Math.cos(toRadians(second[1])) * Math.sin(dLng / 2) ** 2;
    return earthRadius * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
}

function parseAssignments(shell) {
    try {
        const assignments = JSON.parse(shell.dataset.riderLocationAssignments || '[]');
        return Array.isArray(assignments) ? assignments.filter((assignment) => assignment?.endpoint) : [];
    } catch {
        return [];
    }
}

function initializeRiderLocationTracker() {
    const shell = document.querySelector('[data-rider-live-location]');
    if (!shell || window.LikhaeRiderLocationTracker) return;

    let assignments = parseAssignments(shell);
    let watchId;
    let refreshInProgress = false;
    let lastSentAt = 0;
    let lastCoordinates = null;
    let stoppedByRider = false;
    let retryTimer;

    const statusElement = shell.querySelector('[data-rider-location-status]');
    const controls = () => [
        ...document.querySelectorAll('[data-rider-location-toggle], [data-map-rider-sharing]'),
    ];

    const setStatus = (message, state = 'ready') => {
        if (statusElement) {
            statusElement.textContent = message;
            statusElement.dataset.state = state;
            statusElement.classList.toggle('is-error', ['error', 'offline', 'poor-accuracy'].includes(state));
        }
        shell.dataset.riderLocationState = state;
        window.dispatchEvent(new CustomEvent('likhae:rider-location-status', {
            detail: { message, state, running: watchId !== undefined },
        }));
    };

    const syncControls = () => {
        const active = watchId !== undefined;
        controls().forEach((button) => {
            button.hidden = assignments.length === 0 || !navigator.geolocation;
            button.textContent = active ? 'Pause rider GPS' : 'Resume rider GPS';
            button.setAttribute('aria-pressed', String(active));
        });
    };

    const stop = (message = 'Rider GPS tracking paused.') => {
        if (watchId !== undefined && navigator.geolocation) {
            navigator.geolocation.clearWatch(watchId);
        }
        if (retryTimer !== undefined) window.clearTimeout(retryTimer);
        retryTimer = undefined;
        watchId = undefined;
        lastSentAt = 0;
        lastCoordinates = null;
        syncControls();
        if (message) setStatus(message, 'paused');
    };

    const removeAssignment = (endpoint) => {
        assignments = assignments.filter((assignment) => assignment.endpoint !== endpoint);
        if (!assignments.length) {
            stop('The active rider assignment has ended. GPS tracking stopped.');
        } else {
            syncControls();
        }
    };

    const postLocation = async (assignment, payload) => {
        const controller = new AbortController();
        const timeout = window.setTimeout(() => controller.abort(), 10000);
        try {
            const response = await fetch(assignment.endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                signal: controller.signal,
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify(payload),
            });

            if (response.ok) {
                const body = await response.json();
                return { ok: true, location: body?.data || payload };
            }
            let details = null;
            try { details = await response.json(); } catch { /* Status remains actionable without JSON. */ }
            if (response.status === 403 || response.status === 409) removeAssignment(assignment.endpoint);
            const message = {
                401: 'Your Rider session expired. Sign in again, then resume GPS sharing.',
                403: 'This Rider account cannot update this assignment. GPS sharing was stopped.',
                409: 'This assignment is no longer in a travel state. GPS sharing was stopped.',
                419: 'Your session verification expired. Refresh this page, sign in if needed, then resume GPS.',
                422: details?.message || 'This GPS fix was rejected. Check accuracy and wait for a fresh device fix.',
                500: 'The server could not process this GPS update. It will retry with the next fix.',
            }[response.status] || `GPS update failed (HTTP ${response.status}). It will retry with the next fix.`;
            return { ok: false, message, state: [401, 403, 409, 419, 422].includes(response.status) ? 'error' : 'offline' };
        } catch (error) {
            return {
                ok: false,
                message: error?.name === 'AbortError'
                    ? 'The GPS update timed out. It will retry with the next fix.'
                    : 'GPS was read, but the server could not receive the update. Retrying.',
                state: 'offline',
            };
        } finally {
            window.clearTimeout(timeout);
        }
    };

    const send = (position) => {
        if (!assignments.length || !navigator.onLine) {
            setStatus('Device offline. GPS updates will resume when the connection returns.', 'offline');
            return;
        }

        const latitude = Number(position.coords?.latitude);
        const longitude = Number(position.coords?.longitude);
        const accuracy = Number(position.coords?.accuracy);
        const timestamp = Number(position.timestamp);
        rememberDevicePosition(position);
        const coordinates = [longitude, latitude];
        const recordedAt = new Date(timestamp);

        if (!Number.isFinite(latitude) || !Number.isFinite(longitude)
            || latitude < -90 || latitude > 90 || longitude < -180 || longitude > 180
            || !Number.isFinite(timestamp) || Number.isNaN(recordedAt.getTime())) {
            setStatus('The device returned an invalid GPS coordinate. Waiting for a valid fix.', 'error');
            return;
        }

        if (!Number.isFinite(accuracy) || accuracy < 0 || accuracy > MAX_ACCURACY_METERS) {
            setStatus(`Device location accuracy is too low (${Number.isFinite(accuracy) ? `±${Math.round(accuracy)}m` : 'unknown'}). Keep Location Services on; the map will update when the device gets a clearer fix.`, 'poor-accuracy');
            return;
        }

        if (Math.abs(Date.now() - timestamp) > MAX_TIMESTAMP_SKEW_MS) {
            setStatus('The device GPS timestamp is stale. Waiting for a fresh fix.', 'error');
            return;
        }

        const now = Date.now();
        if (lastCoordinates
            && now - lastSentAt < LOCATION_UPDATE_INTERVAL_MS
            && distanceMeters(lastCoordinates, coordinates) < LOCATION_MOVEMENT_THRESHOLD_METERS) return;

        lastSentAt = now;
        lastCoordinates = coordinates;
        setStatus(accuracy > 250
            ? `Device location is live (±${Math.round(accuracy)}m). Keep this Rider page open while traveling.`
            : 'Live GPS is active. Keep this Rider page in the foreground while traveling.', 'active');
        const payload = {
            latitude,
            longitude,
            accuracy,
            recorded_at: recordedAt.toISOString(),
        };
        // This is a fresh, accuracy-checked device GPS fix from
        // watchPosition(). Let the map calculate the real road route now;
        // persistence and the shared tracking feed continue independently.
        assignments.forEach((assignment) => {
            window.dispatchEvent(new CustomEvent('likhae:rider-location-read', {
                detail: { assignmentId: assignment.id, ...payload },
            }));
        });
        Promise.all(assignments.map((assignment) => postLocation(assignment, payload)))
            .then((results) => {
                if (assignments.length && results.some((result) => result.ok)) {
                    // The server has accepted actual device GPS. Update this
                    // page's map immediately instead of waiting for Reverb or
                    // the next fallback poll to reflect the saved fix.
                    results.forEach((result, index) => {
                        if (!result.ok) return;
                        window.dispatchEvent(new CustomEvent('likhae:rider-location-saved', {
                            detail: {
                                assignmentId: assignments[index].id,
                                ...result.location,
                            },
                        }));
                    });
                    const accepted = results.find((result) => result.ok);
                    setStatus(Number(accepted?.location?.accuracy) > 250
                        ? `Device location is live (±${Math.round(Number(accepted.location.accuracy))}m). Keep this Rider page open while traveling.`
                        : 'Live GPS is active. Keep this Rider page in the foreground while traveling.', 'active');
                } else if (assignments.length) {
                    const failure = results.find((result) => result.message);
                    setStatus(failure?.message || 'GPS was read, but the server could not receive the update. Retrying.', failure?.state || 'offline');
                }
            });
    };

    const startWatch = (highAccuracy) => {
        if (watchId !== undefined && navigator.geolocation) {
            navigator.geolocation.clearWatch(watchId);
        }
        watchId = navigator.geolocation.watchPosition((position) => {
            const accuracy = Number(position.coords?.accuracy);
            if (!highAccuracy && (!Number.isFinite(accuracy) || accuracy > MAX_ACCURACY_METERS)) {
                setStatus(`Approximate location received (${Number.isFinite(accuracy) ? `${Math.round(accuracy)}m` : 'unknown'} accuracy). Requesting a more precise Rider GPS fix…`, 'poor-accuracy');
                startWatch(true);
                return;
            }
            send(position);
        }, (error) => {
            if (error.code === 1) {
                stop('Chrome denied location access. Allow location for this site and resume Rider GPS.');
                shell.dataset.riderLocationState = 'permission-denied';
                return;
            }
            if (!highAccuracy) {
                setStatus('The first GPS request did not get a fix. Trying high-accuracy Rider GPS…', 'ready');
                startWatch(true);
                return;
            }
            if (watchId !== undefined && navigator.geolocation) navigator.geolocation.clearWatch(watchId);
            watchId = undefined;
            syncControls();
            setStatus(error.code === 2
                ? 'Device location is temporarily unavailable. Check Location Services; Rider GPS will retry automatically.'
                : 'Device location timed out. Rider GPS will retry automatically.', 'error');
            if (!stoppedByRider) {
                if (retryTimer !== undefined) window.clearTimeout(retryTimer);
                retryTimer = window.setTimeout(() => {
                    retryTimer = undefined;
                    startWatch(false);
                }, 10000);
            }
        }, {
            enableHighAccuracy: highAccuracy,
            maximumAge: highAccuracy ? 0 : 30000,
            timeout: highAccuracy ? 25000 : 15000,
        });
        syncControls();
    };

    const start = () => {
        if (watchId !== undefined || !assignments.length) return;
        if (!navigator.geolocation) {
            setStatus('This browser does not support device GPS.', 'error');
            syncControls();
            return;
        }
        if (!navigator.onLine) {
            setStatus('Device offline. GPS will start sending when the connection returns.', 'offline');
        } else {
            setStatus('Requesting live Rider GPS. Chrome may use network location first, then precise device GPS…', 'ready');
        }
        stoppedByRider = false;
        startWatch(false);
    };

    const refresh = () => {
        if (!assignments.length) return;
        if (!navigator.geolocation) {
            setStatus('This browser does not support device GPS.', 'error');
            return;
        }

        if (refreshInProgress) return;
        refreshInProgress = true;
        setStatus('Requesting a fresh Rider GPS fix. Trying the browser location provider first…', 'ready');

        getDevicePosition({ maxAgeMs: 30000, forceFresh: true }).then(send).catch((error) => {
            if (error.code === 1) {
                setStatus('Chrome denied location access. Allow location for this site and resume Rider GPS.', 'error');
            } else if (error.code === 2) {
                setStatus('Chrome has location permission, but the device could not determine a position. Turn on Location Services and try again.', 'error');
            } else {
                setStatus(error?.message || 'The device location request timed out. Keep this page open and try again.', 'error');
            }
        }).finally(() => {
            refreshInProgress = false;
        });
    };

    const toggle = () => {
        if (watchId === undefined) {
            stoppedByRider = false;
            start();
        } else {
            stoppedByRider = true;
            stop();
        }
    };

    const tracker = {
        start,
        refresh,
        stop,
        toggle,
        isRunning: () => watchId !== undefined,
    };
    window.LikhaeRiderLocationTracker = tracker;

    controls().forEach((button) => button.addEventListener('click', toggle));
    window.addEventListener('online', () => {
        if (assignments.length && watchId !== undefined) setStatus('Connection restored. Resuming live GPS updates.', 'active');
        else if (assignments.length && !stoppedByRider) start();
    });
    window.addEventListener('offline', () => setStatus('Device offline. GPS updates will resume when the connection returns.', 'offline'));
    window.addEventListener('pagehide', () => stop(null), { once: true });

    syncControls();
    start();
}

document.addEventListener('DOMContentLoaded', initializeRiderLocationTracker);

export { initializeRiderLocationTracker };
