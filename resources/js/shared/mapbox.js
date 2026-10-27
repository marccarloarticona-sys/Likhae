const MAPBOX_SCRIPT = 'https://api.mapbox.com/mapbox-gl-js/v3.15.0/mapbox-gl.js';
const MAPBOX_CSS = 'https://api.mapbox.com/mapbox-gl-js/v3.15.0/mapbox-gl.css';
const MAPLIBRE_SCRIPT = 'https://unpkg.com/maplibre-gl@5.12.0/dist/maplibre-gl.js';
const MAPLIBRE_CSS = 'https://unpkg.com/maplibre-gl@5.12.0/dist/maplibre-gl.css';
const OPENFREEMAP_STYLE = 'https://tiles.openfreemap.org/styles/liberty';
const ROUTE_RECALCULATION_DISTANCE_METERS = 100;
const ROUTE_OFF_PATH_DISTANCE_METERS = 100;
const ROUTE_MAX_AGE_MS = 120000;
const ROUTE_RETRY_DELAY_MS = 30000;
const ORDER_ROUTE_POLL_INTERVAL_MS = 10000;

let mapboxPromise;

function loadMapbox() {
    if (window.mapboxgl) return Promise.resolve(window.mapboxgl);
    if (mapboxPromise) return mapboxPromise;

    if (!document.querySelector('link[data-likhae-mapbox-css]')) {
        const link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = MAPBOX_CSS;
        link.dataset.likhaeMapboxCss = 'true';
        document.head.appendChild(link);
    }

    mapboxPromise = new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = MAPBOX_SCRIPT;
        script.async = true;
        script.onload = () => window.mapboxgl
            ? resolve(window.mapboxgl)
            : reject(new Error('Mapbox loaded without its browser API.'));
        script.onerror = () => reject(new Error('Mapbox could not be loaded.'));
        document.head.appendChild(script);
    });

    return mapboxPromise;
}

export { loadMapbox };

function loadMapLibre() {
    if (window.maplibregl) return Promise.resolve(window.maplibregl);
    if (window._likhaeMapLibrePromise) return window._likhaeMapLibrePromise;

    if (!document.querySelector('link[data-likhae-maplibre-css]')) {
        const link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = MAPLIBRE_CSS;
        link.dataset.likhaeMaplibreCss = 'true';
        document.head.appendChild(link);
    }

    window._likhaeMapLibrePromise = new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = MAPLIBRE_SCRIPT;
        script.async = true;
        script.onload = () => window.maplibregl
            ? resolve(window.maplibregl)
            : reject(new Error('The map renderer loaded without its browser API.'));
        script.onerror = () => reject(new Error('The map renderer could not be loaded.'));
        document.head.appendChild(script);
    });

    return window._likhaeMapLibrePromise;
}

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>'"]/g, (character) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;',
    }[character]));
}

function parseJson(value, fallback) {
    try { return JSON.parse(value); } catch { return fallback; }
}

function updateStatus(shell, message, isError = false) {
    const status = shell.querySelector('[data-map-status]');
    if (!status) return;
    const notice = shell._likhaeRealtimeNotice;
    status.textContent = notice ? `${message} ${notice}` : message;
    status.classList.toggle('is-error', isError || shell._likhaeRealtimeNoticeError === true);
}

function updateTrackingStatus(shell, message, isError = false) {
    updateStatus(shell, message, isError);
}

function toggleFullscreen(shell, map) {
    const fullscreen = shell.classList.toggle('is-fullscreen');
    const button = shell.querySelector('[data-map-fullscreen]');
    if (button) {
        button.textContent = fullscreen ? 'Close full map' : 'Open full map';
        button.setAttribute('aria-pressed', String(fullscreen));
    }
    document.body.classList.toggle('lk-map-lock-scroll', fullscreen);
    window.setTimeout(() => map.resize(), 80);
}

function markerCoordinates(marker) {
    const lat = Number(marker?.latitude);
    const lng = Number(marker?.longitude);
    if (!Number.isFinite(lat) || !Number.isFinite(lng)
        || lat < -90 || lat > 90 || lng < -180 || lng > 180) return null;
    return [lng, lat];
}

function drawRouteGeometry(map, id, geometry, color = '#800000', dash = false, width = 3) {
    const sourceId = `likhae-route-source-${id}`;
    const layerId = `likhae-route-layer-${id}`;
    const data = {
        type: 'Feature',
        properties: {},
        geometry,
    };

    if (map.getSource(sourceId)) {
        map.getSource(sourceId).setData(data);
        if (map.getLayer(layerId)) return;
    } else {
        map.addSource(sourceId, { type: 'geojson', data });
    }
    map.addLayer({
        id: layerId,
        type: 'line',
        source: sourceId,
        layout: { 'line-cap': 'round', 'line-join': 'round' },
        paint: {
            'line-color': color,
            'line-width': width,
            'line-opacity': 0.82,
            ...(dash ? { 'line-dasharray': [1.5, 1.5] } : {}),
        },
    });
}

function orderRouteProgress(status, stops, routeState, shipmentMarkers) {
    const legDistances = routeState?.legDistances || [];
    const totalDistance = legDistances.reduce((sum, distance) => sum + distance, 0);
    if (!totalDistance) return 0;

    const centerIndices = stops
        .map((stop, index) => stop.kind === 'center' ? index : -1)
        .filter((index) => index >= 0);
    const firstCenterIndex = centerIndices[0] ?? -1;
    const lastCenterIndex = centerIndices[centerIndices.length - 1] ?? -1;
    const distanceToCenter = (index) => index > 0
        ? legDistances.slice(0, index).reduce((sum, distance) => sum + distance, 0)
        : 0;
    const firstCenterDistance = distanceToCenter(firstCenterIndex);
    const lastCenterDistance = distanceToCenter(lastCenterIndex);
    const receivedCenterIndices = stops
        .map((stop, index) => ({ stop, index }))
        .filter(({ stop }) => stop.kind === 'center'
            && stop.center_role === 'destination'
            && stop.transfer_status === 'RECEIVED')
        .map(({ index }) => index);
    const lastReceivedCenterIndex = receivedCenterIndices[receivedCenterIndices.length - 1] ?? -1;
    let distance = 0;

    switch (status) {
        case 'AT_SORTING_CENTER':
        case 'SORTED':
            distance = lastReceivedCenterIndex >= 0
                ? distanceToCenter(lastReceivedCenterIndex)
                : firstCenterDistance;
            break;
        case 'ASSIGNED_TO_RIDER':
        case 'OUT_FOR_DELIVERY':
        case 'DELIVERY_FAILED':
            // Final-mile assignment only happens after the parcel reaches its delivery hub.
            distance = lastCenterDistance;
            break;
        case 'DELIVERED':
        case 'COMPLETED':
            distance = totalDistance;
            break;
        case 'RETURNED':
        case 'CANCELLED':
            distance = 0;
            break;
        default:
            distance = 0;
    }

    const rider = shipmentMarkers.find((marker) => marker.kind === 'rider'
        && marker.active_rider
        && markerCoordinates(marker));
    if (rider && ['OUT_FOR_DELIVERY', 'DELIVERY_FAILED'].includes(status)) {
        const geometry = routeState.legGeometries || [];
        const deliveryLegIndex = lastCenterIndex >= 0 ? lastCenterIndex : 0;
        const deliveryLeg = geometry[deliveryLegIndex];
        const riderCoordinates = markerCoordinates(rider);
        const position = deliveryLeg && riderCoordinates
            ? nearestRoutePosition(deliveryLeg.coordinates, riderCoordinates)
            : null;
        if (position && position.distance <= 300) {
            const distanceBeforeLeg = legDistances.slice(0, deliveryLegIndex)
                .reduce((sum, legDistance) => sum + legDistance, 0);
            distance = Math.max(distance, distanceBeforeLeg + (legDistances[deliveryLegIndex] * position.progress));
        }
    }

    return Math.max(0, Math.min(1, distance / totalDistance));
}

function hubTransferProgressRange(stops, routeState, shipmentMarkers) {
    if (!shipmentMarkers.some((marker) => marker.transfer_status === 'IN_TRANSIT')) return null;

    const originIndex = stops.findIndex((stop) => stop.kind === 'center' && stop.center_role === 'origin');
    const destinationIndex = stops.findIndex((stop) => stop.kind === 'center' && stop.center_role === 'destination');
    const legDistances = routeState?.legDistances || [];
    const totalDistance = legDistances.reduce((sum, distance) => sum + distance, 0);
    if (originIndex < 0 || destinationIndex <= originIndex || totalDistance <= 0) return null;

    const startDistance = legDistances.slice(0, originIndex).reduce((sum, distance) => sum + distance, 0);
    const endDistance = legDistances.slice(0, destinationIndex).reduce((sum, distance) => sum + distance, 0);
    if (endDistance <= startDistance) return null;

    return {
        start: startDistance / totalDistance,
        end: endDistance / totalDistance,
    };
}

