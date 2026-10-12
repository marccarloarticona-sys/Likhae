document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-password-toggle]').forEach((button) => {
        const input = document.getElementById(button.dataset.passwordToggle);

        if (!input) {
            return;
        }

        button.addEventListener('click', () => {
            const showing = input.type === 'password';
            input.type = showing ? 'text' : 'password';
            button.setAttribute('aria-label', showing ? 'Hide password' : 'Show password');
            button.setAttribute('aria-pressed', showing ? 'true' : 'false');
        });
    });

    document.querySelectorAll('[data-recovery-form]').forEach((form) => {
        const submitButton = form.querySelector('.auth-submit');

        form.addEventListener('submit', () => {
            if (!submitButton || submitButton.disabled) {
                return;
            }

            submitButton.disabled = true;
            submitButton.classList.add('is-loading');
            submitButton.dataset.originalHtml = submitButton.innerHTML;
            submitButton.innerHTML = `<span class="auth-spinner" aria-hidden="true"></span><span>${submitButton.dataset.loadingText || 'Working...'}</span>`;
        });

        window.addEventListener('pageshow', (event) => {
            if (!event.persisted || !submitButton) {
                return;
            }

            submitButton.disabled = false;
            submitButton.classList.remove('is-loading');
            if (submitButton.dataset.originalHtml) {
                submitButton.innerHTML = submitButton.dataset.originalHtml;
            }
        });
    });
});
