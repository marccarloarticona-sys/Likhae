import { BrowserMultiFormatReader } from '@zxing/browser';
import { BarcodeFormat, DecodeHintType } from '@zxing/library';

const scannerForms = new WeakSet();
const submitButtons = (form) => [...form.querySelectorAll('button[type="submit"], button:not([type]), input[type="submit"]')];

function showMessage(scanner, form, message, linkUrl = '') {
    let output = scanner?.querySelector('[data-scanner-message]') || form?.querySelector('[data-scanner-message]');
    if (!output && form?.matches('[data-scanner-async]')) {
        output = form.parentElement?.querySelector('[data-scan-result]');
        if (!output) {
            output = document.createElement('p');
            output.dataset.scanResult = '';
            output.className = 'mt-2 text-sm font-semibold text-muted';
            output.setAttribute('aria-live', 'polite');
            form.insertAdjacentElement('afterend', output);
        }
    }
    if (!output) return;

    output.replaceChildren(document.createTextNode(message));
    if (linkUrl) {
        output.append(' ');
        const link = document.createElement('a');
        link.href = linkUrl;
        link.target = '_blank';
        link.rel = 'noopener';
        link.className = 'underline';
        link.textContent = 'Open result';
        output.append(link);
    }
}

function responseMessage(payload) {
    if (typeof payload?.message === 'string' && payload.message.trim()) return payload.message;
    const firstError = Object.values(payload?.errors || {}).flat().find((value) => typeof value === 'string');
    return firstError || 'The scan could not be completed. Check the code and try again.';
}

async function submitScannerForm(form, scanner = form.closest('[data-parcel-scanner]')) {
    if (form.dataset.scannerBusy === 'true') return;
    form.dataset.scannerBusy = 'true';
    const buttons = submitButtons(form);
    const previousButtonState = buttons.map((button) => ({
        button,
        disabled: button.disabled,
        text: button.tagName === 'INPUT' ? button.value : button.textContent,
    }));
    buttons.forEach((button) => {
        button.disabled = true;
        if (button.tagName === 'INPUT') button.value = 'Checking...';
        else button.textContent = 'Checking...';
    });

    try {
        const method = (form.method || 'GET').toUpperCase();
        const url = new URL(form.action || window.location.href, window.location.href);
        const formData = new FormData(form);
        const options = {
            method,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json, text/html;q=0.9',
                'X-Requested-With': 'XMLHttpRequest',
            },
        };
        if (method === 'GET') {
            for (const [key, value] of formData.entries()) {
                if (typeof value === 'string') url.searchParams.set(key, value);
            }
        } else {
            options.body = formData;
        }

        const response = await fetch(url, options);
        const contentType = response.headers.get('content-type') || '';
        if (contentType.includes('application/json')) {
            const payload = await response.json();
            const succeeded = response.ok && payload.success !== false;
            const message = responseMessage(payload);
            const resultUrl = payload.open_url || payload.result_url || '';
            showMessage(scanner, form, message, succeeded ? resultUrl : '');
            document.dispatchEvent(new CustomEvent(succeeded ? 'parcel-scanner:success' : 'parcel-scanner:error', {
                detail: { ...payload, message, form, scanner, ok: succeeded },
            }));
            if (succeeded && payload.clear_input) {
                form.querySelector('[data-scanner-input], input[name="tracking"], input[name="scanned_code"]')?.setAttribute('value', '');
                const input = form.querySelector('[data-scanner-input], input[name="tracking"], input[name="scanned_code"]');
                if (input) input.value = '';
            }
            return;
        }

        const resultUrl = response.url && response.url !== window.location.href ? response.url : '';
        const succeeded = response.ok;
        const message = succeeded
            ? 'Scan complete. Open the matching parcel result.'
            : `The scan could not be completed (HTTP ${response.status}). Refresh the page and try again.`;
        showMessage(scanner, form, message, succeeded ? (resultUrl || url.href) : '');
        document.dispatchEvent(new CustomEvent(succeeded ? 'parcel-scanner:success' : 'parcel-scanner:error', {
            detail: { success: succeeded, message, form, scanner, open_url: resultUrl || url.href, ok: succeeded },
        }));
    } catch (error) {
        const message = 'Unable to submit this scan. Check your connection and try again.';
        showMessage(scanner, form, message);
        document.dispatchEvent(new CustomEvent('parcel-scanner:error', {
            detail: { success: false, message, form, scanner, error },
        }));
    } finally {
        form.dataset.scannerBusy = 'false';
        previousButtonState.forEach(({ button, disabled, text }) => {
            if (!button.isConnected) return;
            button.disabled = disabled;
            if (button.tagName === 'INPUT') button.value = text;
            else button.textContent = text;
        });
        if (scanner) scanner.dataset.scannerSubmitting = 'false';
        const input = form.querySelector('[data-scanner-input], input[name="tracking"], input[name="scanned_code"]');
        if (input && scanner?.dataset.scannerKeepFocus !== 'false') input.focus({ preventScroll: true });
    }
}

