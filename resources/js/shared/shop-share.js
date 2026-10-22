const fallbackCopy = (text) => {
    const field = document.createElement('textarea');
    field.value = text;
    field.setAttribute('readonly', '');
    field.style.position = 'fixed';
    field.style.opacity = '0';
    document.body.append(field);
    field.select();
    const copied = document.execCommand('copy');
    field.remove();

    return copied;
};

document.querySelectorAll('[data-share-link]').forEach((button) => {
    button.addEventListener('click', async () => {
        const url = button.dataset.shareUrl || '';
        const title = button.dataset.shareTitle || 'LIKHAE';
        const label = button.dataset.shareLabel || 'Link';
        const feedback = button.parentElement?.parentElement?.querySelector('[data-share-feedback]')
            || document.querySelector('[data-share-feedback]');
        const announce = (message, failed = false) => {
            if (!feedback) return;
            feedback.textContent = message;
            feedback.hidden = false;
            feedback.classList.toggle('text-red-700', failed);
            feedback.classList.toggle('text-emerald-700', !failed);
        };

        if (!url) {
            announce(`The ${label.toLowerCase()} link is unavailable right now.`, true);
            return;
        }

        if (navigator.share) {
            try {
                await navigator.share({ title: `${title} on LIKHAE`, text: `View ${title} on LIKHAE`, url });
                announce(`${label} link shared.`);
            } catch (error) {
                announce(error?.name === 'AbortError' ? 'Sharing cancelled.' : `Unable to share the ${label.toLowerCase()} link.`, error?.name !== 'AbortError');
            }
            return;
        }

        try {
            if (navigator.clipboard?.writeText) {
                await navigator.clipboard.writeText(url);
            } else if (!fallbackCopy(url)) {
                throw new Error('Clipboard copy failed.');
            }
            announce(`${label} link copied to clipboard.`);
        } catch {
            announce(`Could not copy the ${label.toLowerCase()} link. Copy the address from your browser.`, true);
        }
    });
});
