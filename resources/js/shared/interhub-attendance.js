import { BrowserMultiFormatReader } from '@zxing/browser';
import { BarcodeFormat, DecodeHintType } from '@zxing/library';

function initializeAttendanceScanner(scanner) {
    if (scanner.dataset.attendanceInitialized === 'true') return;
    scanner.dataset.attendanceInitialized = 'true';
    const video = scanner.querySelector('[data-attendance-video]');
    const placeholder = scanner.querySelector('[data-attendance-placeholder]');
    const start = scanner.querySelector('[data-attendance-start]');
    const stop = scanner.querySelector('[data-attendance-stop]');
    const message = scanner.querySelector('[data-attendance-message]');
    if (!video || !placeholder || !start || !stop || !message) return;

    let stream = null;
    let controls = null;
    let busy = false;
    let lastToken = '';
    let lastSeen = 0;

    const stopCamera = () => {
        controls?.stop();
        controls = null;
        stream?.getTracks().forEach((track) => track.stop());
        stream = null;
        video.srcObject = null;
        video.classList.add('hidden');
        placeholder.classList.remove('hidden');
        start.classList.remove('hidden');
        stop.classList.add('hidden');
    };

    const submitToken = async (raw) => {
        const token = String(raw ?? '').trim();
        if (!/^[a-f0-9]{48}$/i.test(token) || busy) {
            message.textContent = 'That is not a valid Interhub attendance QR. Scan the current 20-second code.';
            return;
        }
        if (token === lastToken && Date.now() - lastSeen < 3000) return;
        lastToken = token;
        lastSeen = Date.now();
        busy = true;
        message.textContent = 'Recording the shift scan…';
        try {
            const response = await fetch(scanner.dataset.scanUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                },
                body: JSON.stringify({ token }),
            });
            const payload = await response.json();
            if (!response.ok || payload.success === false) throw new Error(payload.message || 'The scan could not be completed. Refresh the QR and try again.');
            message.textContent = payload.message || 'Shift scan recorded.';
            message.classList.remove('text-red-700');
            message.classList.add('text-green-700');
            stopCamera();
            window.setTimeout(() => window.location.reload(), 1200);
        } catch (error) {
            message.textContent = error.message || 'Unable to save the scan. Check your connection and try again.';
            message.classList.remove('text-green-700');
            message.classList.add('text-red-700');
            lastToken = '';
        } finally {
            busy = false;
        }
    };

    start.addEventListener('click', async () => {
        if (!navigator.mediaDevices?.getUserMedia) {
            message.textContent = 'Camera access requires a supported browser and secure connection.';
            return;
        }
        try {
            stream = await navigator.mediaDevices.getUserMedia({ audio: false, video: { facingMode: { ideal: 'environment' } } });
            const hints = new Map();
            hints.set(DecodeHintType.POSSIBLE_FORMATS, [BarcodeFormat.QR_CODE]);
            hints.set(DecodeHintType.TRY_HARDER, true);
            const reader = new BrowserMultiFormatReader(hints, { delayBetweenScanAttempts: 150, delayBetweenScanSuccess: 500 });
            video.srcObject = stream;
            video.classList.remove('hidden');
            placeholder.classList.add('hidden');
            start.classList.add('hidden');
            stop.classList.remove('hidden');
            await video.play();
            controls = await reader.decodeFromStream(stream, video, (result) => {
                if (result) void submitToken(result.getText());
            });
            message.textContent = 'Point the camera at the other side’s current QR.';
        } catch (error) {
            stopCamera();
            message.textContent = error?.name === 'NotAllowedError'
                ? 'Allow camera access in the browser to scan attendance QR codes.'
                : 'Camera could not start. Check camera permission and try again.';
        }
    });
    stop.addEventListener('click', stopCamera);
    window.addEventListener('pagehide', stopCamera);
    document.addEventListener('visibilitychange', () => { if (document.hidden) stopCamera(); });
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-attendance-qr-scanner]').forEach(initializeAttendanceScanner);
});
