@once
    <style>
        .lk-logout-dialog{width:min(440px,calc(100% - 28px));max-width:none;padding:0;border:1px solid #eadbce;border-radius:18px;background:#fffdf9;color:#321d17;box-shadow:0 24px 80px rgba(35,20,17,.28)}
        .lk-logout-dialog::backdrop{background:rgba(24,15,13,.58);backdrop-filter:blur(3px)}
        .lk-logout-dialog__body{padding:24px}
        .lk-logout-dialog h2{margin:0;font-size:19px;font-weight:800}
        .lk-logout-dialog p{margin:10px 0 22px;color:#705c54;font-size:14px;line-height:1.55}
        .lk-logout-dialog__actions{display:flex;justify-content:flex-end;gap:10px}
        .lk-logout-dialog button{min-height:42px;border:1px solid transparent;border-radius:10px;padding:0 16px;font:inherit;font-size:13px;font-weight:700;cursor:pointer}
        .lk-logout-dialog button:focus-visible{outline:3px solid #d6a05c;outline-offset:3px}
        .lk-logout-dialog__cancel{border-color:#d9c9bd!important;background:#fff;color:#432a22}
        .lk-logout-dialog__confirm{background:#943b30;color:#fff}
        html.dark .lk-logout-dialog{border-color:#4b3831;background:#241b18;color:#f7eee9}
        html.dark .lk-logout-dialog p{color:#c7b5ac}
        html.dark .lk-logout-dialog__cancel{border-color:#66534b!important;background:#30231f;color:#f7eee9}
        @media(max-width:480px){.lk-logout-dialog__body{padding:20px}.lk-logout-dialog__actions{display:grid;grid-template-columns:1fr 1fr}}
    </style>

    <dialog class="lk-logout-dialog" data-logout-dialog aria-labelledby="lkLogoutTitle" aria-describedby="lkLogoutMessage">
        <div class="lk-logout-dialog__body">
            <h2 id="lkLogoutTitle">Confirm Logout</h2>
            <p id="lkLogoutMessage">Are you sure you want to log out of your LIKHAE account?</p>
            <div class="lk-logout-dialog__actions">
                <button type="button" class="lk-logout-dialog__cancel" data-logout-cancel>Cancel</button>
                <button type="button" class="lk-logout-dialog__confirm" data-logout-confirm>Logout</button>
            </div>
        </div>
    </dialog>

    <script>
        (() => {
            const dialog = document.querySelector('[data-logout-dialog]');
            if (!dialog || dialog.dataset.bound === 'true') return;
            dialog.dataset.bound = 'true';

            let pendingForm = null;
            let lastTrigger = null;
            let confirmedForm = null;

            document.addEventListener('submit', (event) => {
                const form = event.target;
                if (!(form instanceof HTMLFormElement)) return;
                const action = new URL(form.action, window.location.href);
                if (action.pathname.replace(/\/+$/, '') !== '/logout') return;
                if (confirmedForm === form) {
                    confirmedForm = null;
                    return;
                }

                event.preventDefault();
                pendingForm = form;
                lastTrigger = document.activeElement;
                if (typeof dialog.showModal === 'function') dialog.showModal();
                else dialog.setAttribute('open', '');
                dialog.querySelector('[data-logout-cancel]')?.focus();
            });

            dialog.querySelector('[data-logout-cancel]')?.addEventListener('click', () => dialog.close('cancel'));
            dialog.querySelector('[data-logout-confirm]')?.addEventListener('click', () => {
                const form = pendingForm;
                pendingForm = null;
                if (!form) return dialog.close('cancel');
                confirmedForm = form;
                dialog.close('confirm');
                if (typeof form.requestSubmit === 'function') form.requestSubmit();
                else {
                    form.submit();
                    confirmedForm = null;
                }
            });
            dialog.addEventListener('close', () => {
                if (dialog.returnValue !== 'confirm') lastTrigger?.focus?.();
                pendingForm = null;
            });
        })();
    </script>
@endonce
