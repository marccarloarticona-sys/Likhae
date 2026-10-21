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
        <p class="lk-confirm-details" data-shared-confirm-details hidden></p>
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
        .lk-confirm-card > .lk-confirm-details { max-width: 46ch; white-space: pre-line; border: 1px solid var(--confirm-border); border-radius: 12px; padding: 11px 13px; color: var(--confirm-text); background: var(--confirm-soft); text-align: left; }
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
        .lk-confirm-dialog {
            width: min(420px, calc(100% - 28px)); max-height: calc(100dvh - 28px); border-radius: 24px;
            background: radial-gradient(ellipse 42% 75% at -8% -28%,rgba(208,177,151,.17) 0 65%,transparent 66%), radial-gradient(ellipse 48% 76% at 112% 128%,rgba(208,177,151,.16) 0 65%,transparent 66%), linear-gradient(145deg,var(--lk-modal-paper,#fffaf3),var(--lk-modal-cream,#fbf5ec));
            box-shadow: 0 38px 110px rgba(35,20,17,.3);
        }
        .lk-confirm-dialog::before { height: 0; }
        .lk-confirm-card { padding: 25px 26px 24px; }
        .lk-confirm-heading { gap: 11px; }
        .lk-confirm-icon { width: 50px; height: 50px; flex-basis: 50px; border-radius: 50%; color: var(--lk-modal-ink,#4b211b); background: linear-gradient(145deg,rgba(255,255,255,.76),rgba(247,235,220,.8)); box-shadow: 0 0 0 7px rgba(213,190,168,.13),0 7px 16px rgba(86,47,33,.1); }
        .lk-confirm-icon svg { width: 23px; height: 23px; }
        .lk-confirm-eyebrow { gap: 9px; color: var(--lk-modal-muted,#927260); font-size: 9px; letter-spacing: .2em; }
        .lk-confirm-eyebrow::before,.lk-confirm-eyebrow::after { width: clamp(24px,6vw,58px); height: 1px; background: var(--lk-modal-accent,#b98e70); content: ""; }
        .lk-confirm-eyebrow i { display: none; }
        .lk-confirm-title-wrap { display: grid; gap: 10px; }
        .lk-confirm-card h2 { margin-top: 0; color: var(--lk-modal-ink,#4b211b); font-family: "Playfair Display",Georgia,"Times New Roman",serif; font-size: clamp(30px,4vw,38px); font-weight: 600; letter-spacing: -.045em; line-height: 1.08; }
        .lk-confirm-card > p { max-width: 52ch; margin-top: 8px; color: var(--lk-modal-muted,#927260); font-size: 14px; line-height: 1.5; }
        .lk-confirm-card > .lk-confirm-details { padding: 15px 18px; border-color: var(--lk-modal-line,#e8d6c5); border-radius: 16px; color: var(--lk-modal-ink,#4b211b); background: rgba(255,250,243,.72); font-size: 14px; }
        .lk-confirm-actions { gap: 10px; margin-top: 18px; padding-top: 0; border: 0; }
        .lk-confirm-actions button { min-width: min(140px,42%); min-height: 44px; padding: 10px 16px; border-radius: 999px; font-size: 13px; }
        .lk-confirm-cancel { border-color: var(--lk-modal-accent,#b98e70) !important; color: var(--lk-modal-ink,#4b211b); background: transparent; }
        .lk-confirm-accept { color: #fffaf6; background: linear-gradient(110deg,#56231d,#70352c); box-shadow: 0 12px 25px rgba(86,35,29,.18) !important; }
        .lk-confirm-accept svg { width: 18px; height: 18px; }
        .lk-confirm-dialog::backdrop { background: rgba(53,35,27,.42); backdrop-filter: blur(8px) saturate(.8); }
        @media (max-width: 600px) {
            .lk-confirm-dialog { width: calc(100% - 24px); border-radius: 25px; }
            .lk-confirm-card { padding: 25px 18px 20px; }
            .lk-confirm-icon { width: 50px; height: 50px; flex-basis: 50px; }
            .lk-confirm-icon svg { width: 23px; height: 23px; }
            .lk-confirm-eyebrow { gap: 6px; font-size: 8px; letter-spacing: .16em; }
            .lk-confirm-actions { flex-direction: column-reverse; gap: 8px; margin-top: 16px; }
            .lk-confirm-actions button { width: 100%; min-width: 0; min-height: 44px; font-size: 13px; }
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
            const details = dialog.querySelector('[data-shared-confirm-details]');
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

                const prepareEvent = new CustomEvent('shared-confirm:prepare', { cancelable: true });
                form.dispatchEvent(prepareEvent);
                if (prepareEvent.defaultPrevented) {
                    event.preventDefault();
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
                details.textContent = config.confirmDetails || '';
                details.hidden = !config.confirmDetails;
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
