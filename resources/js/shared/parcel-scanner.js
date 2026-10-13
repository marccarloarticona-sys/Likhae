import { BrowserMultiFormatReader } from '@zxing/browser';
import { BarcodeFormat, DecodeHintType } from '@zxing/library';

export function initializeParcelScanners() {
    document.querySelectorAll('[data-parcel-scanner]').forEach((scanner) => {
        const video = scanner.querySelector('[data-scanner-video]');
        const placeholder = scanner.querySelector('[data-scanner-placeholder]');
        const start = scanner.querySelector('[data-scanner-start]');
        const stop = scanner.querySelector('[data-scanner-stop]');
        const message = scanner.querySelector('[data-scanner-message]');
        const input = scanner.querySelector('[data-scanner-input]');
        const method = scanner.querySelector('[data-scanner-method]');
        const form = scanner.querySelector('[data-scanner-form]');
        let controls;
        let stream;

        const stopCamera = () => {
            controls?.stop();
            controls = undefined;
            stream?.getTracks().forEach((track) => track.stop());
            stream = undefined;
            video.srcObject = null;
            video.classList.add('hidden');
            placeholder.classList.remove('hidden');
            start.classList.remove('hidden');
            start.disabled = false;
            stop.classList.add('hidden');
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

        start.addEventListener('click', async () => {
            message.textContent = 'Requesting camera access...';
            start.disabled = true;
            try {
                if (!window.isSecureContext) throw new DOMException('A secure HTTPS connection is required.', 'SecurityError');
                if (!navigator.mediaDevices?.getUserMedia) throw new DOMException('Camera API unavailable.', 'NotSupportedError');

                stream = await navigator.mediaDevices.getUserMedia({
                    audio: false,
                    video: {
                        facingMode: { ideal: 'environment' },
                        width: { ideal: 1920 },
                        height: { ideal: 1080 },
                    },
                });
                const hints = new Map();
                hints.set(DecodeHintType.POSSIBLE_FORMATS, [BarcodeFormat.CODE_128, BarcodeFormat.QR_CODE]);
                hints.set(DecodeHintType.TRY_HARDER, true);
                const reader = new BrowserMultiFormatReader(hints, {
                    delayBetweenScanAttempts: 100,
                    delayBetweenScanSuccess: 500,
                });
                video.classList.remove('hidden');
                placeholder.classList.add('hidden');
                start.classList.add('hidden');
                stop.classList.remove('hidden');
                controls = await reader.decodeFromStream(stream, video, (result) => {
                    if (!result) return;
                    input.value = result.getText().trim();
                    method.value = result.getBarcodeFormat() === BarcodeFormat.QR_CODE ? 'QR' : 'BARCODE';
                    message.textContent = `Detected tracking: ${input.value}`;
                    stopCamera();
                    form.requestSubmit();
                });
                message.textContent = 'Point the camera at the waybill code.';
            } catch (error) {
                const errorMessage = cameraErrorMessage(error);
                stopCamera();
                message.textContent = errorMessage;
            }
        });
        stop.addEventListener('click', stopCamera);
        window.addEventListener('pagehide', stopCamera);
    });
}
