document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-payout-destination-form]').forEach((form) => {
        const type = form.querySelector('[data-payout-account-type]');
        const number = form.querySelector('[data-payout-account-number]');
        const label = form.querySelector('[data-payout-number-label]');
        const help = form.querySelector('[data-payout-number-help]');
        const method = form.querySelector('[name="payout_method"]');
        const bankField = form.querySelector('[data-payout-bank-field]');
        const bankName = form.querySelector('[name="payout_bank_name"]');

        if (!method && !bankField && !type) return;

        const update = () => {
            if (!type || !number || !label || !help) return;

            const isMobile = type.value === 'MOBILE_NUMBER';
            label.textContent = isMobile ? 'Mobile number' : 'Account number';
            number.type = isMobile ? 'tel' : 'text';
            number.inputMode = isMobile ? 'tel' : 'numeric';
            number.autocomplete = isMobile ? 'tel' : 'off';
            number.placeholder = isMobile ? '09XX XXX XXXX' : '6-20 digits';
            help.textContent = isMobile
                ? 'Use 09XX XXX XXXX or +63 9XX XXX XXXX.'
                : 'Use 6 to 20 digits. Spaces and dashes are accepted.';
        };

        const updateFromMethod = () => {
            const isBankTransfer = method?.value === 'Bank Transfer';

            if (bankField) bankField.hidden = !isBankTransfer;
            if (bankName) {
                bankName.required = isBankTransfer;
                bankName.disabled = !isBankTransfer;
            }

            if (!method) {
                update();
                return;
            }

            if (type) {
                if (['GCash', 'Maya'].includes(method.value)) {
                    type.value = 'MOBILE_NUMBER';
                } else if (method.value === 'Bank Transfer') {
                    type.value = 'ACCOUNT_NUMBER';
                }
            }

            update();
        };

        type?.addEventListener('change', update);
        method?.addEventListener('change', updateFromMethod);
        updateFromMethod();
    });
});
