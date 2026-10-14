<dialog class="lk-confirm-dialog" data-shared-confirm-dialog aria-labelledby="sharedConfirmTitle" aria-describedby="sharedConfirmMessage">
    <div class="lk-confirm-card">
        <div class="lk-confirm-heading">
            <span class="lk-confirm-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none">
                    <path d="M12 3.75 14.3 9.7l5.95 2.3-5.95 2.3L12 20.25 9.7 14.3l-5.95-2.3 5.95-2.3L12 3.75Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/>
                    <path d="m9.4 12.2 1.65 1.65 3.55-3.7" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </span>
            <div class="lk-confirm-title-wrap">
                <span class="lk-confirm-eyebrow">LIKHAE <i aria-hidden="true"></i> ACTION CONFIRMATION</span>
                <h2 id="sharedConfirmTitle" data-shared-confirm-title>Confirm action</h2>
            </div>
        </div>
        <p id="sharedConfirmMessage" data-shared-confirm-message>Please review this action before continuing.</p>
        <div class="lk-confirm-actions">
            <button type="button" class="lk-confirm-cancel" data-shared-confirm-cancel>Cancel</button>
            <button type="button" class="lk-confirm-accept" data-shared-confirm-accept><span data-shared-confirm-label>Continue</span><svg viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M4 10h11m-4-4 4 4-4 4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg></button>
        </div>
    </div>
