const DEFAULT_MAX_AGE_MS = 120000;
export const MAX_LIVE_LOCATION_ACCURACY_METERS = 2500;

let lastDevicePosition = null;

function normalizePosition(position) {
    const latitude = Number(position?.coords?.latitude);
    const longitude = Number(position?.coords?.longitude);
    const accuracy = Number(position?.coords?.accuracy);
    const timestamp = Number(position?.timestamp);

    if (!Number.isFinite(latitude) || latitude < -90 || latitude > 90
        || !Number.isFinite(longitude) || longitude < -180 || longitude > 180
        || !Number.isFinite(accuracy) || accuracy < 0
        || !Number.isFinite(timestamp) || timestamp <= 0) {
        return null;
    }

    return {
        coords: { latitude, longitude, accuracy },
        timestamp,
    };
}

export function rememberDevicePosition(position) {
    const normalized = normalizePosition(position);
    if (!normalized) return null;

    lastDevicePosition = normalized;
    if (typeof window !== 'undefined') {
        window.dispatchEvent(new CustomEvent('likhae:device-location-updated', {
            detail: {
                latitude: normalized.coords.latitude,
                longitude: normalized.coords.longitude,
                accuracy: normalized.coords.accuracy,
                recorded_at: new Date(normalized.timestamp).toISOString(),
            },
        }));
    }

    return normalized;
}

export function getRecentDevicePosition(maxAgeMs = DEFAULT_MAX_AGE_MS) {
    if (!lastDevicePosition) return null;
    const age = Date.now() - lastDevicePosition.timestamp;
    return age >= 0 && age <= maxAgeMs ? lastDevicePosition : null;
}

function readPosition(options) {
    return new Promise((resolve, reject) => {
        if (!navigator.geolocation) {
            reject(new Error('This device/browser does not provide location services.'));
            return;
        }

        navigator.geolocation.getCurrentPosition(resolve, reject, options);
    });
}

export async function getDevicePosition({ maxAgeMs = DEFAULT_MAX_AGE_MS, forceFresh = false } = {}) {
    const cached = !forceFresh ? getRecentDevicePosition(maxAgeMs) : null;
    if (cached) return cached;
    if (!navigator.geolocation) {
        throw new Error('This device/browser does not provide location services.');
    }

    let best = null;
    try {
        const approximate = rememberDevicePosition(await readPosition({
            enableHighAccuracy: false,
            maximumAge: forceFresh ? 0 : maxAgeMs,
            timeout: 15000,
        }));
        if (approximate) {
            best = approximate;
            if (approximate.coords.accuracy <= MAX_LIVE_LOCATION_ACCURACY_METERS) return approximate;
        }
    } catch (error) {
        if (error?.code === 1) throw error;
    }

    try {
        const precise = rememberDevicePosition(await readPosition({
            enableHighAccuracy: true,
            maximumAge: forceFresh ? 0 : Math.min(maxAgeMs, 30000),
            timeout: 25000,
        }));
        if (precise && (!best || precise.coords.accuracy < best.coords.accuracy)) best = precise;
    } catch (error) {
        if (error?.code === 1) throw error;
        if (!best) throw error;
    }

    if (best) return best;
    throw new Error('The device could not determine its current location. Turn on Location Services and try again.');
}

if (typeof window !== 'undefined') {
    window.LikhaeDeviceLocation = {
        getPosition: getDevicePosition,
        getRecentPosition: getRecentDevicePosition,
        rememberPosition: rememberDevicePosition,
        maxLiveAccuracyMeters: MAX_LIVE_LOCATION_ACCURACY_METERS,
    };
}
