import * as mapRenderer from './mapbox';
import { fillAddressFromMapPin } from '../address/postal-code.js';

function updateStatus(shell, message, isError = false) {
    const status = shell.querySelector('[data-address-pin-status]');
    if (!status) return;
    status.textContent = message;
    status.classList.toggle('is-error', isError);
}

async function geoapifyErrorMessage(response) {
    let payload = null;
    try {
        payload = await response.json();
    } catch {
        // Geoapify and intermediary gateways may return a non-JSON error.
    }

    const rawDetail = payload?.message
        || (typeof payload?.error === 'string' ? payload.error : payload?.error?.message)
        || payload?.details
        || '';
    const detail = String(rawDetail)
        .replace(/(apiKey|api_key|access_token)\s*[=:]\s*[^&\s]+/gi, '$1=[redacted]')
        .replace(/\s+/g, ' ')
        .slice(0, 180);
    const suffix = detail ? ` ${detail}` : '';

    if (response.status === 401 || response.status === 403) {
        return `Geoapify denied address search (HTTP ${response.status}). Check the production API key and its allowed site referrer.${suffix}`;
    }
    if (response.status === 429) {
        return 'Geoapify rate limit reached. You can still select and confirm the pin manually.';
    }
    return `Geoapify address search failed (HTTP ${response.status}).${suffix}`;
}

function field(form, names) {
    for (const name of names) {
        const element = form?.querySelector(`[name="${name}"]`);
        if (element) return element;
    }
    return null;
}

function addressQueryCandidates(form) {
    const read = (names) => String(field(form, names)?.value ?? '').trim();
    const house = read(['house_number']);
    const street = read(['street_address', 'street']);
    const landmark = read(['landmark']);
    const barangay = read(['barangay_name', 'barangay']);
    const municipality = read(['municipality_name', 'municipality']);
    const province = read(['province_name', 'province']);
    const region = read(['region_name', 'region']);
    const postal = read(['postal_code']);
    const format = (values) => {
        const parts = values.map((value) => String(value ?? '').trim()).filter(Boolean);
        return parts.length ? [...parts, 'Philippines'].join(', ') : '';
    };

    return [...new Set([
        format([house, street, landmark, barangay, municipality, province, region, postal]),
        format([barangay, municipality, province]),
        format([municipality, province]),
        format([province]),
    ].filter(Boolean))];
}

function coordinatesFromFeature(feature) {
    const center = feature?.geometry?.coordinates || feature?.center;
    const longitude = Number(feature?.properties?.lon ?? feature?.lon ?? center?.[0]);
    const latitude = Number(feature?.properties?.lat ?? feature?.lat ?? center?.[1]);
    if (!Number.isFinite(latitude) || !Number.isFinite(longitude)
        || latitude < -90 || latitude > 90 || longitude < -180 || longitude > 180) return null;
    return { latitude, longitude };
}

async function reverseGeocodeCoordinates(coordinates, apiKey, signal) {
    const url = new URL('https://api.geoapify.com/v1/geocode/reverse');
    url.searchParams.set('lat', String(coordinates.latitude));
    url.searchParams.set('lon', String(coordinates.longitude));
    url.searchParams.set('countrycodes', 'ph');
    url.searchParams.set('lang', 'en');
    url.searchParams.set('limit', '1');
    url.searchParams.set('format', 'json');
    url.searchParams.set('apiKey', apiKey);

    const response = await fetch(url, {
        headers: { Accept: 'application/json' },
        signal,
    });
    if (!response.ok) throw new Error(await geoapifyErrorMessage(response));

    const payload = await response.json();
    const place = payload.results?.[0] || payload.features?.[0]?.properties || payload.features?.[0];
    if (!place) throw new Error('Geoapify could not identify an address at this pin.');
    return place;
}

function validCoordinates(latitudeValue, longitudeValue) {
    if (!String(latitudeValue ?? '').trim() || !String(longitudeValue ?? '').trim()) return null;
    const latitude = Number(latitudeValue);
    const longitude = Number(longitudeValue);
    if (!Number.isFinite(latitude) || !Number.isFinite(longitude)
        || latitude < -90 || latitude > 90 || longitude < -180 || longitude > 180) return null;
    return { latitude, longitude };
}