</dialog>
@once
    <style>
        .lk-confirm-dialog {
            --confirm-surface: var(--lk-card, var(--sl-card, var(--ad-card, var(--lk-surface, #fffdf9))));
            --confirm-text: var(--lk-text, var(--sl-text, var(--ad-text, #3b211b)));
            --confirm-muted: var(--lk-muted, var(--sl-muted, var(--ad-muted, #987865)));
            --confirm-border: var(--lk-border, var(--sl-border, var(--ad-border, #eadccc)));
            --confirm-brand: var(--lk-maroon, var(--sl-maroon, var(--ad-maroon, var(--lk-primary, #561c17))));
            --confirm-brand-hover: var(--lk-maroon-dark, var(--sl-maroon-dark, var(--ad-maroon-dark, var(--lk-primary-hover, #642920))));
            --confirm-soft: var(--lk-red-soft, var(--sl-blue-soft, var(--ad-blue-soft, var(--lk-primary-soft, #f3e4de))));
            --confirm-danger: var(--lk-danger, var(--sl-danger, var(--ad-danger, #b42318)));
            --confirm-danger-soft: var(--lk-danger-soft, var(--sl-danger-soft, var(--ad-danger-soft, #fcebe9)));
            position: fixed;
            inset: 0;
            width: min(460px, calc(100% - 32px));
            max-width: none;
            max-height: calc(100dvh - 32px);
            height: fit-content;
            box-sizing: border-box;
            margin: auto;
            padding: 0;
            overflow: auto;
            border: 1px solid var(--confirm-border);
            border-radius: 22px;
            color: var(--confirm-text);
            background: var(--confirm-surface);
            box-shadow: 0 28px 90px rgba(35, 20, 17, .28);
            font-family: inherit;
        }
        .lk-confirm-dialog::before {
            display: block;
            height: 4px;
            background: linear-gradient(90deg, var(--confirm-brand), var(--lk-tan, var(--sl-tan, #c19771)), var(--confirm-brand));
            content: "";
        }
        .lk-confirm-dialog::backdrop {
            background: var(--lk-overlay, rgba(28, 22, 15, .58));
            backdrop-filter: blur(5px);
        }
        .lk-confirm-card { padding: 30px 32px 28px; text-align: center; }
        .lk-confirm-heading { display: flex; flex-direction: column; align-items: center; gap: 15px; }
        .lk-confirm-icon {
            display: grid;
            width: 58px;
            height: 58px;
            flex: 0 0 58px;
            place-items: center;
            border: 1px solid var(--confirm-border);
            border-radius: 19px;
            color: var(--confirm-brand);
            background: linear-gradient(145deg, var(--confirm-soft), var(--confirm-surface));
            box-shadow: 0 7px 18px rgba(86, 28, 23, .08);
        }
        .lk-confirm-icon svg { width: 27px; height: 27px; }
        .lk-confirm-title-wrap { min-width: 0; }
        .lk-confirm-eyebrow {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            color: var(--confirm-muted);
            font-size: 9px;
            font-weight: 700;
            letter-spacing: .13em;
            line-height: 1.3;
        }
        .lk-confirm-eyebrow i { width: 3px; height: 3px; border-radius: 50%; background: var(--lk-tan, var(--sl-tan, #c19771)); }
        .lk-confirm-card h2 { margin: 7px 0 0; font-size: 21px; font-weight: 750; letter-spacing: -.03em; line-height: 1.3; }
        .lk-confirm-card > p { max-width: 38ch; margin: 13px auto 0; color: var(--confirm-muted); font-size: 13px; line-height: 1.7; }
        .lk-confirm-actions {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin-top: 25px;
            padding-top: 19px;
            border-top: 1px solid var(--confirm-border);
        }
        .lk-confirm-actions button {
            display: inline-flex;
            min-width: 128px;
            min-height: 44px;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 10px 17px;
            border: 1px solid transparent;
            border-radius: 12px;
            font: inherit;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            transition: background .16s ease, border-color .16s ease, box-shadow .16s ease, transform .16s ease;
        }
        .lk-confirm-actions button:hover { transform: translateY(-1px); }
        .lk-confirm-cancel { border-color: var(--confirm-border) !important; color: var(--confirm-text); background: var(--confirm-surface); }
        .lk-confirm-cancel:hover { background: var(--confirm-soft); }
        .lk-confirm-accept { color: #fff; background: var(--confirm-brand); box-shadow: 0 5px 13px rgba(86, 28, 23, .17); }
        .lk-confirm-accept:hover { background: var(--confirm-brand-hover); }
        .lk-confirm-accept svg { width: 16px; height: 16px; }
        .lk-confirm-dialog.is-danger .lk-confirm-icon {
            border-color: var(--lk-danger-border, var(--sl-danger-border, #f0c9c4));
            color: var(--confirm-danger);
            background: linear-gradient(145deg, var(--confirm-danger-soft), var(--confirm-surface));
        }
        .lk-confirm-dialog.is-danger .lk-confirm-accept { background: var(--confirm-danger); box-shadow: 0 5px 13px rgba(180, 35, 24, .16); }
        .lk-confirm-dialog.is-danger .lk-confirm-accept:hover { filter: brightness(.92); }
        .lk-confirm-actions button:focus-visible { outline: 3px solid color-mix(in srgb, var(--confirm-brand) 30%, transparent); outline-offset: 2px; }
        @media (max-width: 480px) {
            .lk-confirm-card { padding: 25px 21px 22px; }
            .lk-confirm-actions { flex-direction: column-reverse; }
            .lk-confirm-actions button { width: 100%; }
        }
        @media (prefers-reduced-motion: reduce) {
            .lk-confirm-actions button { transition: none; }
            .lk-confirm-actions button:hover { transform: none; }
        }
    </style>
    <script>
        (() => {
            const dialog = document.querySelector('[data-shared-confirm-dialog]');
            if (!dialog || typeof dialog.showModal !== 'function') return;

            const title = dialog.querySelector('[data-shared-confirm-title]');
            const message = dialog.querySelector('[data-shared-confirm-message]');
            const acceptButton = dialog.querySelector('[data-shared-confirm-accept]');
            const acceptLabel = dialog.querySelector('[data-shared-confirm-label]');
            const cancelButton = dialog.querySelector('[data-shared-confirm-cancel]');
            let pendingForm = null;
            let pendingSubmitter = null;

            document.addEventListener('submit', (event) => {
                const form = event.target;
                if (!(form instanceof HTMLFormElement)) return;
                if (form.dataset.sharedConfirmApproved === 'true') {
                    delete form.dataset.sharedConfirmApproved;
                    return;
                }

                const submitter = event.submitter;
                const config = submitter?.dataset.confirmMessage !== undefined ? submitter.dataset : form.dataset;
                if (!config.confirmMessage) return;

                event.preventDefault();
                pendingForm = form;
                pendingSubmitter = submitter;
                title.textContent = config.confirmTitle || 'Confirm action';
                message.textContent = config.confirmMessage;
                acceptLabel.textContent = config.confirmLabel || 'Continue';
                dialog.classList.toggle('is-danger', config.confirmTone === 'danger');
                dialog.showModal();
                acceptButton.focus();
            }, true);

            const clearPending = () => {
                pendingForm = null;
                pendingSubmitter = null;
            };
            cancelButton.addEventListener('click', () => dialog.close('cancel'));
            acceptButton.addEventListener('click', () => {
                if (!pendingForm) return dialog.close('cancel');
                const form = pendingForm;
                const submitter = pendingSubmitter;
                const config = submitter?.dataset.confirmMessage !== undefined ? submitter.dataset : form.dataset;
                const field = config.confirmSetField ? form.elements.namedItem(config.confirmSetField) : null;
                if (field) field.value = '1';
                clearPending();
                dialog.close('confirm');
                form.dataset.sharedConfirmApproved = 'true';
                form.requestSubmit(submitter || undefined);
            });
            dialog.addEventListener('close', clearPending);
        })();
    </script>
@endonce
