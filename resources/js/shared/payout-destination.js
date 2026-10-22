document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-payout-destination-form]').forEach((form) => {
        const type = form.querySelector('[data-payout-account-type]');
        const number = form.querySelector('[data-payout-account-number]');
        const label = form.querySelector('[data-payout-number-label]');
        const help = form.querySelector('[data-payout-number-help]');
        const method = form.querySelector('[name="payout_method"]');

        if (!type || !number || !label || !help) return;

        const update = () => {
            const isMobile = type.value === 'MOBILE_NUMBER';
            label.textContent = isMobile ? 'Mobile number' : 'Account number';
            number.type = isMobile ? 'tel' : 'text';
            number.inputMode = 'numeric';
            number.autocomplete = isMobile ? 'tel' : 'off';
            number.placeholder = isMobile ? '09XX XXX XXXX' : '6-20 digits';
            help.textContent = isMobile
                ? 'Use 09XX XXX XXXX or +63 9XX XXX XXXX.'
                : 'Use 6 to 20 digits. Spaces and dashes are accepted.';
        };

        const updateFromMethod = () => {
            if (!method) return;

            if (['GCash', 'Maya'].includes(method.value)) {
                type.value = 'MOBILE_NUMBER';
            } else if (method.value === 'Bank Transfer') {
                type.value = 'ACCOUNT_NUMBER';
            }

            update();
        };

        type.addEventListener('change', update);
        method?.addEventListener('change', updateFromMethod);
        updateFromMethod();
    });
});