function nearestRoutePosition(coordinates, point) {
    if (!Array.isArray(coordinates) || coordinates.length < 2 || !point) return null;
    const latitudeScale = 110540;
    const longitudeScale = 111320 * Math.cos((point[1] * Math.PI) / 180);
    const project = (coordinate) => [coordinate[0] * longitudeScale, coordinate[1] * latitudeScale];
    const projectedPoint = project(point);
    const totalLength = coordinates.slice(1).reduce((sum, coordinate, index) =>
        sum + distanceMeters(coordinates[index], coordinate), 0);
    if (!totalLength) return null;

    let distanceBeforeSegment = 0;
    let nearest = null;
    for (let index = 1; index < coordinates.length; index += 1) {
        const start = coordinates[index - 1];
        const end = coordinates[index];
        const projectedStart = project(start);
        const projectedEnd = project(end);
        const vector = [projectedEnd[0] - projectedStart[0], projectedEnd[1] - projectedStart[1]];
        const lengthSquared = (vector[0] ** 2) + (vector[1] ** 2);
        const ratio = lengthSquared === 0
            ? 0
            : Math.max(0, Math.min(1, ((projectedPoint[0] - projectedStart[0]) * vector[0]
                + (projectedPoint[1] - projectedStart[1]) * vector[1]) / lengthSquared));
        const closest = [projectedStart[0] + (vector[0] * ratio), projectedStart[1] + (vector[1] * ratio)];
        const distance = Math.hypot(projectedPoint[0] - closest[0], projectedPoint[1] - closest[1]);
        const segmentLength = distanceMeters(start, end);
        if (!nearest || distance < nearest.distance) {
            nearest = {
                distance,
                progress: (distanceBeforeSegment + (segmentLength * ratio)) / totalLength,
            };
        }
        distanceBeforeSegment += segmentLength;
    }

    return nearest;
}

function splitRouteAtProgress(coordinates, progress) {
    if (!Array.isArray(coordinates) || coordinates.length < 2) return { completed: [], future: [] };
    const safeProgress = Math.max(0, Math.min(1, progress));
    if (safeProgress <= 0) return { completed: [], future: coordinates };
    if (safeProgress >= 1) return { completed: coordinates, future: [] };

    const totalLength = coordinates.slice(1).reduce((sum, coordinate, index) =>
        sum + distanceMeters(coordinates[index], coordinate), 0);
    const targetDistance = totalLength * safeProgress;
    const completed = [coordinates[0]];
    let future = [];
    let distanceBeforeSegment = 0;

    for (let index = 1; index < coordinates.length; index += 1) {
        const start = coordinates[index - 1];
        const end = coordinates[index];
        const segmentLength = distanceMeters(start, end);
        if (distanceBeforeSegment + segmentLength < targetDistance) {
            completed.push(end);
            distanceBeforeSegment += segmentLength;
            continue;
        }

        const ratio = segmentLength ? (targetDistance - distanceBeforeSegment) / segmentLength : 0;
        const split = [
            start[0] + (end[0] - start[0]) * ratio,
            start[1] + (end[1] - start[1]) * ratio,
        ];
        completed.push(split);
        future = [split, ...coordinates.slice(index)];
        break;
    }

    return { completed, future };
}

function removeOrderProgressMarker(shell, shipmentId) {
    const key = String(shipmentId);
    const marker = shell._likhaeOrderProgressMarkers?.[key];
    if (!marker) return;
    marker.remove();
    delete shell._likhaeOrderProgressMarkers[key];
}

function drawOrderRouteSegments(map, routeId, routeState, progress, activeTransferRange = null) {
    const coordinates = routeState?.geometry?.coordinates || [];
    if (coordinates.length < 2) return;
    const drawSegment = (suffix, segment, color, dash) => {
        const segmentId = `${routeId}-${suffix}`;
        if (segment.length < 2) {
            removeRouteLayer(map, segmentId);
            return;
        }
        drawRouteGeometry(map, segmentId, { type: 'LineString', coordinates: segment }, color, dash, 4);
    };

    if (activeTransferRange) {
        const beforeTransfer = splitRouteAtProgress(coordinates, activeTransferRange.start);
        const transferProgress = (activeTransferRange.end - activeTransferRange.start) / (1 - activeTransferRange.start);
        const transferSegments = splitRouteAtProgress(beforeTransfer.future, transferProgress);
        drawSegment('traveled', beforeTransfer.completed, '#800000', false);
        drawSegment('active-transfer', transferSegments.completed, '#c2410c', true);
        drawSegment('remaining', transferSegments.future, '#9ca3af', false);
    } else {
        const segments = splitRouteAtProgress(coordinates, progress);
        drawSegment('traveled', segments.completed, '#800000', false);
        drawSegment('remaining', segments.future, '#9ca3af', false);
        removeRouteLayer(map, `${routeId}-active-transfer`);
    }
    removeRouteLayer(map, routeId);
}

function removeOrderRouteLayers(map, routeId) {
    removeRouteLayer(map, routeId);
    removeRouteLayer(map, `${routeId}-traveled`);
    removeRouteLayer(map, `${routeId}-active-transfer`);
    removeRouteLayer(map, `${routeId}-remaining`);
}

function drawOrderRoutes(map, mapboxgl, markers, shell) {
    if (shell.dataset.mapOrderRoute !== 'true') return;

    const grouped = markers.reduce((groups, marker) => {
        if (marker.shipment_id) {
            groups[marker.shipment_id] ||= [];
            groups[marker.shipment_id].push(marker);
        }
        return groups;
    }, {});
    const activeRouteIds = new Set();
    const routeOrder = { seller: 0, center: 1, destination: 2 };
    const centerRoleOrder = { origin: 0, destination: 1 };
    const token = shell.dataset.mapToken?.trim() || '';
    shell._likhaeOrderRouteState ||= {};

    Object.entries(grouped).forEach(([shipmentId, shipmentMarkers]) => {
        const stops = shipmentMarkers
            .filter((marker) => Object.prototype.hasOwnProperty.call(routeOrder, marker.kind))
            .sort((a, b) => routeOrder[a.kind] - routeOrder[b.kind]
                || (Number(a.sequence_no) || 0) - (Number(b.sequence_no) || 0)
                || (centerRoleOrder[a.center_role] ?? 0) - (centerRoleOrder[b.center_role] ?? 0))
            .filter((marker) => markerCoordinates(marker));
        const stopCoordinates = stops.map(markerCoordinates);
        if (stopCoordinates.length < 2) {
            removeOrderProgressMarker(shell, shipmentId);
            return;
        }

        const routeId = `order-${shipmentId}`;
        activeRouteIds.add(routeId);
        const signature = stopCoordinates.map(([longitude, latitude]) => `${longitude},${latitude}`).join(';');
        const status = shipmentMarkers.find((marker) => marker.tracking_status)?.tracking_status
            || (String(shell._likhaeCurrentTracking?.shipment_id || '') === String(shipmentId)
                ? shell._likhaeCurrentTracking?.status
                : null)
            || 'PLACED';
        let routeState = shell._likhaeOrderRouteState[shipmentId];

        if (routeState?.signature !== signature) {
            removeOrderRouteLayers(map, routeId);
            routeState = { signature, pending: false, geometry: null, legDistances: [], legGeometries: [] };
            shell._likhaeOrderRouteState[shipmentId] = routeState;
        }

        removeOrderProgressMarker(shell, shipmentId);

        if (routeState.geometry) {
            let progress = orderRouteProgress(status, stops, routeState, shipmentMarkers);
            const activeTransferRange = hubTransferProgressRange(stops, routeState, shipmentMarkers);
            if (['RETURNED', 'CANCELLED'].includes(status)) {
                routeState.confirmedProgress = 0;
            } else {
                progress = Math.max(routeState.confirmedProgress || 0, progress);
                routeState.confirmedProgress = progress;
            }
            drawOrderRouteSegments(map, routeId, routeState, progress, activeTransferRange);
            return;
        }

        if (routeState.pending
            || (!token && routeState.noToken)
            || (routeState.failedAt && Date.now() - routeState.failedAt < ROUTE_RETRY_DELAY_MS)) return;

        if (!token) {
            routeState.noToken = true;
            routeState.failedAt = Date.now();
            updateStatus(shell, 'Road-following directions need a Mapbox public token. Shipment progress is shown at saved stops.', true);
            return;
        }

        routeState.pending = true;
        const routePromise = Promise.all(stopCoordinates.slice(1).map((coordinate, index) =>
            fetchRoadGeometry(token, stopCoordinates[index], coordinate)));
        routePromise.then((legs) => {
            if (shell._likhaeOrderRouteState?.[shipmentId] !== routeState) return;
            const coordinates = legs.reduce((joined, leg, index) => {
                const next = leg.geometry.coordinates;
                joined.push(...(index ? next.slice(1) : next));
                return joined;
            }, []);
            if (coordinates.length < 2) throw new Error('Mapbox returned no drivable order route.');

            routeState.pending = false;
            routeState.geometry = { type: 'LineString', coordinates };
            routeState.legDistances = legs.map((leg) => leg.distance);
            routeState.legGeometries = legs.map((leg) => leg.geometry);
            routeState.fetchedAt = Date.now();
            drawOrderRoutes(map, mapboxgl, shell._likhaeCurrentMarkers || markers, shell);
        }).catch((error) => {
            if (shell._likhaeOrderRouteState?.[shipmentId] !== routeState) return;
            routeState.pending = false;
            routeState.failedAt = Date.now();
            drawOrderRoutes(map, mapboxgl, shell._likhaeCurrentMarkers || markers, shell);
            updateStatus(shell, error?.message || 'The road-following order route is temporarily unavailable.', true);
        });
    });

    (shell._likhaeOrderRouteIds || [])
        .filter((routeId) => !activeRouteIds.has(routeId))
        .forEach((routeId) => removeOrderRouteLayers(map, routeId));
    const activeShipmentIds = new Set(Object.keys(grouped));
    Object.keys(shell._likhaeOrderRouteState)
        .filter((shipmentId) => !activeShipmentIds.has(shipmentId))
        .forEach((shipmentId) => {
            removeOrderProgressMarker(shell, shipmentId);
            delete shell._likhaeOrderRouteState[shipmentId];
        });
    shell._likhaeOrderRouteIds = [...activeRouteIds];
}

