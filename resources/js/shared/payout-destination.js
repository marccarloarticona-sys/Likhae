document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-payout-destination-form]').forEach((form) => {
        const type = form.querySelector('[data-payout-account-type]');
        const number = form.querySelector('[data-payout-account-number]');
        const label = form.querySelector('[data-payout-number-label]');
        const help = form.querySelector('[data-payout-number-help]');
        const method = form.querySelector('[name="payout_method"]');
        const bankField = form.querySelector('[data-payout-bank-field]');
        const bankName = form.querySelector('[name="payout_bank_name"]');
        const action = form.querySelector('[name="destination_action"]');
        const destinationId = form.querySelector('[name="destination_id"]');
        const accountName = form.querySelector('[name="payout_account_name"]');
        const defaultCheckbox = form.querySelector('[data-payout-make-default]');
        const editorTitle = form.querySelector('[data-payout-editor-title]');
        const submitButton = form.querySelector('[data-payout-submit]');
        const cancelEdit = form.querySelector('[data-payout-cancel-edit]');
        const section = form.closest('.payout-section');

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

        if (form.dataset.payoutEditing === '1') {
            if (defaultCheckbox) defaultCheckbox.disabled = form.dataset.payoutEditDefault === '1';
            if (cancelEdit) cancelEdit.hidden = false;
        }

        const beginEdit = (button) => {
            if (!method || !number || !accountName || !type || !action || !destinationId) return;

            action.value = 'update';
            destinationId.value = button.dataset.destinationId || '';
            method.value = button.dataset.payoutMethod || '';
            method.dispatchEvent(new Event('change', { bubbles: true }));
            type.value = button.dataset.payoutAccountType || 'ACCOUNT_NUMBER';
            type.dispatchEvent(new Event('change', { bubbles: true }));
            if (bankName) bankName.value = button.dataset.payoutBankName || '';
            accountName.value = button.dataset.payoutAccountName || '';
            number.value = button.dataset.payoutAccountNumber || '';

            const isDefault = button.dataset.isDefault === '1';
            if (defaultCheckbox) {
                defaultCheckbox.checked = isDefault;
                defaultCheckbox.disabled = isDefault;
            }
            if (editorTitle) editorTitle.textContent = 'Update destination';
            if (submitButton) submitButton.textContent = 'Update payout details';
            if (cancelEdit) cancelEdit.hidden = false;
            form.scrollIntoView({ behavior: 'smooth', block: 'center' });
        };

        section?.querySelectorAll('[data-payout-edit]').forEach((button) => {
            button.addEventListener('click', () => beginEdit(button));
        });

        cancelEdit?.addEventListener('click', () => {
            if (action) action.value = 'add';
            if (destinationId) destinationId.value = '';
            if (method) method.value = '';
            if (type) type.value = 'MOBILE_NUMBER';
            if (bankName) bankName.value = '';
            if (accountName) accountName.value = '';
            if (number) number.value = '';
            if (defaultCheckbox) {
                defaultCheckbox.disabled = false;
                defaultCheckbox.checked = form.dataset.payoutHasDefault !== '1';
            }
            if (editorTitle) editorTitle.textContent = form.dataset.payoutAddTitle || 'Add a destination';
            if (submitButton) submitButton.textContent = 'Save payout details';
            cancelEdit.hidden = true;
            updateFromMethod();
        });
    });
});
