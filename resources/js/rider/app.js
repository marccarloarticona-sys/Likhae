import { initializeParcelScanners } from '../shared/parcel-scanner';
import '../shared/echo';
import '../shared/messages';
import '../shared/notification-sounds';
import '../shared/rider-location';

/*
|--------------------------------------------------------------------------
| RIDER JS
|--------------------------------------------------------------------------
*/


document.addEventListener('DOMContentLoaded', function () {

    initializeParcelScanners();


    /*
    |--------------------------------------------------------------------------
    | Pickups Index — Accept Pickup Button
    |--------------------------------------------------------------------------
    */

    document.querySelectorAll('.accept-pickup-btn').forEach(function (button) {

        button.addEventListener('click', function () {

            const tracking = this.dataset.tracking;
            const pickup   = document.getElementById('pickup-' + tracking);

            if (!pickup) return;

            this.disabled    = true;
            this.textContent = 'Accepting...';

            pickup.querySelectorAll('.pickup-status').forEach(function (badge) {

                badge.classList.replace('bg-warning-soft', 'bg-success-soft');
                badge.classList.replace('text-warning', 'text-success');

                const dot = badge.querySelector('.status-dot');
                if (dot) dot.classList.replace('bg-warning', 'bg-success');

                const label = badge.querySelector('.status-label');
                if (label) label.textContent = 'Pickup Accepted';

            });

            this.textContent = 'Pickup Accepted';
            this.classList.replace('bg-primary', 'bg-success');

            const url = window.likhaeRoutes?.pickupShow
                ? window.likhaeRoutes.pickupShow.replace(':tracking', encodeURIComponent(tracking))
                : '#';

            setTimeout(function () {
                window.location.href = url;
            }, 500);

        });

    });


    /*
    |--------------------------------------------------------------------------
    | Deliveries Index — Accept Delivery Button
    |--------------------------------------------------------------------------
    */

    document.querySelectorAll('.accept-delivery-btn').forEach(function (button) {

        button.addEventListener('click', function () {

            const tracking = this.dataset.tracking;

            this.disabled    = true;
            this.textContent = 'Accepting...';

            setTimeout(() => {
                this.textContent = 'Accepted ✓';
                this.classList.replace('bg-primary', 'bg-success');
            }, 400);

        });

    });


    /*
    |--------------------------------------------------------------------------
    | Scan Page — Start Scanner
    |--------------------------------------------------------------------------
    */

    const startScannerBtn   = document.getElementById('startScannerBtn');
    const confirmPickupBtn  = document.getElementById('confirmPickupBtn');
    const scannerFrame      = document.getElementById('scannerFrame');
    const scannerIcon       = document.getElementById('scannerIcon');
    const scannerTitle      = document.getElementById('scannerTitle');
    const scannerDescription = document.getElementById('scannerDescription');
    const scanResult        = document.getElementById('scanResult');
    const scannerMessage    = document.getElementById('scannerMessage');
    const parcelStatus      = document.getElementById('parcelStatus');
    const pickedUpStep      = document.getElementById('pickedUpStep');
    const sortingStep       = document.getElementById('sortingStep');

    startScannerBtn?.addEventListener('click', function () {

        startScannerBtn.disabled = true;

        startScannerBtn.innerHTML = `
            <svg viewBox="0 0 24 24" class="h-4 w-4 animate-spin fill-none stroke-current">
                <circle cx="12" cy="12" r="9" stroke-width="1.5" class="opacity-30"></circle>
                <path d="M21 12a9 9 0 0 1-9 9" stroke-width="1.5"></path>
            </svg>
            Scanning...
        `;

        scannerMessage.textContent = 'Scanning for the parcel barcode...';
        scannerFrame.classList.remove('opacity-0');
        scannerFrame.classList.add('animate-pulse');

        setTimeout(function () {

            scannerFrame.classList.remove('animate-pulse');
            scannerIcon.classList.replace('bg-primary-soft', 'bg-success-soft');
            scannerIcon.classList.replace('text-primary', 'text-success');

            scannerTitle.textContent       = 'Parcel Verified';
            scannerDescription.textContent = 'The parcel barcode matches this assignment.';

            scanResult.classList.remove('hidden');

            scannerMessage.textContent = 'Parcel successfully scanned. You may now confirm the pickup.';

            startScannerBtn.innerHTML = `
                <svg viewBox="0 0 24 24" class="h-4 w-4 fill-none stroke-current">
                    <path d="m5 12 4 4L19 6" stroke-width="2"></path>
                </svg>
                Scan Complete
            `;

            startScannerBtn.classList.replace('bg-primary', 'bg-success');

            if (parcelStatus) {
                parcelStatus.textContent = 'PICKED_UP';
                parcelStatus.classList.replace('text-warning', 'text-success');
            }

            pickedUpStep?.classList.add('bg-success/20');
            sortingStep?.classList.add('bg-white/15');

            if (confirmPickupBtn) confirmPickupBtn.disabled = false;

        }, 1200);

    });


    /*
    |--------------------------------------------------------------------------
    | Scan Page — Confirm Pickup
    |--------------------------------------------------------------------------
    */

    confirmPickupBtn?.addEventListener('click', function () {

        if (this.disabled) return;

        this.disabled = true;

        this.innerHTML = `
            <svg viewBox="0 0 24 24" class="h-4 w-4 animate-spin fill-none stroke-current">
                <circle cx="12" cy="12" r="9" stroke-width="1.5" class="opacity-30"></circle>
                <path d="M21 12a9 9 0 0 1-9 9" stroke-width="1.5"></path>
            </svg>
            Confirming...
        `;

        setTimeout(() => {

            this.innerHTML = `
                <svg viewBox="0 0 24 24" class="h-4 w-4 fill-none stroke-current">
                    <path d="m5 12 4 4L19 6" stroke-width="2"></path>
                </svg>
                Pickup Confirmed
            `;

            setTimeout(() => {
                window.location.href = window.likhaeRoutes?.pickupsIndex ?? '/rider/pickups';
            }, 500);

        }, 700);

    });


    /*
    |--------------------------------------------------------------------------
    | Delivery Tracking — Open Navigation
    |--------------------------------------------------------------------------
    */

    const openRouteBtn = document.getElementById('openRouteBtn');

    openRouteBtn?.addEventListener('click', function () {

        const address = window.likhaeDelivery?.customerAddress ?? '';

        window.open(
            'https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(address),
            '_blank',
            'noopener,noreferrer'
        );

    });


    /*
    |--------------------------------------------------------------------------
    | Delivery Tracking — Contact Customer
    |--------------------------------------------------------------------------
    */

    document.getElementById('contactCustomerBtn')?.addEventListener('click', function () {
        window.location.href = 'tel:' + (window.likhaeDelivery?.customerPhone ?? '');
    });


    /*
    |--------------------------------------------------------------------------
    | Delivery Tracking — Arrived At Customer
    |--------------------------------------------------------------------------
    */

    document.getElementById('arrivedCustomerBtn')?.addEventListener('click', function () {

        this.textContent = 'Customer Reached ✓';
        this.disabled    = true;

        this.classList.remove('border-line', 'text-ink');
        this.classList.add('border-success', 'bg-success-soft', 'text-success');

    });


    /*
    |--------------------------------------------------------------------------
    | Delivery Tracking — Mark Delivered
    |--------------------------------------------------------------------------
    */

    document.getElementById('markDeliveredBtn')?.addEventListener('click', function () {

        this.textContent = 'Delivered ✓';
        this.disabled    = true;
        this.classList.replace('bg-success', 'bg-success-soft');
        this.classList.add('text-success');

        const badge = document.querySelector('section:first-of-type .bg-primary-soft');

        if (badge) {
            badge.classList.replace('bg-primary-soft', 'bg-success-soft');
            badge.classList.replace('text-primary', 'text-success');
            badge.innerHTML = '<span class="h-1.5 w-1.5 rounded-full bg-success"></span> DELIVERED';
        }

    });


    /*
    |--------------------------------------------------------------------------
    | Delivery Tracking — Delivery Failed
    |--------------------------------------------------------------------------
    */

    document.getElementById('failedDeliveryBtn')?.addEventListener('click', function () {

        this.textContent = 'Delivery Marked Failed';
        this.disabled    = true;
        this.classList.replace('bg-danger', 'bg-danger-soft');
        this.classList.add('text-danger');

    });


});