async function fetchRoadGeometry(token, from, to) {
    const coordinates = `${from[0]},${from[1]};${to[0]},${to[1]}`;
    const url = `https://api.mapbox.com/directions/v5/mapbox/driving/${coordinates}?overview=full&geometries=geojson&access_token=${encodeURIComponent(token)}`;
    const response = await fetch(url, { headers: { Accept: 'application/json' } });
    if (!response.ok) {
        const message = response.status === 401 || response.status === 403
            ? `Mapbox Directions authorization failed (HTTP ${response.status}). Check the browser-safe token and its URL restrictions.`
            : `Mapbox Directions request failed (HTTP ${response.status}).`;
        throw new Error(message);
    }
    const payload = await response.json();
    const route = payload.routes?.[0];
    if (!route?.geometry?.coordinates?.length) throw new Error('Mapbox returned no drivable route for these saved coordinates.');
    if (!Number.isFinite(Number(route.distance)) || !Number.isFinite(Number(route.duration))) {
        throw new Error('The driving route did not include distance and duration.');
    }
    return {
        geometry: route.geometry,
        distance: Number(route.distance),
        duration: Number(route.duration),
    };
}

function distancePointToSegmentMeters(point, start, end) {
    const latitudeScale = 110540;
    const longitudeScale = 111320 * Math.cos((point[1] * Math.PI) / 180);
    const project = (coordinate) => [coordinate[0] * longitudeScale, coordinate[1] * latitudeScale];
    const projectedPoint = project(point);
    const projectedStart = project(start);
    const projectedEnd = project(end);
    const vector = [projectedEnd[0] - projectedStart[0], projectedEnd[1] - projectedStart[1]];
    const lengthSquared = (vector[0] ** 2) + (vector[1] ** 2);
    const ratio = lengthSquared === 0
        ? 0
        : Math.max(0, Math.min(1, ((projectedPoint[0] - projectedStart[0]) * vector[0]
            + (projectedPoint[1] - projectedStart[1]) * vector[1]) / lengthSquared));
    const closest = [projectedStart[0] + (vector[0] * ratio), projectedStart[1] + (vector[1] * ratio)];
    return Math.hypot(projectedPoint[0] - closest[0], projectedPoint[1] - closest[1]);
}

function distanceToRouteMeters(point, geometry) {
    const coordinates = geometry?.coordinates;
    if (!point || !Array.isArray(coordinates) || coordinates.length < 2) return Number.POSITIVE_INFINITY;
    let nearest = Number.POSITIVE_INFINITY;
    for (let index = 1; index < coordinates.length; index += 1) {
        nearest = Math.min(nearest, distancePointToSegmentMeters(point, coordinates[index - 1], coordinates[index]));
    }
    return nearest;
}

function formatRoadDistance(distance) {
    if (!Number.isFinite(distance)) return '—';
    if (distance < 1000) return `${Math.max(0, Math.round(distance))} m`;
    return `${(distance / 1000).toFixed(distance >= 10000 ? 0 : 1)} km`;
}

function formatRouteDuration(duration) {
    if (!Number.isFinite(duration)) return '—';
    const minutes = Math.max(0, Math.ceil(duration / 60));
    if (minutes < 60) return `${minutes} min`;
    const hours = Math.floor(minutes / 60);
    const remainder = minutes % 60;
    return remainder ? `${hours}h ${remainder}m` : `${hours}h`;
}

function formatArrivalTime(duration, arrivalAt = null) {
    if (!Number.isFinite(duration)) return '—';
    const parts = new Intl.DateTimeFormat('en-PH', {
        timeZone: 'Asia/Manila',
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        hour12: true,
    }).formatToParts(new Date(Number.isFinite(arrivalAt) ? arrivalAt : Date.now() + (duration * 1000)));
    const values = Object.fromEntries(parts.map(({ type, value }) => [type, value]));
    return `${values.month} ${values.day}, ${values.year}, ${values.hour}:${values.minute} ${values.dayPeriod} PHT`;
}

function navigationDestination(target) {
    return target?.navigation_destination || {
        name: target?.title || target?.label || 'Active destination',
        address: target?.popup || 'Saved destination coordinates',
    };
}

function navigationMetricPlaceholder(shell, rider, target, status) {
    const canRequestRoute = Boolean(
        markerCoordinates(rider)
        && markerCoordinates(target)
        && shell.dataset.mapToken?.trim(),
    );
    if (!canRequestRoute) return '—';

    return /failed|unavailable|rejected|no drivable route|did not include/i.test(String(status || ''))
        ? 'Retrying…'
        : 'Calculating…';
}

function updateNavigationPanel(shell, { rider = null, target = null, route = null, status = null } = {}) {
    const panel = shell.querySelector('[data-map-navigation-panel]');
    if (!panel) return;

    shell._likhaeNavigationSnapshot = { rider, target, route, status };
    panel.hidden = false;
    const destination = navigationDestination(target);
    const buyerPinMissing = rider?.active_destination_kind === 'buyer' && !markerCoordinates(target);
    const assignmentLabel = rider?.assignment_type === 'INTER_LOGISTICS'
        ? 'Inter-hub navigation'
        : rider?.assignment_type === 'PICKUP' ? 'Pickup navigation' : 'Delivery navigation';
    const setText = (selector, value) => {
        const element = panel.querySelector(selector);
        if (element) element.textContent = value;
    };

    setText('[data-map-navigation-assignment]', rider
        ? assignmentLabel
        : shell.dataset.mapRiderGps === 'true' && target
            ? shell.dataset.mapUserTarget === 'seller'
                ? 'Pickup navigation'
                : shell.dataset.mapUserTarget === 'logistics'
                    ? 'Logistics navigation'
                    : 'Navigation preview'
            : 'Waiting for active Rider GPS');
    setText('[data-map-navigation-destination]', buyerPinMissing ? 'Delivery pin required' : (destination.name || 'Active destination'));
    setText('[data-map-navigation-address]', buyerPinMissing
        ? 'The Buyer has not confirmed an exact destination pin for this order.'
        : (destination.address || 'Saved destination coordinates'));
    const remainingDuration = route
        ? (Number.isFinite(route.arrivalAt)
            ? Math.max(0, (route.arrivalAt - Date.now()) / 1000)
            : route.duration)
        : null;
    const metricPlaceholder = route ? null : navigationMetricPlaceholder(shell, rider, target, status);
    setText('[data-map-navigation-eta]', route ? formatRouteDuration(remainingDuration) : metricPlaceholder);
    setText('[data-map-navigation-distance]', route ? formatRoadDistance(route.distance) : metricPlaceholder);
    setText('[data-map-navigation-arrival]', route ? formatArrivalTime(route.duration, route.arrivalAt) : metricPlaceholder);
    setText('[data-map-navigation-status]', status || (rider
        ? rider.location_fresh === true ? 'Live GPS position available' : 'Last GPS update is delayed. Navigation is using the last known location.'
        : 'Waiting for live GPS'));
}