function bindScannerForm(form) {
    if (!form || scannerForms.has(form)) return;
    scannerForms.add(form);
    form.addEventListener('submit', (event) => {
        event.preventDefault();
        void submitScannerForm(form, form.closest('[data-parcel-scanner]'));
    });
}

function bindScannerForms() {
    document.querySelectorAll('[data-scanner-form], form[data-scanner-async]').forEach(bindScannerForm);
}

export function initializeParcelScanners() {
    bindScannerForms();
    document.querySelectorAll('[data-parcel-scanner]').forEach((scanner) => {
        if (scanner.dataset.scannerInitialized === 'true') return;
        scanner.dataset.scannerInitialized = 'true';

        const video = scanner.querySelector('[data-scanner-video]');
        const placeholder = scanner.querySelector('[data-scanner-placeholder]');
        const guide = scanner.querySelector('[data-scanner-guide]');
        const start = scanner.querySelector('[data-scanner-start]');
        const stop = scanner.querySelector('[data-scanner-stop]');
        const cameraWrap = scanner.querySelector('[data-camera-select-wrap]');
        const cameraSelect = scanner.querySelector('[data-camera-select]');
        const torchButton = scanner.querySelector('[data-scanner-torch]');
        const message = scanner.querySelector('[data-scanner-message]');
        const input = scanner.querySelector('[data-scanner-input]');
        const method = scanner.querySelector('[data-scanner-method]');
        const transferScan = scanner.closest('[data-transfer-handoff], [data-transfer-scan-container]')
            || (scanner.dataset.scannerFillMatching === 'true' ? document.querySelector('[data-transfer-handoff], [data-transfer-scan-container]') : null);
        const scanMode = transferScan?.dataset.transferScanContainer || 'release';
        const autoSubmit = scanner.dataset.scannerAutosubmit !== 'false' && !transferScan;
        const form = scanner.querySelector('[data-scanner-form]') || (autoSubmit ? scanner.closest('form') : null);
        if (!video || !placeholder || !start || !stop || !input || !message) return;
        if (form) {
            form.dataset.scannerAsync = '';
            bindScannerForm(form);
        }

        let controls;
        let stream;
        let torchEnabled = false;
        let cameraRequest = 0;
        let lastCode = '';
        let lastCodeAt = 0;
        const transferScanInputs = [...(transferScan?.querySelectorAll('[data-expected-tracking]') || [])];
        const transferScanProgress = transferScan?.querySelector('[data-transfer-scan-progress], [data-handoff-progress]');
        const manifestProgress = document.querySelector('[data-manifest-handoff-progress]');
        const normalizeTrackingCode = (value) => String(value ?? '').replace(/\s+/g, '').toUpperCase();
        const findTransferScanInput = (value) => {
            const normalized = normalizeTrackingCode(value);
            const exact = transferScanInputs.find((field) => normalizeTrackingCode(field.dataset.expectedTracking) === normalized);
            if (exact) return exact;
            const codesInPayload = String(value ?? '').toUpperCase().match(/TRK-\d{8}-[A-Z0-9]+/g) || [];
            return transferScanInputs.find((field) => codesInPayload.includes(normalizeTrackingCode(field.dataset.expectedTracking)));
        };
        const setTransferScanStatus = (row, label, color = 'text-muted') => {
            const status = row.querySelector('[data-scan-verification]');
            if (!status) return;
            status.textContent = label;
            status.className = `text-xs font-semibold ${color}`;
        };
        const submitTransferParcel = async (field, scanMethod, allowMissingWithoutScan = false) => {
            const row = field.closest('form[data-transfer-parcel-row]');
            if (!row || row.dataset.released === 'true' || scanner.dataset.scannerSubmitting === 'true') return;
            const button = row.querySelector('button[type="submit"]');
            const rowMethod = row.querySelector('[name="scan_method"]');
            const tracking = field.dataset.expectedTracking;
            const outcome = row.querySelector('[name="outcome"]')?.value || 'RECEIVED';
            const missingWithoutScan = scanMode === 'receive' && outcome === 'MISSING' && allowMissingWithoutScan;
            scanner.dataset.scannerSubmitting = 'true';
            row.dataset.handoffBusy = 'true';
            field.value = missingWithoutScan ? '' : tracking;
            if (rowMethod) rowMethod.value = scanMethod;
            if (button) button.disabled = true;
            const progressVerb = scanMode === 'receive' ? 'Receiving' : 'Releasing';
            const statusLabel = scanMode === 'receive'
                ? (outcome === 'MISSING' ? 'Missing' : outcome === 'DAMAGED' ? 'Damaged' : 'Received')
                : 'Released';
            setTransferScanStatus(row, `${progressVerb}...`, 'text-blue-700');
            message.textContent = missingWithoutScan
                ? 'Saving the missing-parcel exception...'
                : `Saving ${scanMode === 'receive' ? 'receipt' : 'handoff'} for ${tracking}...`;

            try {
                const response = await fetch(row.action, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body: new FormData(row),
                });
                const contentType = response.headers.get('content-type') || '';
                if (!contentType.includes('application/json')) throw new Error('Could not save the scan. Reload this page and try again.');
                const payload = await response.json();
                if (!response.ok || payload.success === false) throw new Error(responseMessage(payload));

                row.dataset.released = 'true';
                field.readOnly = true;
                if (button) button.textContent = statusLabel;
                setTransferScanStatus(row, statusLabel, outcome === 'MISSING' || outcome === 'DAMAGED' ? 'text-amber-800' : 'text-green-700');
                const completedCount = payload.completed_count ?? payload.released_count ?? payload.received_count ?? 0;
                if (transferScanProgress) transferScanProgress.textContent = `${completedCount} / ${payload.total_count}`;
                if (manifestProgress && scanMode === 'release') {
                    manifestProgress.textContent = `· ${payload.released_count} / ${payload.total_count} parcels released`;
                    manifestProgress.classList.remove('hidden');
                }
                [...document.querySelectorAll('[data-transfer-leg-status]')]
                    .find((cell) => cell.dataset.transferLegStatus === String(payload.item_id))
                    ?.querySelector('strong')?.replaceChildren(document.createTextNode(statusLabel));
                message.textContent = scanMode === 'receive'
                    ? `${missingWithoutScan ? 'Missing parcel recorded' : `${tracking} ${statusLabel.toLowerCase()} at destination`}. ${completedCount} of ${payload.total_count} parcels accounted for.`
                    : `${tracking} released. ${payload.released_count} of ${payload.total_count} parcels handed off.`;
                if ((scanMode === 'release' && payload.manifest_status === 'RELEASED')
                    || (scanMode === 'receive' && payload.pending_count === 0)) {
                    stopCamera();
                    window.location.reload();
                }
            } catch (error) {
                if (!missingWithoutScan) field.value = '';
                if (rowMethod) rowMethod.value = 'MANUAL';
                if (button) button.disabled = false;
                setTransferScanStatus(row, scanMode === 'receive' ? 'Awaiting receipt scan' : 'Awaiting scan');
                message.textContent = error.message || 'Could not save the scan. Try again.';
                input.focus({ preventScroll: true });
            } finally {
                row.dataset.handoffBusy = 'false';
                scanner.dataset.scannerSubmitting = 'false';
            }
        };
        const applyTransferScanCode = (value, scanMethod = 'BARCODE') => {
            const code = String(value ?? '').trim();
            if (!code || scanner.dataset.scannerSubmitting === 'true') return false;
            const matchingInput = findTransferScanInput(code);
            if (!matchingInput) {
                message.textContent = `No parcel in this manifest matches ${code}.`;
                return false;
            }
            const row = matchingInput.closest('[data-transfer-parcel-row]');
            if (scanMode === 'receive' && row?.querySelector('[name="outcome"]')?.value === 'MISSING') {
                input.value = '';
                message.textContent = 'A missing parcel cannot be confirmed by scanning. Choose Received or Damaged to scan it.';
                return true;
            }
            if (row?.dataset.released === 'true') {
                input.value = '';
                message.textContent = scanMode === 'receive'
                    ? `${matchingInput.dataset.expectedTracking} was already accounted for.`
                    : `${matchingInput.dataset.expectedTracking} was already released.`;
                return true;
            }
            input.value = '';
            void submitTransferParcel(matchingInput, scanMethod);
            return true;
        };
        transferScan?.addEventListener('input', (event) => {
            const field = event.target;
            if (field?.matches?.('[data-expected-tracking]')) {
                const matchingInput = findTransferScanInput(field.value);
                if (matchingInput) {
                    const code = field.value;
                    if (matchingInput !== field) field.value = '';
                    applyTransferScanCode(code, 'BARCODE');
                }
            }
        });
        transferScan?.querySelectorAll('form[data-transfer-parcel-row]').forEach((row) => {
            row.addEventListener('submit', (event) => {
                event.preventDefault();
                const field = row.querySelector('[data-expected-tracking]');
                const scanMethod = row.querySelector('[name="scan_method"]')?.value || 'MANUAL';
                if (scanMode === 'receive' && row.querySelector('[name="outcome"]')?.value === 'MISSING') {
                    void submitTransferParcel(field, scanMethod, true);
                    return;
                }
                applyTransferScanCode(field?.value, scanMethod);
            });
        });
        transferScan?.addEventListener('keydown', (event) => {
            if (event.key !== 'Enter' || !event.target?.matches?.('[data-expected-tracking]')) return;
            event.preventDefault();
            applyTransferScanCode(event.target.value, 'BARCODE');
        });
        if (transferScan && input.type !== 'hidden') {
            input.addEventListener('input', () => {
                if (findTransferScanInput(input.value)) applyTransferScanCode(input.value, 'BARCODE');
            });
            input.addEventListener('keydown', (event) => {
                if (event.key !== 'Enter') return;
                event.preventDefault();
                applyTransferScanCode(input.value, 'BARCODE');
            });
        }

        const stopCamera = () => {
            cameraRequest += 1;
            controls?.stop();
            controls = undefined;
            stream?.getTracks().forEach((track) => track.stop());
            stream = undefined;
            video.srcObject = null;
            video.classList.add('hidden');
            placeholder.classList.remove('hidden');
            guide?.classList.add('hidden');
            start.classList.remove('hidden');
            start.disabled = false;
            stop.classList.add('hidden');
            cameraWrap?.classList.add('hidden');
            torchButton?.classList.add('hidden');
            torchEnabled = false;
            torchButton?.setAttribute('aria-pressed', 'false');
            if (torchButton) torchButton.textContent = 'Turn Torch On';
        };

        const cameraErrorMessage = (error) => {
            if (!window.isSecureContext) return 'Camera access requires HTTPS. Use https://likhae.online.';
            if (!navigator.mediaDevices?.getUserMedia) return 'This browser does not support camera access. Enter the tracking number manually.';
            if (error?.name === 'NotAllowedError' || error?.name === 'SecurityError') return 'Camera permission was blocked. Allow Camera for likhae.online in the browser site settings, then try again.';
            if (error?.name === 'NotFoundError' || error?.name === 'DevicesNotFoundError') return 'No camera was found on this device. Enter the tracking number manually.';
            if (error?.name === 'NotReadableError' || error?.name === 'TrackStartError') return 'The camera is already in use by another app or browser tab. Close it there and try again.';
            if (error?.name === 'OverconstrainedError') return 'The requested camera is unavailable. Try again or use manual tracking entry.';
            return `Camera could not start${error?.message ? `: ${error.message}` : '.'} Use manual tracking entry if needed.`;
        };

        const refreshCameras = async (selectedDeviceId = '') => {
            if (!cameraSelect || !navigator.mediaDevices?.enumerateDevices) return;
            const devices = (await navigator.mediaDevices.enumerateDevices()).filter((device) => device.kind === 'videoinput');
            cameraSelect.replaceChildren(...devices.map((device, index) => {
                const option = document.createElement('option');
                option.value = device.deviceId;
                option.textContent = device.label || `Camera ${index + 1}`;
                return option;
            }));
            if (selectedDeviceId && devices.some((device) => device.deviceId === selectedDeviceId)) cameraSelect.value = selectedDeviceId;
            cameraWrap?.classList.toggle('hidden', devices.length < 2);
        };

        const submitDetectedCode = (result) => {
            if (scanner.dataset.scannerSubmitting === 'true' || form?.dataset.scannerBusy === 'true') return;
            const code = result.getText().trim();
            if (!code) return;
            const now = Date.now();
            if (code === lastCode) return;
            lastCode = code;
            lastCodeAt = now;
            input.value = code;
            const detectedMethod = result.getBarcodeFormat() === BarcodeFormat.QR_CODE ? 'QR' : 'BARCODE';
            if (method) method.value = detectedMethod;
            navigator.vibrate?.(45);

            if (transferScan) {
                applyTransferScanCode(code, method?.value || 'BARCODE');
                return;
            }

            if (scanner.dataset.scannerAppendTarget) {
                const target = document.querySelector(scanner.dataset.scannerAppendTarget);
                if (!target) {
                    message.textContent = 'The tracking list is unavailable. Refresh the page and try again.';
                    input.value = '';
                    return;
                }

                const currentCodes = String(target.value || '').split(/[\r\n,;]+/).map((value) => value.trim()).filter(Boolean);
                const normalizedCode = normalizeTrackingCode(code);
                if (currentCodes.some((value) => normalizeTrackingCode(value) === normalizedCode)) {
                    message.textContent = `${code} is already in the tracking list. Scan the next parcel.`;
                } else {
                    currentCodes.push(code);
                    target.value = currentCodes.join('\n');
                    target.dispatchEvent(new Event('input', { bubbles: true }));
                    if (currentCodes.length === 1 && scanner.dataset.scannerMethodTarget) {
                        const methodTarget = document.querySelector(scanner.dataset.scannerMethodTarget);
                        if (methodTarget) methodTarget.value = detectedMethod;
                    }
                    message.textContent = `Added ${code} to the tracking list (${currentCodes.length}). Scan the next parcel or verify and receive.`;
                }
                input.value = '';
                scanner.dataset.scannerSubmitting = 'false';
                return;
            }

            scanner.dataset.scannerSubmitting = 'true';
            message.textContent = `Detected tracking: ${code}. Processing...`;
            if (form) form.requestSubmit();
            else scanner.dataset.scannerSubmitting = 'false';
        };

        const startCamera = async (deviceId = '') => {
            if (stream) return;
            const requestId = ++cameraRequest;
            message.textContent = 'Requesting camera access...';
            start.disabled = true;
            try {
                if (!window.isSecureContext) throw new DOMException('A secure HTTPS connection is required.', 'SecurityError');
                if (!navigator.mediaDevices?.getUserMedia) throw new DOMException('Camera API unavailable.', 'NotSupportedError');

                const nextStream = await navigator.mediaDevices.getUserMedia({
                    audio: false,
                    video: {
                        ...(deviceId ? { deviceId: { exact: deviceId } } : { facingMode: { ideal: 'environment' } }),
                        width: { ideal: 1920 },
                        height: { ideal: 1080 },
                    },
                });
                if (requestId !== cameraRequest) {
                    nextStream.getTracks().forEach((track) => track.stop());
                    return;
                }
                stream = nextStream;
                const hints = new Map();
                hints.set(DecodeHintType.POSSIBLE_FORMATS, [
                    BarcodeFormat.QR_CODE, BarcodeFormat.CODE_128, BarcodeFormat.CODE_39,
                    BarcodeFormat.CODE_93, BarcodeFormat.DATA_MATRIX, BarcodeFormat.EAN_13,
                    BarcodeFormat.EAN_8, BarcodeFormat.UPC_A, BarcodeFormat.UPC_E,
                ]);
                hints.set(DecodeHintType.TRY_HARDER, true);
                const reader = new BrowserMultiFormatReader(hints, {
                    delayBetweenScanAttempts: 120,
                    delayBetweenScanSuccess: 450,
                });
                video.classList.remove('hidden');
                placeholder.classList.add('hidden');
                guide?.classList.remove('hidden');
                start.classList.add('hidden');
                stop.classList.remove('hidden');
                video.srcObject = stream;
                await video.play();
                const [track] = stream.getVideoTracks();
                await refreshCameras(track?.getSettings?.().deviceId || '');
                const torchSupported = Boolean(track?.getCapabilities?.().torch);
                torchButton?.classList.toggle('hidden', !torchSupported);
                controls = await reader.decodeFromStream(stream, video, (result) => {
                    if (result) {
                        submitDetectedCode(result);
                    } else if (Date.now() - lastCodeAt > 1100) {
                        lastCode = '';
                    }
                });
                message.textContent = 'Point the camera at the waybill code.';
            } catch (error) {
                const errorMessage = cameraErrorMessage(error);
                stopCamera();
                message.textContent = errorMessage;
            }
        };

        start.addEventListener('click', () => startCamera(cameraSelect?.value || ''));
        stop.addEventListener('click', stopCamera);
        window.addEventListener('pagehide', stopCamera);
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) stopCamera();
        });

        cameraSelect?.addEventListener('change', () => {
            if (!stream) return;
            const selectedDevice = cameraSelect.value;
            stopCamera();
            startCamera(selectedDevice);
        });

        torchButton?.addEventListener('click', async () => {
            const [track] = stream?.getVideoTracks() || [];
            if (!track) return;
            try {
                torchEnabled = !torchEnabled;
                await track.applyConstraints({ advanced: [{ torch: torchEnabled }] });
                torchButton.textContent = torchEnabled ? 'Turn Torch Off' : 'Turn Torch On';
                torchButton.setAttribute('aria-pressed', String(torchEnabled));
            } catch {
                torchEnabled = false;
                torchButton.classList.add('hidden');
                message.textContent = 'Torch control is unavailable on this camera.';
            }
        });
    });
}
