import '../../css/Buyer/hero-carousel.css';

document.querySelectorAll('[data-hero-carousel]').forEach((carousel) => {
    const slides = [...carousel.querySelectorAll('[data-hero-slide]')];
    const dots = [...carousel.querySelectorAll('[data-hero-dot]')];
    const pause = carousel.querySelector('[data-hero-pause]');
    const motion = window.matchMedia('(prefers-reduced-motion: reduce)');
    let current = 0;
    let paused = motion.matches;
    let timer;
    let touchStart = null;
    const loadSlideImage = (slide, priority = 'low') => {
        const image = slide.querySelector('.lk-carousel__image');
        if (!image) return;

        image.loading = 'eager';
        image.fetchPriority = priority;
    };

    const schedule = () => {
        window.clearTimeout(timer);
        const focusedSlide = slides.some((slide) => slide.contains(document.activeElement));
        if (!paused && !document.hidden && !focusedSlide) {
            timer = window.setTimeout(() => show(current + 1), 5000);
        }
    };
    const show = (index, announce = false) => {
        current = (index + slides.length) % slides.length;
        loadSlideImage(slides[current], 'high');
        slides.forEach((slide, i) => {
            slide.classList.toggle('is-active', i === current);
            slide.setAttribute('aria-hidden', String(i !== current));
            slide.inert = i !== current;
            dots[i].setAttribute('aria-current', String(i === current));
        });
        carousel.querySelector('[data-hero-count]').textContent = String(current + 1).padStart(2, '0');
        if (announce) carousel.querySelector('[data-hero-status]').textContent = `Slide ${current + 1} of ${slides.length}: ${slides[current].querySelector('.lk-carousel__title').textContent}`;
        schedule();
    };
    const updatePause = () => {
        pause.textContent = paused ? 'Play' : 'Pause';
        pause.setAttribute('aria-label', paused ? 'Play slideshow' : 'Pause slideshow');
        schedule();
    };
    dots.forEach((dot, i) => dot.addEventListener('click', () => show(i, true)));
    carousel.querySelector('[data-hero-prev]').addEventListener('click', () => show(current - 1, true));
    carousel.querySelector('[data-hero-next]').addEventListener('click', () => show(current + 1, true));
    pause.addEventListener('click', () => { paused = !paused; updatePause(); });
    carousel.addEventListener('focusin', schedule);
    carousel.addEventListener('focusout', () => window.setTimeout(schedule, 0));
    carousel.addEventListener('keydown', (event) => {
        if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return;
        event.preventDefault();
        const next = (current + (event.key === 'ArrowRight' ? 1 : -1) + slides.length) % slides.length;
        dots[next].focus();
        show(next, true);
    });
    carousel.addEventListener('touchstart', (event) => {
        touchStart = { x: event.touches[0].clientX, y: event.touches[0].clientY };
    }, { passive: true });
    carousel.addEventListener('touchend', (event) => {
        if (!touchStart) return;
        const dx = event.changedTouches[0].clientX - touchStart.x;
        const dy = event.changedTouches[0].clientY - touchStart.y;
        if (Math.abs(dx) > 50 && Math.abs(dx) > Math.abs(dy)) show(current + (dx < 0 ? 1 : -1), true);
        touchStart = null;
    }, { passive: true });
    carousel.addEventListener('touchcancel', () => { touchStart = null; });
    document.addEventListener('visibilitychange', schedule);
    motion.addEventListener('change', () => { paused = motion.matches; updatePause(); });
    carousel.querySelector('.lk-carousel__controls').hidden = false;
    updatePause();
});