function removeRouteLayer(map, routeId) {
    const layerId = `likhae-route-layer-${routeId}`;
    const sourceId = `likhae-route-source-${routeId}`;
    if (map.getLayer(layerId)) map.removeLayer(layerId);
    if (map.getSource(sourceId)) map.removeSource(sourceId);
}

async function drawPersistedRoutes(map, markers, shell, token) {
    const grouped = markers.reduce((groups, marker) => {
        if (marker.shipment_id) {
            groups[marker.shipment_id] ||= [];
            groups[marker.shipment_id].push(marker);
        }
        return groups;
    }, {});
    shell._likhaeRouteState ||= {};
    const activeRouteIds = new Set();
    const navigationEnabled = shell.dataset.mapNavigation === 'true';
    const activeRider = markers.find((marker) => marker.kind === 'rider' && marker.active_rider);
    // A route is still useful when the latest server position is older than the
    // live threshold.  Keep the pin honestly labelled as "last known", but do
    // not suppress the road line while the next device fix is being requested.
    const navigationRider = activeRider || null;
    const activeDestinationKind = activeRider?.active_destination_kind;
    const activeNavigationTarget = markers.find((marker) => marker.target_kind === activeDestinationKind)
        || markers.find((marker) => marker.target_kind === shell.dataset.mapUserTarget);
    const previousNavigation = shell._likhaeNavigationSnapshot;
    const sameNavigationTarget = previousNavigation?.target?.id
        && previousNavigation.target.id === activeNavigationTarget?.id
        && previousNavigation.rider?.assignment_id === activeRider?.assignment_id;

    if (navigationEnabled) {
        updateNavigationPanel(shell, {
            rider: activeRider,
            target: activeNavigationTarget,
            route: sameNavigationTarget && navigationRider ? previousNavigation.route : null,
            status: !activeRider
                ? activeNavigationTarget && shell.dataset.mapRiderGps === 'true'
                    ? 'Refresh rider GPS to preview the route from this device.'
                    : 'Waiting for the active assignment.'
                : !markerCoordinates(activeNavigationTarget)
                    ? activeDestinationKind === 'seller'
                        ? 'Seller pickup coordinates are missing. Ask the seller to confirm the saved address pin.'
                    : activeDestinationKind === 'buyer'
                        ? 'DELIVERY PIN REQUIRED — The Buyer has not confirmed an exact destination pin for this order. Navigation will become available once the Buyer confirms the delivery pin.'
                        : 'Saved destination coordinates are unavailable.'
                    : !navigationRider
                        ? 'Waiting for the Rider location.'
                        : activeRider.location_fresh !== true
                            ? 'Last GPS update is delayed. Navigation is using the last known location. Refresh GPS for a current route.'
                        : sameNavigationTarget && previousNavigation.route
                            ? 'Road route active'
                            : 'Calculating driving route…',
        });

        const riderCoordinates = markerCoordinates(navigationRider);
        const destinationCoordinates = markerCoordinates(activeNavigationTarget);
        const fitKey = `${activeRider?.assignment_id || ''}:${activeNavigationTarget?.id || ''}`;
        if (riderCoordinates && destinationCoordinates && shell._likhaeNavigationFitKey !== fitKey) {
            const west = Math.min(riderCoordinates[0], destinationCoordinates[0]);
            const east = Math.max(riderCoordinates[0], destinationCoordinates[0]);
            const south = Math.min(riderCoordinates[1], destinationCoordinates[1]);
            const north = Math.max(riderCoordinates[1], destinationCoordinates[1]);
            map.fitBounds([[west, south], [east, north]], { padding: 64, maxZoom: 15, duration: 350 });
            shell._likhaeNavigationFitKey = fitKey;
        }
    }

    Object.entries(grouped).forEach(([shipmentId, shipmentMarkers]) => {
        const riders = shipmentMarkers.filter((marker) => marker.kind === 'rider'
            && marker.active_rider
            );
        const targets = shipmentMarkers.filter((marker) => ['seller', 'center', 'destination'].includes(marker.kind));

        riders.forEach((rider) => {
            const from = markerCoordinates(rider);
            if (!from) return;

            const destinationKind = rider.active_destination_kind;
            const target = targets.find((candidate) => candidate.target_kind === destinationKind);
            const to = markerCoordinates(target);
            if (!to) return;

            const routeId = `${shipmentId}-${rider.assignment_id || 'rider'}-${destinationKind}`;
            activeRouteIds.add(routeId);
            if (!token) {
                removeRouteLayer(map, routeId);
                delete shell._likhaeRouteState[routeId];
                if (navigationEnabled && rider.active_rider) {
                    updateNavigationPanel(shell, {
                        rider,
                        target,
                        status: 'Live rider location is shown. Driving route previews need a Mapbox public token.',
                    });
                }
                return;
            }
            const previous = shell._likhaeRouteState?.[routeId];
            const sameDestination = previous && previous.to[0] === to[0] && previous.to[1] === to[1];
            const pending = previous?.pending === true;
            const routeFresh = previous?.fetchedAt && Date.now() - previous.fetchedAt < ROUTE_MAX_AGE_MS;
            const routeOnPath = previous?.geometry
                ? distanceToRouteMeters(from, previous.geometry) <= ROUTE_OFF_PATH_DISTANCE_METERS
                : false;
            if (previous && sameDestination && (pending
                || (routeFresh && distanceMeters(previous.from, from) < ROUTE_RECALCULATION_DISTANCE_METERS && routeOnPath)
                || (previous.failedAt && Date.now() - previous.failedAt < ROUTE_RETRY_DELAY_MS))) return;

            removeRouteLayer(map, routeId);
            const routeState = { from, to, pending: true };
            shell._likhaeRouteState[routeId] = routeState;
            if (navigationEnabled && rider.active_rider) {
                updateNavigationPanel(shell, {
                    rider,
                    target,
                    status: 'Calculating driving route…',
                });
            }

            fetchRoadGeometry(token, from, to)
                .then((route) => {
                    if (shell._likhaeRouteState?.[routeId] !== routeState) return;
                    routeState.pending = false;
                    routeState.geometry = route.geometry;
                    routeState.fetchedAt = Date.now();
                    routeState.route = {
                        ...route,
                        arrivalAt: Date.now() + (route.duration * 1000),
                    };
                    const isOrderRoute = shell.dataset.mapOrderRoute === 'true';
                    drawRouteGeometry(map, routeId, route.geometry, isOrderRoute ? '#9ca3af' : '#800000', isOrderRoute);
                    if (navigationEnabled && rider.active_rider) {
                        updateNavigationPanel(shell, {
                            rider,
                            target,
                            route: routeState.route,
                            status: rider.location_fresh === true
                                ? 'Road route updated from live GPS'
                                : 'Last GPS update is delayed. Navigation is using the last known location. Refresh GPS for a current route.',
                        });
                    }
                })
                .catch((error) => {
                    if (shell._likhaeRouteState?.[routeId] !== routeState) return;
                    routeState.pending = false;
                    routeState.failedAt = Date.now();
                    if (navigationEnabled && rider.active_rider) {
                        updateNavigationPanel(shell, {
                            rider,
                            target,
                            status: error?.message || 'Driving route unavailable. Retrying shortly.',
                        });
                    }
                    updateStatus(shell, error?.message || 'Live rider location is available, but the road route is temporarily unavailable.', true);
                });
        });
    });

    Object.keys(shell._likhaeRouteState)
        .filter((routeId) => !activeRouteIds.has(routeId))
        .forEach((routeId) => {
            removeRouteLayer(map, routeId);
            delete shell._likhaeRouteState[routeId];
        });
}