function initialize(shell, maplibregl) {
    const form = shell.closest('form');
    const canvas = shell.querySelector('[data-address-pin-canvas]');
    const pinRequired = shell.dataset.addressPinRequired !== 'false';
    const searchInput = shell.querySelector('[data-address-search]');
    const apiKey = shell.dataset.geoapifyKey?.trim() || '';
    const mapToken = shell.dataset.mapToken?.trim() || '';
    const latitudeField = shell.querySelector('[data-address-latitude]');
    const longitudeField = shell.querySelector('[data-address-longitude]');
    const initialCoordinates = validCoordinates(latitudeField?.value, longitudeField?.value);
    const hasInitial = Boolean(initialCoordinates);
    let defaultCenter;
    try { defaultCenter = JSON.parse(shell.dataset.mapCenter || '[121,14.6]'); } catch { defaultCenter = [121, 14.6]; }
    const center = hasInitial ? [initialCoordinates.longitude, initialCoordinates.latitude] : defaultCenter;

    if (!form || !canvas) {
        updateStatus(shell, pinRequired
            ? 'Map pinning is unavailable on this page.'
            : 'Exact map pinning is unavailable. You can continue without it.', true);
        return;
    }

    if (mapToken) maplibregl.accessToken = mapToken;

    const map = new maplibregl.Map({
        container: canvas,
        style: shell.dataset.mapStyle || 'https://tiles.openfreemap.org/styles/liberty',
        center,
        zoom: hasInitial ? 15 : 10,
        attributionControl: {
            customAttribution: apiKey ? '<a href="https://www.geoapify.com/" target="_blank" rel="noopener">Geocoding by Geoapify</a>' : '',
        },
    });
    map.addControl(new maplibregl.NavigationControl({ showCompass: true }), 'top-right');

    let marker = null;
    let pending = hasInitial ? { ...initialCoordinates } : null;
    let pendingReliable = hasInitial;
    let pendingManuallyAdjusted = false;
    let confirmed = hasInitial;
    const confirmButton = shell.querySelector('[data-address-confirm]');
    let searchRequestId = 0;
    let searchController = null;
    let reverseRequestId = 0;
    let reverseController = null;

    function cancelReverseGeocode() {
        reverseRequestId += 1;
        reverseController?.abort();
        reverseController = null;
    }

    const setPending = (coordinates, message, { reliable = false, manual = false } = {}) => {
        if (!validCoordinates(coordinates?.latitude, coordinates?.longitude)) return;
        cancelReverseGeocode();
        pending = coordinates;
        pendingReliable = reliable;
        pendingManuallyAdjusted = manual;
        confirmed = false;
        if (!marker) {
            marker = new maplibregl.Marker({ draggable: true, color: '#800000' })
                .setLngLat([coordinates.longitude, coordinates.latitude])
                .addTo(map);
            marker.on('dragend', () => {
                cancelSearch();
                const [longitude, latitude] = marker.getLngLat().toArray();
                setPending({ latitude, longitude }, 'Marker moved. Confirm the pin when it is exact.', { reliable: true, manual: true });
            });
        } else {
            marker.setLngLat([coordinates.longitude, coordinates.latitude]);
        }
        map.flyTo({ center: [coordinates.longitude, coordinates.latitude], zoom: Math.max(map.getZoom(), 15), essential: true });
        if (confirmButton) confirmButton.hidden = false;
        updateStatus(shell, message || 'Suggested location shown. Drag the marker to the exact position, then confirm it.');
    };

    confirmButton?.addEventListener('click', async () => {
        if (!pending) return;
        if (!pendingReliable && !pendingManuallyAdjusted) {
            updateStatus(shell, 'This result is area-level. Move the pin to the exact building or entrance before confirming.', true);
            return;
        }
        const confirmedCoordinates = { ...pending };
        latitudeField.value = confirmedCoordinates.latitude.toFixed(7);
        longitudeField.value = confirmedCoordinates.longitude.toFixed(7);
        confirmed = true;
        confirmButton.hidden = true;

        if (!apiKey || !form.querySelector('[data-postal-address]')) {
            updateStatus(shell, `Confirmed pin: ${confirmedCoordinates.latitude.toFixed(6)}, ${confirmedCoordinates.longitude.toFixed(6)}.`);
            return;
        }

        cancelReverseGeocode();
        const requestId = reverseRequestId;
        const controller = new AbortController();
        reverseController = controller;
        const timeout = window.setTimeout(() => controller.abort(), 10000);
        updateStatus(shell, 'Pin confirmed. Filling Region, Province, City / Municipality, and Barangay from the map…');
        try {
            const place = await reverseGeocodeCoordinates(confirmedCoordinates, apiKey, controller.signal);
            if (requestId !== reverseRequestId) return;
            const result = await fillAddressFromMapPin(form, place, () => requestId === reverseRequestId);
            if (requestId !== reverseRequestId) return;
            if (result.cancelled) return;
            syncSearchFromAddress();
            if (result.complete) {
                updateStatus(shell, `Pin confirmed. Address filled: ${result.address.barangay}, ${result.address.municipality}${result.address.province ? `, ${result.address.province}` : ''}, ${result.address.region}.`);
            } else {
                const missing = result.missing?.join(', ') || 'some address fields';
                updateStatus(shell, `Pin confirmed, but the map could not match ${missing} to the Philippine address list. Complete those fields manually.`, true);
            }
        } catch (error) {
            if (requestId !== reverseRequestId) return;
            const message = error?.name === 'AbortError'
                ? 'Address lookup timed out. The pin is saved; complete the address fields manually.'
                : (error?.message || 'Address lookup failed. The pin is saved; complete the address fields manually.');
            updateStatus(shell, message, true);
        } finally {
            window.clearTimeout(timeout);
            if (requestId === reverseRequestId) reverseController = null;
        }
    });

    map.on('click', (event) => {
        cancelSearch();
        setPending({ latitude: event.lngLat.lat, longitude: event.lngLat.lng }, 'Map location selected. Confirm the pin to save it.', { reliable: true, manual: true });
    });

    function cancelSearch() {
        searchRequestId += 1;
        searchController?.abort();
        searchController = null;
    }

    const invalidatePin = () => {
        cancelSearch();
        cancelReverseGeocode();
        if (!confirmed && !pending) return;
        pending = null;
        pendingReliable = false;
        pendingManuallyAdjusted = false;
        confirmed = false;
        latitudeField.value = '';
        longitudeField.value = '';
        marker?.remove();
        marker = null;
        if (confirmButton) confirmButton.hidden = true;
        updateStatus(shell, 'Address changed. Search again or select the exact location on the map before saving.');
    };

    const addressInputs = ['region_name', 'region', 'province_name', 'province', 'municipality_name', 'municipality', 'barangay_name', 'barangay', 'house_number', 'street_address', 'street', 'landmark', 'postal_code']
        .map((name) => form.querySelector(`[name="${name}"]`))
        .filter(Boolean);
    let suggestionTimer;
    let searchWasManuallyEdited = false;
    const syncSearchFromAddress = () => {
        if (!searchInput || searchWasManuallyEdited) return;
        searchInput.value = addressQueryCandidates(form)[0] || '';
    };

    syncSearchFromAddress();
    addressInputs.forEach((input) => {
        const handleAddressChange = (event) => {
            if (event.type === 'change' && event.isTrusted === false) return;
            searchWasManuallyEdited = false;
            invalidatePin();
            window.clearTimeout(suggestionTimer);
            syncSearchFromAddress();
            const canTriggerMapSearch = event.currentTarget.matches(
                '[name="barangay_name"], [name="barangay"], [name="street_address"], [name="street"], [name="house_number"], [name="landmark"]',
            );
            if (!canTriggerMapSearch) return;
            const hasBarangay = Boolean(field(form, ['barangay_name', 'barangay'])?.value?.trim());
            const hasStreetAndMunicipality = Boolean(
                field(form, ['street_address', 'street'])?.value?.trim()
                && field(form, ['municipality_name', 'municipality'])?.value?.trim()
            );
            if (!hasBarangay && !hasStreetAndMunicipality) return;
            suggestionTimer = window.setTimeout(() => searchAddress(), 700);
        };
        input.addEventListener('input', handleAddressChange);
        input.addEventListener('change', handleAddressChange);
    });
    searchInput?.addEventListener('input', () => {
        searchWasManuallyEdited = true;
        invalidatePin();
        window.clearTimeout(suggestionTimer);
    });

    form.addEventListener('submit', (event) => {
        if (!pinRequired || (confirmed && validCoordinates(latitudeField.value, longitudeField.value))) return;
        event.preventDefault();
        const hasPendingPin = Boolean(pending && validCoordinates(pending.latitude, pending.longitude));
        updateStatus(shell, hasPendingPin
            ? 'Finish pinning your location: confirm the pin below. If the result is approximate, drag the marker to your exact location first.'
            : 'Set and confirm your delivery pin before saving. Search for the address, use device location, or click the exact spot on the map.', true);
        shell.scrollIntoView({ behavior: 'smooth', block: 'center' });
        if (hasPendingPin && confirmButton && !confirmButton.hidden) {
            confirmButton.focus({ preventScroll: true });
        } else {
            searchInput?.focus({ preventScroll: true });
        }
    });

    async function searchAddress() {
        syncSearchFromAddress();
        const manualQuery = searchWasManuallyEdited ? String(searchInput?.value || '').trim() : '';
        const queries = manualQuery ? [manualQuery] : addressQueryCandidates(form);
        if (!queries.length) return;
        if (!apiKey) {
            updateStatus(shell, 'Address search needs a Geoapify API key. You can still select the exact location on the map.', true);
            return;
        }
        if (!manualQuery && searchInput) searchInput.value = queries[0];
        updateStatus(shell, queries.length > 1
            ? 'Finding the address. Trying its barangay and city if the full address is not listed…'
            : 'Searching OpenStreetMap address data.');
        cancelSearch();
        const requestId = searchRequestId;
        const controller = new AbortController();
        searchController = controller;
        const timeout = window.setTimeout(() => controller.abort(), 10000);
        try {
            let feature = null;
            let matchedQuery = '';
            for (const query of queries) {
                const url = new URL('https://api.geoapify.com/v1/geocode/search');
                url.searchParams.set('text', query);
                url.searchParams.set('filter', 'countrycode:ph');
                url.searchParams.set('lang', 'en');
                url.searchParams.set('limit', '1');
                url.searchParams.set('format', 'json');
                url.searchParams.set('apiKey', apiKey);
                const response = await fetch(url, {
                    headers: { Accept: 'application/json' },
                    signal: controller.signal,
                });
                if (requestId !== searchRequestId) return;
                if (!response.ok) throw await geoapifyErrorMessage(response);
                const payload = await response.json();
                if (requestId !== searchRequestId) return;
                const result = payload.features?.[0] || payload.results?.[0];
                if (coordinatesFromFeature(result)) {
                    feature = result;
                    matchedQuery = query;
                    break;
                }
            }
            const coordinates = coordinatesFromFeature(feature);
            if (!coordinates) throw new Error('no-result');
            const properties = feature?.properties || feature || {};
            const reliable = ['building', 'amenity'].includes(properties.result_type)
                && ['full_match', 'inner_part', 'match_by_building'].includes(properties.rank?.match_type);
            setPending(coordinates, reliable
                ? 'Suggested address location shown. Confirm it or move the pin to the exact entrance.'
                : `Found an approximate location for ${matchedQuery}. Move the pin to the exact building or entrance before confirming.`, { reliable });
        } catch (error) {
            if (requestId !== searchRequestId) return;
            if (error?.name === 'AbortError') {
                updateStatus(shell, 'Geoapify address search timed out. You can select the exact location on the map manually.', true);
            } else if (error?.message === 'no-result') {
                updateStatus(shell, 'No map location matched the selected barangay or city. Search a nearby landmark or place the pin manually.', true);
            } else if (error instanceof TypeError) {
                updateStatus(shell, 'Could not reach Geoapify. Check the connection and this site’s API-key restrictions.', true);
            } else {
                updateStatus(shell, error?.message || 'Address search failed. You can select the exact location on the map manually.', true);
            }
        } finally {
            window.clearTimeout(timeout);
            if (requestId === searchRequestId) searchController = null;
        }
    }

    shell.querySelector('[data-address-search-submit]')?.addEventListener('click', async () => {
        if (!searchInput?.value.trim() && !addressQueryCandidates(form).length) {
            updateStatus(shell, 'Enter an address or complete the address fields first.', true);
            return;
        }
        await searchAddress();
    });

    shell.querySelector('[data-address-use-location]')?.addEventListener('click', () => {
        if (!navigator.geolocation) {
            updateStatus(shell, 'Device location is not supported by this browser.', true);
            return;
        }
        updateStatus(shell, 'Waiting for location permission…');
        navigator.geolocation.getCurrentPosition(
            (position) => setPending({ latitude: position.coords.latitude, longitude: position.coords.longitude }, 'Device location shown. Confirm the pin to save it.', { reliable: true }),
            () => updateStatus(shell, 'Location permission was not granted or GPS is unavailable.', true),
            { enableHighAccuracy: true, timeout: 10000, maximumAge: 30000 },
        );
    });

    if (hasInitial) {
        map.on('load', () => {
            setPending(pending, 'Saved pin loaded. Drag it if the physical location needs correction.', { reliable: true });
            confirmed = true;
            if (confirmButton) confirmButton.hidden = true;
        });
    } else if (!hasInitial && (
        field(form, ['barangay_name', 'barangay'])?.value?.trim()
        || (field(form, ['street_address', 'street'])?.value?.trim()
            && field(form, ['municipality_name', 'municipality'])?.value?.trim())
    )) {
        window.setTimeout(() => searchAddress(), 700);
    }
}

function bootAddressPins() {
    const shells = [...document.querySelectorAll('[data-address-pin]')];
    if (!shells.length) return;
    const loadRenderer = mapRenderer.loadMapLibre || mapRenderer.loadMapbox;
    if (typeof loadRenderer !== 'function') {
        shells.forEach((shell) => updateStatus(shell, 'Map service is unavailable. Complete the address fields manually.', true));
        return;
    }
    loadRenderer()
        .then((maplibregl) => shells.forEach((shell) => initialize(shell, maplibregl)))
        .catch(() => shells.forEach((shell) => updateStatus(
            shell,
            shell.dataset.addressPinRequired === 'false'
                ? 'Map service is unavailable. You can continue registration without an exact pin.'
                : 'Map service is unavailable. Confirm the exact pin before saving.',
            true,
        )));
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', bootAddressPins);
else bootAddressPins();
