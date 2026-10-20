const EDGE_GAP = 14;
const DRAG_THRESHOLD = 6;

export function makeAiWidgetDraggable(widget, head, windowEl, storageKey) {
    let initialized = false;
    let dragging = false;
    let suppressClick = false;
    let pointerStart = null;
    let suppressClickTimer = null;

    const clamp = (value, min, max) => Math.min(Math.max(value, min), max);

    const savePosition = () => {
        const rect = widget.getBoundingClientRect();
        try {
            localStorage.setItem(storageKey, JSON.stringify({ left: rect.left, top: rect.top }));
        } catch (_) {}
    };

    const setPosition = (left, top, persist = false) => {
        const rect = widget.getBoundingClientRect();
        const maxLeft = Math.max(EDGE_GAP, window.innerWidth - rect.width - EDGE_GAP);
        const maxTop = Math.max(EDGE_GAP, window.innerHeight - rect.height - EDGE_GAP);
        widget.style.right = 'auto';
        widget.style.bottom = 'auto';
        widget.style.left = `${clamp(left, EDGE_GAP, maxLeft)}px`;
        widget.style.top = `${clamp(top, EDGE_GAP, maxTop)}px`;
        if (persist) savePosition();
        placeWindow();
    };

    const placeWindow = () => {
        if (windowEl.hidden) return;

        const headRect = head.getBoundingClientRect();
        const windowRect = windowEl.getBoundingClientRect();
        const width = windowRect.width;
        const height = windowRect.height;
        const onRightSide = headRect.left + headRect.width / 2 >= window.innerWidth / 2;
        const left = onRightSide ? headRect.right - width : headRect.left;
        const roomAbove = headRect.top - EDGE_GAP;
        const top = roomAbove >= height + EDGE_GAP
            ? headRect.top - height - EDGE_GAP
            : headRect.bottom + EDGE_GAP;

        windowEl.style.position = 'fixed';
        windowEl.style.right = 'auto';
        windowEl.style.bottom = 'auto';
        windowEl.style.left = `${clamp(left, EDGE_GAP, window.innerWidth - width - EDGE_GAP)}px`;
        windowEl.style.top = `${clamp(top, EDGE_GAP, window.innerHeight - height - EDGE_GAP)}px`;
    };

    const refresh = () => {
        if (widget.hidden || !widget.getClientRects().length) return;

        const rect = widget.getBoundingClientRect();
        if (!initialized) {
            let saved = null;
            try {
                saved = JSON.parse(localStorage.getItem(storageKey) || 'null');
            } catch (_) {}

            initialized = true;
            setPosition(
                Number.isFinite(saved?.left) ? saved.left : rect.left,
                Number.isFinite(saved?.top) ? saved.top : rect.top,
            );
            return;
        }

        setPosition(rect.left, rect.top);
    };

    const dragHandle = (handle, suppressHandleClick = false) => {
        if (!handle) return;

        handle.addEventListener('pointerdown', (event) => {
            if (event.button !== 0 || event.isPrimary === false) return;
            const interactive = event.target.closest('button, input, textarea, a, [role="button"]');
            if (interactive && interactive !== handle) return;

            if (handle === head) handle.classList.add('is-pressed');
            pointerStart = {
                id: event.pointerId,
                x: event.clientX,
                y: event.clientY,
                left: widget.getBoundingClientRect().left,
                top: widget.getBoundingClientRect().top,
                handle,
                suppressHandleClick,
            };
            handle.setPointerCapture(event.pointerId);
        });

        handle.addEventListener('pointermove', (event) => {
            if (!pointerStart || event.pointerId !== pointerStart.id) return;
            const deltaX = event.clientX - pointerStart.x;
            const deltaY = event.clientY - pointerStart.y;
            if (!dragging && Math.hypot(deltaX, deltaY) < DRAG_THRESHOLD) return;

            dragging = true;
            handle.classList.add('is-dragging');
            event.preventDefault();
            setPosition(pointerStart.left + deltaX, pointerStart.top + deltaY);
        });

        const finishPointer = (event) => {
            if (!pointerStart || event.pointerId !== pointerStart.id) return;
            if (dragging) {
                if (pointerStart.suppressHandleClick) suppressClick = true;
                savePosition();
            }
            pointerStart.handle.classList.remove('is-dragging');
            pointerStart.handle.classList.remove('is-pressed');
            pointerStart = null;
            dragging = false;
            if (suppressClick) {
                clearTimeout(suppressClickTimer);
                suppressClickTimer = setTimeout(() => { suppressClick = false; }, 0);
            }
        };

        handle.addEventListener('pointerup', finishPointer);
        handle.addEventListener('pointercancel', finishPointer);
    };

    dragHandle(head, true);
    dragHandle(windowEl.querySelector('.likhae-workspace-ai-header, .likhae-buyer-ai-header'));
    head.addEventListener('click', (event) => {
        if (!suppressClick) return;
        suppressClick = false;
        event.preventDefault();
        event.stopImmediatePropagation();
    }, true);

    head.addEventListener('keydown', (event) => {
        const offsets = {
            ArrowLeft: [-24, 0],
            ArrowRight: [24, 0],
            ArrowUp: [0, -24],
            ArrowDown: [0, 24],
        };
        const offset = offsets[event.key];
        if (!offset) return;
        event.preventDefault();
        const rect = widget.getBoundingClientRect();
        setPosition(rect.left + offset[0], rect.top + offset[1], true);
    });

    window.addEventListener('resize', () => {
        refresh();
        placeWindow();
    });
    refresh();

    return { refresh, placeWindow };
}
