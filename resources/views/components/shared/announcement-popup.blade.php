@if($announcement && $notification)
    <div class="lk-announcement-backdrop" data-announcement-popup role="dialog" aria-modal="true" aria-labelledby="lk-announcement-title" aria-describedby="lk-announcement-message">
        <section class="lk-announcement-card" @if($themeColor) style="--lk-announcement-accent: {{ $themeColor }}" @endif>
            @if($imageUrl)
                <div class="lk-announcement-image-wrap">
                    <img src="{{ $imageUrl }}" alt="{{ $announcement->image_alt ?: $announcement->title }}" class="lk-announcement-image">
                </div>
            @endif

            <div class="lk-announcement-accent" aria-hidden="true"></div>
            <form method="POST" action="{{ route('notifications.announcements.dismiss', $notification) }}" class="lk-announcement-content" data-announcement-form>
                @csrf
                <input type="hidden" name="return_to" value="{{ request()->getRequestUri() }}">
                <button type="submit" class="lk-announcement-close" aria-label="Close announcement" data-announcement-dismiss formnovalidate>×</button>
                <span class="lk-announcement-kicker">Announcement</span>
                <h2 id="lk-announcement-title">{{ $announcement->title }}</h2>
                <p id="lk-announcement-message">{{ $announcement->body }}</p>
                <div class="lk-announcement-actions">
                    <button type="submit" class="lk-announcement-dismiss" data-announcement-dismiss formnovalidate>Not now</button>
                    @if($ctaUrl)
                        <button type="submit" class="lk-announcement-cta" formaction="{{ route('notifications.announcements.open', $notification) }}" formnovalidate>
                            {{ $announcement->cta_label }} <span aria-hidden="true">→</span>
                        </button>
                    @endif
                </div>
            </form>
        </section>
    </div>
    <script>
        (() => {
            const popup = document.querySelector('[data-announcement-popup]');
            const form = popup?.querySelector('[data-announcement-form]');
            const closeButton = popup?.querySelector('.lk-announcement-close');
            if (!popup || !form || !closeButton) return;

            const dismiss = async (event) => {
                event.preventDefault();
                const submitter = event.currentTarget instanceof HTMLButtonElement
                    ? event.currentTarget
                    : closeButton;

                try {
                    const response = await fetch(form.action, {
                        method: 'POST',
                        body: new FormData(form),
                        credentials: 'same-origin',
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                    });
                    if (!response.ok) throw new Error('Announcement dismissal failed.');

                    popup.remove();
                    const main = document.querySelector('main');
                    if (main) {
                        main.setAttribute('tabindex', '-1');
                        main.focus({preventScroll: true});
                    }
                } catch {
                    form.requestSubmit(submitter || closeButton);
                }
            };

            popup.querySelectorAll('[data-announcement-dismiss]').forEach((button) => {
                button.addEventListener('click', dismiss);
            });
            closeButton.focus();
            popup.addEventListener('keydown', (event) => {
                const buttons = [...popup.querySelectorAll('button:not([disabled])')];
                if (event.key === 'Escape') {
                    dismiss(event);
                    return;
                }
                if (event.key !== 'Tab' || buttons.length === 0) return;
                const first = buttons[0];
                const last = buttons[buttons.length - 1];
                if (event.shiftKey && document.activeElement === first) {
                    event.preventDefault();
                    last.focus();
                } else if (!event.shiftKey && document.activeElement === last) {
                    event.preventDefault();
                    first.focus();
                }
            });
        })();
    </script>
@endif