async function drawUserRoute(shell, map, mapboxgl, markers, from) {
    // Before a pickup assignment is accepted, preview the route on the Rider's
    // own device. This coordinate is not sent to the shipment tracking API.
    if (shell.dataset.mapRiderGps !== 'true') return null;

    const target = markers.find((marker) => marker.target_kind === shell.dataset.mapUserTarget);
    const to = markerCoordinates(target);
    if (!target || !to) return null;
    if (!shell.dataset.mapToken?.trim()) {
        const message = 'Your location is shown on the map. Driving route previews need a Mapbox public token.';
        updateNavigationPanel(shell, { target, status: message });
        updateStatus(shell, message, true);
        return false;
    }

    const routeId = `device-preview-${shell.dataset.mapUserTarget || 'pickup'}`;
    const localRider = {
        assignment_type: 'PICKUP',
        active_destination_kind: shell.dataset.mapUserTarget,
        location_fresh: true,
    };

    try {
        const route = await fetchRoadGeometry(shell.dataset.mapToken, from, to);
        drawRouteGeometry(map, routeId, route.geometry, '#800000');
        const bounds = new mapboxgl.LngLatBounds();
        bounds.extend(from);
        bounds.extend(to);
        map.fitBounds(bounds, { padding: 64, maxZoom: 15, duration: 350 });
        updateNavigationPanel(shell, {
            rider: localRider,
            target,
            route: { ...route, arrivalAt: Date.now() + (route.duration * 1000) },
            status: 'Route preview only. No location update was sent to LIKHAE tracking.',
        });
        return true;
    } catch (error) {
        updateNavigationPanel(shell, {
            rider: localRider,
            target,
            status: error?.message || 'Driving route unavailable. Try refreshing GPS again.',
        });
        updateStatus(shell, error?.message || 'Your location is shown on this device, but the road route is unavailable.', true);
        return false;
    }
}

function addUserLocation(shell, map, mapboxgl, markers) {
    if (shell.dataset.mapLiveDeviceGps === 'true') return;
    const button = shell.querySelector('[data-map-location]');
    if (!button) return;

    const syncRiderGpsButton = () => {
        if (!window.LikhaeRiderLocationTracker?.refresh) return;
        button.textContent = 'Refresh rider GPS current device';
        button.dataset.mapRiderGps = 'true';
    };
    syncRiderGpsButton();
    window.addEventListener('likhae:rider-location-status', syncRiderGpsButton);

    button.addEventListener('click', async () => {
        // On an active Rider page this is intentionally not a local map-only
        // pin. It requests a fresh, high-accuracy GPS fix that is sent to the
        // existing protected Rider endpoint and can create navigation.
        if (window.LikhaeRiderLocationTracker?.refresh) {
            window.LikhaeRiderLocationTracker.refresh();
            updateStatus(shell, 'Requesting a fresh Rider device GPS fix…');
            return;
        }

        if (!navigator.geolocation) {
            updateStatus(shell, 'Location is not supported by this browser.', true);
            return;
        }

        button.disabled = true;
        updateStatus(shell, 'Waiting for your location permission…');
        navigator.geolocation.getCurrentPosition(async (position) => {
            const lng = position.coords.longitude;
            const lat = position.coords.latitude;
            shell._likhaeUserMarker?.remove();
            const marker = new mapboxgl.Marker({ element: Object.assign(document.createElement('div'), {
                className: 'lk-map-marker',
            }) }).setLngLat([lng, lat]).addTo(map);
            marker.getElement().dataset.kind = shell.dataset.mapRiderGps === 'true' ? 'rider' : 'user';
            marker.getElement().dataset.mapUserMarker = 'true';
            shell._likhaeUserMarker = marker;
            map.flyTo({ center: [lng, lat], zoom: Math.max(map.getZoom(), 14), essential: true });
            const hasRoute = await drawUserRoute(shell, map, mapboxgl, markers, [lng, lat]);
            if (hasRoute === true) {
                updateStatus(shell, 'Road route preview is ready. This GPS fix was not sent to LIKHAE tracking.');
            } else if (hasRoute === null) {
                updateStatus(shell, 'Your location is shown on this device only.');
            }
            button.disabled = false;
        }, (error) => {
            const message = error.code === 1
                ? 'Location permission was not granted. You can enable it in browser settings.'
                : 'We could not read your location right now. Try again.';
            updateStatus(shell, message, true);
            button.disabled = false;
        }, { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 });
    });
}

function startLiveDeviceNavigation(shell, map, mapboxgl, markers) {
    if (shell.dataset.mapLiveDeviceGps !== 'true') return;

    const button = shell.querySelector('[data-map-location]');
    const target = markers.find((marker) => marker.target_kind === shell.dataset.mapUserTarget);
    const destination = markerCoordinates(target);
    const token = shell.dataset.mapToken?.trim() || '';
    const rider = { assignment_type: 'INTER_LOGISTICS', active_destination_kind: 'destination', location_fresh: true };
    let watchId;
    let lastRouteFrom = null;
    let lastRouteAt = 0;
    let routeRequest = 0;
    let activeRoute = null;
    let followDriver = true;

    const setButton = (active) => {
        if (!button) return;
        button.textContent = active ? 'Pause live GPS' : 'Resume live GPS';
        button.setAttribute('aria-pressed', String(active));
    };

    const updateFromPosition = (position) => {
        const latitude = Number(position.coords?.latitude);
        const longitude = Number(position.coords?.longitude);
        const accuracy = Number(position.coords?.accuracy);
        if (!Number.isFinite(latitude) || !Number.isFinite(longitude)
            || latitude < -90 || latitude > 90 || longitude < -180 || longitude > 180) {
            updateStatus(shell, 'Device GPS returned an invalid location. Waiting for a fresh reading.', true);
            return;
        }
        if (Number.isFinite(accuracy) && accuracy > 250) {
            updateStatus(shell, `GPS accuracy is low (${Math.round(accuracy)} m). Waiting for a clearer location fix.`, true);
            return;
        }

        const from = [longitude, latitude];
        if (!shell._likhaeUserMarker) {
            const element = document.createElement('div');
            element.className = 'lk-map-marker';
            element.dataset.kind = 'rider';
            element.setAttribute('aria-label', 'Driver live location');
            shell._likhaeUserMarker = new mapboxgl.Marker({ element, anchor: 'bottom' })
                .setPopup(new mapboxgl.Popup({ offset: 16 }).setHTML('<div class="lk-map-popup"><strong>Driver live location</strong><span>Updated from this device GPS</span></div>'))
                .setLngLat(from)
                .addTo(map);
        } else {
            shell._likhaeUserMarker.setLngLat(from);
        }

        if (followDriver) {
            const bounds = new mapboxgl.LngLatBounds();
            bounds.extend(from);
            if (destination) bounds.extend(destination);
            map.fitBounds(bounds, { padding: 64, maxZoom: 13, duration: 350 });
        }
        const locationState = shell.querySelector('[data-map-location-state]');
        if (locationState) locationState.textContent = 'LIVE DRIVER GPS';
        if (!destination) {
            updateNavigationPanel(shell, { rider, target, status: 'Destination hub map coordinates are missing.' });
            updateStatus(shell, 'Your live location is shown. The destination hub needs a saved map pin.', true);
            return;
        }

        if (!token) {
            updateNavigationPanel(shell, { rider, target, status: 'Live GPS is active. Add a Mapbox public token to show the driving route and ETA.' });
            updateStatus(shell, 'Live driver location and destination are shown. Driving route preview needs a Mapbox public token.');
            return;
        }

        const now = Date.now();
        const movedEnough = !lastRouteFrom || distanceMeters(lastRouteFrom, from) >= 500;
        const routeIsOld = now - lastRouteAt >= 90000;
        if (movedEnough || routeIsOld) {
            lastRouteFrom = from;
            lastRouteAt = now;
            const requestId = ++routeRequest;
            updateNavigationPanel(shell, { rider, target, route: activeRoute, status: 'Live GPS active. Updating the road route…' });
            fetchRoadGeometry(token, from, destination)
                .then((route) => {
                    if (requestId !== routeRequest) return;
                    activeRoute = { ...route, arrivalAt: Date.now() + (route.duration * 1000) };
                    drawRouteGeometry(map, 'inter-hub-live', route.geometry, '#800000');
                    updateNavigationPanel(shell, { rider, target, route: activeRoute, status: 'Live driver GPS · road route updated' });
                    updateStatus(shell, 'Live driver location and destination are shown. Keep this page open while traveling.');
                })
                .catch((error) => {
                    if (requestId !== routeRequest) return;
                    updateNavigationPanel(shell, { rider, target, route: activeRoute, status: error?.message || 'Driving route is temporarily unavailable.' });
                    updateStatus(shell, 'Live driver location is shown; the driving route could not be refreshed.', true);
                });
        } else {
            updateNavigationPanel(shell, { rider, target, route: activeRoute, status: 'Live driver GPS · route refreshes as you travel' });
            updateStatus(shell, 'Live driver location and destination are shown. Keep this page open while traveling.');
        }
    };

    const stop = (message = 'Live GPS paused. The destination hub remains visible.') => {
        if (watchId !== undefined && navigator.geolocation) navigator.geolocation.clearWatch(watchId);
        watchId = undefined;
        setButton(false);
        const locationState = shell.querySelector('[data-map-location-state]');
        if (locationState) locationState.textContent = 'GPS PAUSED';
        if (message) {
            updateNavigationPanel(shell, { rider, target, route: activeRoute, status: 'Live GPS paused' });
            updateStatus(shell, message);
        }
    };

    const start = () => {
        if (watchId !== undefined) return;
        if (!navigator.geolocation) {
            updateNavigationPanel(shell, { rider, target, status: 'This browser does not support live device GPS.' });
            updateStatus(shell, 'This browser does not support live device GPS.', true);
            return;
        }
        if (!destination) {
            updateNavigationPanel(shell, { rider, target, status: 'Destination hub map coordinates are missing.' });
        }
        followDriver = true;
        updateStatus(shell, 'Requesting device location permission for live inter-hub navigation.');
        setButton(true);
        watchId = navigator.geolocation.watchPosition(updateFromPosition, (error) => {
            const message = error.code === 1
                ? 'Location access was denied. Enable browser location permission to show your live position.'
                : 'Device location is temporarily unavailable. Waiting for another GPS reading.';
            updateNavigationPanel(shell, { rider, target, route: activeRoute, status: message });
            updateStatus(shell, message, true);
            if (error.code === 1) stop(null);
        }, { enableHighAccuracy: true, maximumAge: 0, timeout: 15000 });
    };

    map.on('dragstart', () => { followDriver = false; });
    button?.addEventListener('click', () => watchId === undefined ? start() : stop());
    window.addEventListener('pagehide', () => stop(null), { once: true });
    start();
}

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

