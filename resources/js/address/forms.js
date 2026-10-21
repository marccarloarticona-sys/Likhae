import { initPostalAddressForm } from './postal-code.js';

const initializePostalAddressForms = () => {
    document.querySelectorAll('[data-postal-address]').forEach(initPostalAddressForm);
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializePostalAddressForms, { once: true });
} else {
    initializePostalAddressForms();
}
