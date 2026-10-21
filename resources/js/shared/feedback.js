const createToast = (region, message, type = 'success') => {
    if (!region || !message) return;

    region.querySelector('[data-feedback-transient]')?.remove();
    const toast = document.createElement('div');
    toast.className = `lk-feedback-toast is-visible is-${type}`;
    toast.dataset.feedbackTransient = '';
    toast.setAttribute('role', type === 'error' ? 'alert' : 'status');

    const icon = document.createElement('span');
    icon.className = 'lk-feedback-toast__icon';
    icon.setAttribute('aria-hidden', 'true');
    icon.textContent = type === 'error' ? '!' : (type === 'info' ? 'i' : '✓');

    const copy = document.createElement('span');
    copy.className = 'lk-feedback-toast__message';
    copy.textContent = message;

    const close = document.createElement('button');
    close.className = 'lk-feedback-toast__close';
    close.type = 'button';
    close.dataset.feedbackDismiss = '';
    close.setAttribute('aria-label', 'Dismiss message');
    close.textContent = '×';
    close.addEventListener('click', () => toast.remove());

    toast.append(icon, copy, close);
    region.append(toast);
    if (type !== 'error') window.setTimeout(() => toast.remove(), 3600);
};

const feedback = {
    show(message, type = 'success') {
        createToast(document.querySelector('[data-feedback-region]'), message, type);
    },
};
window.lkFeedback = feedback;

document.addEventListener('click', (event) => {
    const dismiss = event.target.closest('[data-feedback-dismiss]');
    dismiss?.closest('.lk-feedback-toast')?.remove();
});

document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement)
        || event.defaultPrevented
        || form.hasAttribute('data-no-feedback-loading')
        || form.method.toLowerCase() === 'dialog'
        || form.target) return;
    if (form.dataset.feedbackSubmitting === 'true') {
        event.preventDefault();
        return;
    }

    const submitter = event.submitter instanceof HTMLElement
        ? event.submitter
        : form.querySelector('button[type="submit"], button:not([type]), input[type="submit"]');
    if (!submitter || submitter.getAttribute('aria-disabled') === 'true') return;

    form.dataset.feedbackSubmitting = 'true';
    submitter.dataset.feedbackOriginalMarkup = submitter.innerHTML;
    submitter.dataset.feedbackOriginalValue = submitter instanceof HTMLInputElement ? submitter.value : '';
    submitter.setAttribute('aria-disabled', 'true');
    submitter.setAttribute('aria-busy', 'true');
    submitter.classList.add('lk-feedback-submit');

    const loadingText = submitter.dataset.loadingText || 'Working…';
    const content = document.createElement('span');
    content.className = 'lk-feedback-submit-content';
    const spinner = document.createElement('span');
    spinner.className = 'lk-feedback-spinner';
    spinner.setAttribute('aria-hidden', 'true');
    const label = document.createElement('span');
    label.textContent = loadingText;
    content.append(spinner, label);

    if (submitter instanceof HTMLInputElement) submitter.value = loadingText;
    else submitter.replaceChildren(content);

    window.setTimeout(() => {
        if (form.isConnected && form.dataset.feedbackSubmitting === 'true') {
            form.dataset.feedbackSubmitting = 'false';
            submitter.removeAttribute('aria-disabled');
            submitter.removeAttribute('aria-busy');
            submitter.classList.remove('lk-feedback-submit');
            if (submitter instanceof HTMLInputElement) submitter.value = submitter.dataset.feedbackOriginalValue || '';
            else if (submitter.isConnected) submitter.innerHTML = submitter.dataset.feedbackOriginalMarkup || '';
        }
    }, 30000);
});

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-feedback-flash]').forEach((toast) => {
        const message = toast.querySelector('.lk-feedback-toast__message')?.textContent?.replace(/\s+/g, ' ').trim();
        if (message) {
            const region = toast.closest('[data-feedback-region]');
            const duplicates = [...document.querySelectorAll('.sl-alert-success, .sl-compliance-feedback, .sl-card, .ad-note, .ad-card, [role="status"], .lk-status-message')]
                .filter((element) => !region?.contains(element) && element.textContent.replace(/\s+/g, ' ').trim() === message);
            duplicates.forEach((element) => {
                if (!duplicates.some((parent) => parent !== element && parent.contains(element))) element.remove();
            });
        }
        if (toast.classList.contains('is-error')) return;
        window.setTimeout(() => toast.remove(), 5200);
    });
});