function renderMarkers(shell, map, mapboxgl, markers, fit = false) {
    shell._likhaeMarkerRefs ||= {};
    const seen = new Set();
    const bounds = new mapboxgl.LngLatBounds();

    markers.forEach((markerData) => {
        const coordinates = markerCoordinates(markerData);
        if (!coordinates) return;

        const normalizedTitle = String(markerData.title || markerData.label || '').trim().toLowerCase().replace(/\s+/g, ' ');
        const normalizedAddress = String(markerData.popup || '').trim().toLowerCase().replace(/\s+/g, ' ');
        let key = String(markerData.id || `${markerData.kind}-${markerData.latitude}-${markerData.longitude}`);
        if (markerData.kind === 'center') {
            const centerId = key.replace(/-shipment-\d+$/, '');
            key = centerId !== String(markerData.id || '')
                ? centerId
                : `center-${normalizedAddress || `${coordinates[0].toFixed(4)}-${coordinates[1].toFixed(4)}`}`;
        } else if (markerData.kind === 'destination') {
            key = `destination-${normalizedTitle}-${normalizedAddress}`;
        } else if (markerData.kind === 'seller') {
            key = `seller-${coordinates[0].toFixed(4)}-${coordinates[1].toFixed(4)}`;
        }
        seen.add(key);
        let marker = shell._likhaeMarkerRefs[key];
        if (!marker) {
            const element = document.createElement('div');
            element.className = 'lk-map-marker';
            marker = new mapboxgl.Marker({ element }).setLngLat(coordinates).addTo(map);
            shell._likhaeMarkerRefs[key] = marker;
        } else {
            marker.setLngLat(coordinates);
        }

        marker.getElement().dataset.kind = markerData.kind || 'destination';
        marker.getElement().dataset.activeRider = markerData.active_rider ? 'true' : 'false';
        marker.setPopup(new mapboxgl.Popup({ offset: 16 }).setHTML(
            `<div class="lk-map-popup"><strong>${escapeHtml(markerData.title || markerData.label || 'Location')}</strong><span>${escapeHtml(markerData.popup || '')}</span></div>`,
        ));
        bounds.extend(coordinates);
    });

    Object.entries(shell._likhaeMarkerRefs).forEach(([key, marker]) => {
        if (!seen.has(key)) {
            marker.remove();
            delete shell._likhaeMarkerRefs[key];
        }
    });

    const activeRider = markers.find((marker) => marker.kind === 'rider' && marker.active_rider);
    const locationState = shell.querySelector('[data-map-location-state]');
    if (locationState) {
        locationState.textContent = activeRider && markerCoordinates(activeRider)
            ? activeRider.location_fresh === true ? 'LIVE' : 'LAST KNOWN LOCATION'
            : activeRider ? 'WAITING FOR RIDER GPS' : 'LOCATION MAP';
    }

    if (fit && !bounds.isEmpty()) {
        map.fitBounds(bounds, { padding: 54, maxZoom: 14, duration: 0 });
    }
}

async function refreshTracking(shell, map, mapboxgl, payload) {
    const markers = Array.isArray(payload?.markers) ? payload.markers : [];
    const endpoints = markers.map((marker) => marker.tracking_endpoint).filter(Boolean);
    const channels = markers.map((marker) => marker.tracking_channel).filter(Boolean);
    if (endpoints.length) shell._likhaeTrackingEndpoints = [...new Set(endpoints)];
    if (channels.length) shell._likhaeTrackingChannels = [...new Set(channels)];
    shell._likhaeCurrentMarkers = markers;
    renderMarkers(shell, map, mapboxgl, markers);
    drawOrderRoutes(map, mapboxgl, markers, shell);
    configureRiderLocationUpdates(shell);
    await drawPersistedRoutes(map, markers, shell, shell.dataset.mapToken?.trim() || '');

    const tracking = payload?.tracking || {};
    shell._likhaeCurrentTracking = tracking;
    const fitKey = `${tracking.status || ''}:${tracking.active_assignment_id || ''}:${tracking.active_destination_kind || ''}`;
    if (tracking.live && shell._likhaeFitKey !== fitKey) {
        const rider = markers.find((marker) => marker.kind === 'rider' && marker.active_rider);
        const target = markers.find((marker) => marker.target_kind === tracking.active_destination_kind);
        const riderCoordinates = markerCoordinates(rider);
        const targetCoordinates = markerCoordinates(target);
        if (riderCoordinates && targetCoordinates) {
            const bounds = new mapboxgl.LngLatBounds();
            bounds.extend(riderCoordinates);
            bounds.extend(targetCoordinates);
            map.fitBounds(bounds, { padding: 64, maxZoom: 14, duration: 450 });
        }
        shell._likhaeFitKey = fitKey;
    }
    if (tracking.live) {
        updateStatus(shell, `Rider live location updated · ${tracking.status || 'Active shipment'}.`);
    } else if (tracking.location_state === 'stale') {
        const lastSeen = tracking.last_location?.recorded_at
            ? new Intl.DateTimeFormat('en-PH', {
                timeZone: 'Asia/Manila',
                year: 'numeric',
                month: 'short',
                day: 'numeric',
                hour: 'numeric',
                minute: '2-digit',
                second: '2-digit',
                timeZoneName: 'short',
            }).format(new Date(tracking.last_location.recorded_at)).replace(/GMT\+8$/, 'PHT')
            : 'an earlier time';
        updateStatus(shell, `Showing the Rider’s last known location from ${lastSeen}. The maroon road route is shown; refresh GPS to update it.`, false);
    } else if (['READY_FOR_PICKUP', 'PICKED_UP', 'ASSIGNED_TO_RIDER', 'OUT_FOR_DELIVERY'].includes(tracking.status)) {
        updateStatus(shell, `Waiting for the active rider location · ${tracking.status.replaceAll('_', ' ')}.`);
    } else if (markers.length) {
        updateStatus(shell, `${markers.length} saved location${markers.length === 1 ? '' : 's'} shown. Live movement starts when the active delivery phase begins.`);
    } else {
        updateStatus(shell, 'No saved coordinates yet.');
    }
}

function configureRiderLocationUpdates(shell) {
    const button = shell.querySelector('[data-map-rider-sharing]');
    if (!button) return;

    const sync = () => {
        const tracker = window.LikhaeRiderLocationTracker;
        button.hidden = !tracker;
        if (tracker) button.setAttribute('aria-pressed', String(tracker.isRunning()));
    };
    sync();
    const tracker = window.LikhaeRiderLocationTracker;
    if (tracker?.refresh && shell.dataset.deviceGpsRefreshRequested !== 'true') {
        shell.dataset.deviceGpsRefreshRequested = 'true';
        window.setTimeout(() => tracker.refresh(), 0);
    }
    if (!button.dataset.locationStatusBound) {
        button.dataset.locationStatusBound = 'true';
        window.addEventListener('likhae:rider-location-status', sync);
    }
}

