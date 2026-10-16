import { BrowserMultiFormatReader } from '@zxing/browser';
import { BarcodeFormat, DecodeHintType } from '@zxing/library';

export function initializeParcelScanners() {
    document.querySelectorAll('[data-parcel-scanner]').forEach((scanner) => {
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
        const form = scanner.querySelector('[data-scanner-form]');
        let controls;
        let stream;
        let torchEnabled = false;
        let submitted = false;
        let cameraRequest = 0;

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
            if (selectedDeviceId && devices.some((device) => device.deviceId === selectedDeviceId)) {
                cameraSelect.value = selectedDeviceId;
            }
            cameraWrap?.classList.toggle('hidden', devices.length < 2);
        };

        const startCamera = async (deviceId = '') => {
            if (stream) stopCamera();
            const requestId = ++cameraRequest;
            message.textContent = 'Requesting camera access...';
            start.disabled = true;
            try {
                if (!window.isSecureContext) throw new DOMException('A secure HTTPS connection is required.', 'SecurityError');
                if (!navigator.mediaDevices?.getUserMedia) throw new DOMException('Camera API unavailable.', 'NotSupportedError');

                stream = await navigator.mediaDevices.getUserMedia({
                    audio: false,
                    video: {
                        ...(deviceId ? { deviceId: { exact: deviceId } } : { facingMode: { ideal: 'environment' } }),
                        width: { ideal: 1920 },
                        height: { ideal: 1080 },
                    },
                });
                if (requestId !== cameraRequest) {
                    stream.getTracks().forEach((track) => track.stop());
                    stream = undefined;
                    return;
                }
                const hints = new Map();
                hints.set(DecodeHintType.POSSIBLE_FORMATS, [BarcodeFormat.CODE_128, BarcodeFormat.QR_CODE]);
                hints.set(DecodeHintType.TRY_HARDER, true);
                const reader = new BrowserMultiFormatReader(hints, {
                    delayBetweenScanAttempts: 100,
                    delayBetweenScanSuccess: 500,
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
                    if (!result || submitted) return;
                    submitted = true;
                    input.value = result.getText().trim();
                    method.value = result.getBarcodeFormat() === BarcodeFormat.QR_CODE ? 'QR' : 'BARCODE';
                    message.textContent = `Detected tracking: ${input.value}`;
                    stopCamera();
                    form?.requestSubmit();
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
            if (stream) {
                const selectedDevice = cameraSelect.value;
                stopCamera();
                startCamera(selectedDevice);
            }
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

        form.addEventListener('submit', () => {
            if (submitted) return;
            submitted = true;
            stopCamera();
            const submit = form.querySelector('button[type="submit"], button:not([type])');
            if (submit) {
                submit.disabled = true;
                submit.textContent = 'Checking Parcel...';
            }
        });
    });
}