function validLiveEventCoordinates(event) {
    const latitude = Number(event?.latitude);
    const longitude = Number(event?.longitude);
    if (!Number.isFinite(latitude) || !Number.isFinite(longitude)
        || latitude < -90 || latitude > 90 || longitude < -180 || longitude > 180) {
        return null;
    }

    const recordedAt = event?.recorded_at ? Date.parse(event.recorded_at) : Date.now();
    if (!Number.isFinite(recordedAt) || recordedAt > Date.now() + 30000) return null;

    return { latitude, longitude, recordedAt };
}

async function applyRiderLocationEvent(shell, map, mapboxgl, event) {
    const shipmentId = String(event?.shipment_id || '');
    const assignmentId = String(event?.assignment_id || '');
    if (!shipmentId || !assignmentId) return false;

    const marker = (shell._likhaeCurrentMarkers || []).find((candidate) =>
        String(candidate.shipment_id || '') === shipmentId
        && String(candidate.assignment_id || '') === assignmentId
        && candidate.kind === 'rider');
    if (!marker) return false;

    const coordinates = validLiveEventCoordinates(event);
    if (!coordinates) return false;

    const previousRecordedAt = marker.location_recorded_at ? Date.parse(marker.location_recorded_at) : 0;
    if (Number.isFinite(previousRecordedAt) && coordinates.recordedAt < previousRecordedAt) return false;

    const fresh = Date.now() - coordinates.recordedAt <= 120000;
    marker.latitude = coordinates.latitude;
    marker.longitude = coordinates.longitude;
    marker.location_recorded_at = new Date(coordinates.recordedAt).toISOString();
    marker.location_fresh = fresh;
    marker.location_state = fresh ? 'live' : 'stale';
    marker.title = fresh ? 'Current rider location' : 'Last known rider location';
    marker.popup = fresh ? 'Updated just now' : 'Last known rider location';

    const tracking = shell._likhaeCurrentTracking || {};
    if (String(tracking.shipment_id || '') === shipmentId
        && String(tracking.active_assignment_id || '') === assignmentId) {
        shell._likhaeCurrentTracking = {
            ...tracking,
            live: fresh,
            location_state: fresh ? 'live' : 'stale',
            last_location: {
                latitude: coordinates.latitude,
                longitude: coordinates.longitude,
                recorded_at: marker.location_recorded_at,
            },
        };
    }

    renderMarkers(shell, map, mapboxgl, shell._likhaeCurrentMarkers || []);
    drawOrderRoutes(map, mapboxgl, shell._likhaeCurrentMarkers || [], shell);
    await drawPersistedRoutes(map, shell._likhaeCurrentMarkers || [], shell, shell.dataset.mapToken?.trim() || '');
    updateStatus(shell, fresh
        ? event?.local_device_gps
            ? 'Road route updated from fresh Rider device GPS. Saving live location…'
            : 'Rider live location updated from the active assignment.'
        : 'Last GPS update is delayed. Navigation is using this Rider’s last known location; refresh GPS to update it.', false);
    return true;
}

function bindDeviceRiderLocation(shell, map, mapboxgl) {
    if (shell._likhaeSavedLocationBound) return;
    shell._likhaeSavedLocationBound = true;

    const applyDeviceFix = ({ detail }, source) => {
        const assignmentId = String(detail?.assignmentId || '');
        const marker = (shell._likhaeCurrentMarkers || []).find((candidate) =>
            String(candidate.assignment_id || '') === assignmentId
            && candidate.kind === 'rider');
        if (!marker) return;

        // This event is emitted only after the existing protected endpoint
        // returns success. It is therefore real, accepted Rider device GPS,
        // never the local-only "Use my location" marker.
        applyRiderLocationEvent(shell, map, mapboxgl, {
            shipment_id: marker.shipment_id,
            assignment_id: assignmentId,
            latitude: detail.latitude,
            longitude: detail.longitude,
            recorded_at: detail.recorded_at,
            local_device_gps: source === 'device',
        });
    };

    // Only the tracker’s fresh, validated watchPosition coordinate may create
    // an immediate route. The local-only map helper never emits this event.
    window.addEventListener('likhae:rider-location-read', (event) => applyDeviceFix(event, 'device'));
    window.addEventListener('likhae:rider-location-saved', (event) => applyDeviceFix(event, 'saved'));
}

async function pollTracking(shell, map, mapboxgl, { fallback = false } = {}) {
    const endpoints = shell._likhaeTrackingEndpoints || [];
    if (!endpoints.length) return;
    if (shell._likhaeTrackingPollPromise) return shell._likhaeTrackingPollPromise;

    const request = (async () => {
        try {
            const payloads = await Promise.all(endpoints.map(async (endpoint) => {
                const response = await fetch(endpoint, {
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                return response.ok ? response.json() : null;
            }));
            const valid = payloads.filter(Boolean);
            if (!valid.length) return;
            const mergedMarkers = valid.flatMap((payload) => payload.markers || []);
            await refreshTracking(shell, map, mapboxgl, {
                markers: [...new Map(mergedMarkers.map((marker) => [marker.id, marker])).values()],
                tracking: valid[0].tracking || {},
            });
        } catch {
            if (fallback) {
                shell._likhaeRealtimeNotice = 'Realtime updates unavailable; polling fallback is active.';
                shell._likhaeRealtimeNoticeError = true;
                updateStatus(shell, 'The live tracking check could not reach the server.');
            }
        }
    })();

    shell._likhaeTrackingPollPromise = request;
    request.finally(() => {
        if (shell._likhaeTrackingPollPromise === request) shell._likhaeTrackingPollPromise = null;
    });
    return request;
}

function setRealtimeConnectionState(shell, state) {
    shell._likhaeRealtimeConnectionState = state;
    const connected = state === 'connected';
    shell._likhaeRealtimeNotice = connected ? '' : 'Realtime updates unavailable; polling fallback is active.';
    shell._likhaeRealtimeNoticeError = !connected;
    updateStatus(shell, connected
        ? shell.dataset.mapOrderRoute === 'true'
            ? 'Realtime tracking connected; route checks run every 10 seconds.'
            : 'Realtime tracking connected; waiting for the next location update.'
        : 'Realtime tracking is reconnecting; polling fallback is active.', !connected);
}

function syncPollingFallback(shell, map, mapboxgl) {
    const connected = shell._likhaeRealtimeConnectionState === 'connected';
    const orderRoute = shell.dataset.mapOrderRoute === 'true';
    if (connected && !orderRoute) {
        if (shell._likhaeTrackingTimer) window.clearInterval(shell._likhaeTrackingTimer);
        shell._likhaeTrackingTimer = null;
        shell._likhaeTrackingTimerIntervalMs = null;
        shell._likhaeTrackingTimerMode = null;
        return;
    }

    const intervalMs = orderRoute ? ORDER_ROUTE_POLL_INTERVAL_MS : 5000;
    const mode = connected ? 'connected' : 'fallback';
    if (shell._likhaeTrackingTimer
        && shell._likhaeTrackingTimerIntervalMs === intervalMs
        && shell._likhaeTrackingTimerMode === mode) return;

    if (shell._likhaeTrackingTimer) window.clearInterval(shell._likhaeTrackingTimer);
    pollTracking(shell, map, mapboxgl, { fallback: !connected });
    shell._likhaeTrackingTimer = window.setInterval(
        () => pollTracking(shell, map, mapboxgl, { fallback: !connected }),
        intervalMs,
    );
    shell._likhaeTrackingTimerIntervalMs = intervalMs;
    shell._likhaeTrackingTimerMode = mode;
}

function leaveEchoChannels(shell) {
    const channels = shell._likhaeTrackingChannels || [];
    channels.forEach((channel) => {
        try { window.Echo?.leave(channel); } catch { /* The fallback poll remains active. */ }
    });
    shell._likhaeEchoChannels = null;
}

function connectLiveTracking(shell, map, mapboxgl) {
    if (shell.dataset.mapLive !== 'true' || !shell._likhaeTrackingChannels?.length) return;

    const subscribe = () => {
        if (!window.Echo || shell._likhaeEchoChannels) {
            if (!window.Echo) setRealtimeConnectionState(shell, 'unavailable');
            return;
        }

        try {
            shell._likhaeEchoChannels = shell._likhaeTrackingChannels.map((channel) => window.Echo.private(channel)
                .listen('.rider.location.updated', (event) => {
                    shell._likhaeRealtimeNotice = '';
                    shell._likhaeRealtimeNoticeError = false;
                    applyRiderLocationEvent(shell, map, mapboxgl, event)
                        .finally(() => {
                            if (shell._likhaeRealtimeConnectionState !== 'connected') {
                                pollTracking(shell, map, mapboxgl, { fallback: true });
                            }
                        });
                })
                .listen('.shipment.tracking.updated', () => {
                    shell._likhaeRealtimeNotice = '';
                    shell._likhaeRealtimeNoticeError = false;
                    pollTracking(shell, map, mapboxgl);
                }));
            const connectionState = window.Echo?.connector?.pusher?.connection?.state;
            setRealtimeConnectionState(shell, connectionState === 'connected' ? 'connected' : 'connecting');
            syncPollingFallback(shell, map, mapboxgl);
        } catch {
            leaveEchoChannels(shell);
            setRealtimeConnectionState(shell, 'unavailable');
            syncPollingFallback(shell, map, mapboxgl);
        }
    };

    const bindConnection = () => {
        const connection = window.Echo?.connector?.pusher?.connection;
        if (!connection || shell._likhaeEchoConnection === connection) return;

        shell._likhaeEchoConnection = connection;
        connection.bind('state_change', (states) => {
            if (states.current === 'connected') {
                shell._likhaeRealtimeNotice = '';
                shell._likhaeRealtimeNoticeError = false;
                leaveEchoChannels(shell);
                subscribe();
                syncPollingFallback(shell, map, mapboxgl);
                return;
            }

            leaveEchoChannels(shell);
            setRealtimeConnectionState(shell, states.current || 'unavailable');
            syncPollingFallback(shell, map, mapboxgl);
        });
        setRealtimeConnectionState(shell, connection.state === 'connected' ? 'connected' : 'connecting');
        syncPollingFallback(shell, map, mapboxgl);
    };

    bindConnection();
    subscribe();
    shell._likhaeEchoRetryTimer = window.setInterval(() => {
        if (!window.Echo) {
            setRealtimeConnectionState(shell, 'unavailable');
            syncPollingFallback(shell, map, mapboxgl);
            return;
        }
        bindConnection();
        subscribe();
    }, 2000);
    syncPollingFallback(shell, map, mapboxgl);
}

function initMap(shell, mapboxgl, { fallback = false } = {}) {
    const token = shell.dataset.mapToken;
    const canvas = shell.querySelector('[data-map-canvas]');
    if (!canvas) {
        updateStatus(shell, 'The map canvas is missing from this page.', true);
        return;
    }

    const markers = parseJson(shell.dataset.mapMarkers || '[]', []);
    const center = parseJson(shell.dataset.mapCenter || '[121,14.6]', [121, 14.6]);
    const zoom = Number(shell.dataset.mapZoom || 10);
    shell.dataset.mapFallback = fallback ? 'true' : 'false';
    if (token) mapboxgl.accessToken = token;

    const map = new mapboxgl.Map({
        container: canvas,
        style: fallback ? OPENFREEMAP_STYLE : (shell.dataset.mapStyle || 'mapbox://styles/mapbox/streets-v12'),
        ...(shell.dataset.mapProjection ? { projection: shell.dataset.mapProjection } : {}),
        center,
        zoom,
        attributionControl: true,
    });
    let mapLoaded = false;
    map.on('error', (event) => {
        if (mapLoaded) return;
        const status = Number(event?.error?.status || event?.error?.statusCode);
        if (!fallback && (status === 401 || status === 403) && !shell._likhaeMapFallbackStarted) {
            shell._likhaeMapFallbackStarted = true;
            map.remove();
            loadMapLibre()
                .then((maplibregl) => initMap(shell, maplibregl, { fallback: true }))
                .catch(() => updateStatus(shell, 'Mapbox access was rejected and the open map service is unavailable. Check your connection or Mapbox token.', true));
            return;
        }
        updateStatus(shell, status === 401 || status === 403
            ? 'Map access was rejected. Check the Mapbox public token and its URL restrictions.'
            : 'Map tiles could not be loaded. Check the map provider connection and style URL.', true);
    });
    map.addControl(new mapboxgl.NavigationControl({ showCompass: true }), 'top-right');

    shell._likhaeCurrentMarkers = markers;
    shell._likhaeTrackingEndpoints = [...new Set(markers.map((marker) => marker.tracking_endpoint).filter(Boolean))];
    shell._likhaeTrackingChannels = [...new Set(markers.map((marker) => marker.tracking_channel).filter(Boolean))];
    renderMarkers(shell, map, mapboxgl, markers, true);

    shell.querySelector('[data-map-fullscreen]')?.addEventListener('click', () => toggleFullscreen(shell, map));
    addUserLocation(shell, map, mapboxgl, markers);
    map.on('load', () => {
        mapLoaded = true;
        const pinFormId = shell.dataset.mapPinForm;
        const pinForm = pinFormId ? document.getElementById(pinFormId) : null;
        if (pinForm) {
            const latitude = pinForm.querySelector('[name="latitude"]');
            const longitude = pinForm.querySelector('[name="longitude"]');
            const saveButton = pinForm.querySelector('[data-delivery-pin-save]');
            const pinStatus = pinForm.querySelector('[data-delivery-pin-status]');
            map.getCanvas().style.cursor = 'crosshair';
            map.on('click', (event) => {
                const lat = Number(event.lngLat.lat.toFixed(7));
                const lng = Number(event.lngLat.lng.toFixed(7));
                latitude.value = lat.toFixed(7);
                longitude.value = lng.toFixed(7);
                shell._likhaeSelectedPinMarker?.remove();
                const markerElement = document.createElement('div');
                markerElement.className = 'lk-map-marker';
                markerElement.dataset.kind = 'destination';
                markerElement.setAttribute('aria-label', 'Selected delivery pin');
                shell._likhaeSelectedPinMarker = new mapboxgl.Marker({ element: markerElement, anchor: 'bottom' })
                    .setLngLat([lng, lat])
                    .addTo(map);
                if (saveButton) saveButton.disabled = false;
                if (pinStatus) pinStatus.textContent = `Selected pin: ${lat.toFixed(7)}, ${lng.toFixed(7)}. Review the map, then confirm.`;
                updateStatus(shell, 'Exact pin selected. Review its position and confirm it below.');
            });
        }
        drawPersistedRoutes(map, markers, shell, token?.trim() || '');
        drawOrderRoutes(map, mapboxgl, markers, shell);
        configureRiderLocationUpdates(shell);
        bindDeviceRiderLocation(shell, map, mapboxgl);
        updateStatus(shell, fallback
            ? token?.trim()
                ? `${markers.length ? `${markers.length} saved location${markers.length === 1 ? '' : 's'} shown` : 'Map is ready'} on OpenFreeMap. Driving routes use Mapbox Directions.`
                : `${markers.length ? `${markers.length} saved location${markers.length === 1 ? '' : 's'} shown` : 'Map is ready'} on OpenFreeMap. Add a Mapbox public token to enable driving route previews.`
            : markers.length ? `${markers.length} location${markers.length === 1 ? '' : 's'} loaded.` : 'No saved coordinates yet.');
        connectLiveTracking(shell, map, mapboxgl);
        if (shell.dataset.mapNavigation === 'true') {
            shell._likhaeNavigationTimer = window.setInterval(() => {
                if (shell._likhaeNavigationSnapshot?.route) {
                    updateNavigationPanel(shell, shell._likhaeNavigationSnapshot);
                }
            }, 1000);
        }
        startLiveDeviceNavigation(shell, map, mapboxgl, markers);
    });
}

function bootMaps() {
    const shells = [...document.querySelectorAll('[data-likhae-map]')];
    if (!shells.length) return;

    shells.forEach((shell) => {
        const fallback = !shell.dataset.mapToken?.trim();
        const renderer = fallback ? loadMapLibre() : loadMapbox();
        renderer
            .then((mapboxgl) => initMap(shell, mapboxgl, { fallback }))
            .catch(() => {
                if (fallback) {
                    updateStatus(shell, 'The open map service is unavailable right now. Check your connection and reload the page.', true);
                    return;
                }
                loadMapLibre()
                    .then((maplibregl) => initMap(shell, maplibregl, { fallback: true }))
                    .catch(() => updateStatus(shell, 'Mapbox and the open map service could not be loaded. Check your connection and reload the page.', true));
            });
    });

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') return;
        document.querySelectorAll('[data-likhae-map].is-fullscreen').forEach((shell) => {
            shell.querySelector('[data-map-fullscreen]')?.click();
        });
    });
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', bootMaps);
else bootMaps();
