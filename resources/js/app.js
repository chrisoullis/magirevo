const storage = {
    read(key, fallback) {
        try {
            const raw = window.sessionStorage.getItem(key);

            return raw === null ? fallback : JSON.parse(raw);
        } catch {
            return fallback;
        }
    },
    write(key, value) {
        try {
            window.sessionStorage.setItem(key, JSON.stringify(value));
        } catch {
            // Storage can be unavailable (private mode); state then lives only on the page.
        }
    },
};

const template = (text, values) => Object.entries(values)
    .reduce((result, [name, value]) => result.replaceAll(`{${name}}`, String(value)), text ?? '');

/* Ingredient checkboxes: temporary, per-browser-session state that never touches the recipe. */
const ingredientState = (recipeId) => ({
    key: `ingredients:${recipeId}`,
    get checked() {
        return new Set(storage.read(this.key, []));
    },
    save(set) {
        storage.write(this.key, [...set]);
    },
});

const syncIngredients = (root, recipeId) => {
    const checked = ingredientState(recipeId).checked;

    root.querySelectorAll('input[data-ingredient-key]').forEach((input) => {
        input.checked = checked.has(input.dataset.ingredientKey);
    });
    root.querySelectorAll('[data-ingredient-reset]').forEach((button) => {
        button.hidden = checked.size === 0;
    });
};

const initIngredients = () => {
    const panel = () => document.querySelector('[data-ingredients-panel]');
    const recipeId = () => panel()?.dataset.recipeId ?? document.querySelector('[data-cooking-overlay]')?.dataset.recipeId;

    document.addEventListener('change', (event) => {
        const input = event.target;

        if (!(input instanceof HTMLInputElement) || !input.dataset.ingredientKey || !recipeId()) {
            return;
        }

        const state = ingredientState(recipeId());
        const checked = state.checked;

        if (input.checked) {
            checked.add(input.dataset.ingredientKey);
        } else {
            checked.delete(input.dataset.ingredientKey);
        }

        state.save(checked);
        syncIngredients(document, recipeId());
    });

    document.addEventListener('click', (event) => {
        if (!event.target.closest?.('[data-ingredient-reset]') || !recipeId()) {
            return;
        }

        ingredientState(recipeId()).save(new Set());
        syncIngredients(document, recipeId());
    });

    if (!panel()) {
        return;
    }

    syncIngredients(document, recipeId());

    // Livewire re-renders the list when servings change; restore ticks afterwards.
    new MutationObserver(() => syncIngredients(document, recipeId())).observe(panel(), { childList: true, subtree: true });
};

/* Jump to recipe: move focus to the target so keyboard and screen-reader users land on the content. */
const initJump = () => {
    document.querySelectorAll('[data-jump-to-recipe]').forEach((link) => {
        link.addEventListener('click', (event) => {
            const target = document.querySelector(link.getAttribute('href'));

            if (!target) {
                return;
            }

            event.preventDefault();
            const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

            target.scrollIntoView({ behavior: reduceMotion ? 'auto' : 'smooth', block: 'start' });
            target.focus({ preventScroll: true });
            history.replaceState(null, '', link.getAttribute('href'));
        });
    });
};

/* Print: carry the currently selected servings (kept in the URL by the servings component). */
const initPrint = () => {
    document.querySelectorAll('[data-print-link]').forEach((link) => {
        link.addEventListener('click', () => {
            const servings = new URL(window.location.href).searchParams.get('servings');
            const url = new URL(link.href, window.location.origin);

            if (servings) {
                url.searchParams.set('servings', servings);
            } else {
                url.searchParams.delete('servings');
            }

            link.href = url.pathname + url.search;
        });
    });

    document.querySelectorAll('[data-print-now]').forEach((button) => {
        button.addEventListener('click', () => window.print());
    });

    if (document.body.hasAttribute('data-auto-print')) {
        window.addEventListener('load', () => window.setTimeout(() => window.print(), 300));
    }
};

/* Cooking mode */
const initCookingMode = () => {
    const overlay = document.querySelector('[data-cooking-overlay]');
    const start = document.querySelector('[data-cooking-start]');

    if (!overlay || !start) {
        return;
    }

    const labels = overlay.dataset;
    const steps = [...overlay.querySelectorAll('[data-cooking-step]')];
    const stateKey = `cooking:${overlay.dataset.recipeId}`;
    const saved = storage.read(stateKey, {});
    const state = {
        step: Number.isInteger(saved.step) ? Math.min(Math.max(saved.step, 0), steps.length - 1) : 0,
        done: new Set(Array.isArray(saved.done) ? saved.done : []),
        size: Number.isInteger(saved.size) ? Math.min(Math.max(saved.size, 0), 4) : 1,
        timers: saved.timers && typeof saved.timers === 'object' ? saved.timers : {},
    };

    const els = {
        previous: overlay.querySelector('[data-cooking-previous]'),
        next: overlay.querySelector('[data-cooking-next]'),
        close: overlay.querySelector('[data-cooking-close]'),
        progress: overlay.querySelector('[data-cooking-progress]'),
        doneCount: overlay.querySelector('[data-cooking-done-count]'),
        bar: overlay.querySelector('[data-cooking-bar]'),
        timers: overlay.querySelector('[data-cooking-timers]'),
        alert: overlay.querySelector('[data-cooking-alert]'),
        ingredients: overlay.querySelector('[data-cooking-ingredients]'),
        smaller: overlay.querySelector('[data-text-smaller]'),
        larger: overlay.querySelector('[data-text-larger]'),
        wake: overlay.querySelector('[data-wake-toggle]'),
        heading: overlay.querySelector('[data-cooking-heading]'),
    };

    let isOpen = false;
    let ticker = null;
    let wakeLock = null;
    let wantsWake = false;
    const finished = new Set();

    const persist = () => storage.write(stateKey, {
        step: state.step,
        done: [...state.done],
        size: state.size,
        timers: state.timers,
    });

    const stepNumber = (index) => steps[index].dataset.stepNumber;

    /* Timers use absolute end timestamps, so throttled or paused tabs still report the right time. */
    const remainingSeconds = (index) => {
        const end = state.timers[index];

        return end ? Math.max(0, Math.ceil((end - Date.now()) / 1000)) : null;
    };

    const formatClock = (seconds) => {
        const hours = Math.floor(seconds / 3600);
        const minutes = Math.floor((seconds % 3600) / 60);
        const rest = seconds % 60;
        const pad = (value) => String(value).padStart(2, '0');

        return hours > 0 ? `${hours}:${pad(minutes)}:${pad(rest)}` : `${pad(minutes)}:${pad(rest)}`;
    };

    const beep = () => {
        try {
            const AudioContextClass = window.AudioContext || window.webkitAudioContext;
            const context = new AudioContextClass();
            const oscillator = context.createOscillator();

            oscillator.frequency.value = 880;
            oscillator.connect(context.destination);
            oscillator.start();
            oscillator.stop(context.currentTime + 0.4);
            oscillator.onended = () => context.close();
        } catch {
            // Audio is a best-effort extra; the visible and spoken alert still fire.
        }

        navigator.vibrate?.([200, 100, 200]);
    };

    const announceFinished = (index) => {
        finished.add(index);
        els.alert.textContent = '';
        window.requestAnimationFrame(() => {
            els.alert.textContent = template(labels.labelTimerFinished, { step: stepNumber(index) });
        });
        beep();
    };

    const stopTicker = () => {
        if (ticker !== null) {
            window.clearInterval(ticker);
            ticker = null;
        }
    };

    const stopTickerIfIdle = () => {
        if (!Object.keys(state.timers).some((index) => remainingSeconds(Number(index)) > 0)) {
            stopTicker();
        }
    };

    const renderStep = () => {
        steps.forEach((step, index) => {
            step.hidden = index !== state.step;

            const done = state.done.has(index);
            const doneButton = step.querySelector('[data-step-done]');

            doneButton.setAttribute('aria-pressed', String(done));
            doneButton.textContent = done ? labels.labelMarkedDone : labels.labelMarkDone;

            const timerButton = step.querySelector('[data-timer-toggle]');

            if (timerButton) {
                timerButton.textContent = remainingSeconds(index) === null
                    ? template(labels.labelTimerStart, { minutes: step.dataset.minutes })
                    : labels.labelTimerStop;
            }
        });

        els.previous.disabled = state.step === 0;
        els.next.disabled = state.step === steps.length - 1;
        els.progress.textContent = template(labels.labelProgress, { current: state.step + 1, total: steps.length });
        els.doneCount.textContent = template(labels.labelDoneCount, { count: state.done.size });
        els.bar.setAttribute('aria-valuenow', String(state.step + 1));
        els.bar.firstElementChild.style.width = `${((state.step + 1) / steps.length) * 100}%`;
        overlay.dataset.textSize = String(state.size);
        els.smaller.disabled = state.size === 0;
        els.larger.disabled = state.size === 4;
    };

    const renderTimers = () => {
        const indexes = Object.keys(state.timers).map(Number).sort((a, b) => a - b);

        els.timers.hidden = indexes.length === 0;
        els.timers.replaceChildren(...indexes.map((index) => {
            const left = remainingSeconds(index);
            const item = document.createElement('div');

            item.className = `cooking-timer${left === 0 ? ' is-finished' : ''}`;

            const text = document.createElement('span');
            const clock = document.createElement('strong');

            clock.textContent = formatClock(left);
            text.append(`${template(labels.labelTimerRunning, { step: stepNumber(index) })} `, clock);

            const stop = document.createElement('button');

            stop.type = 'button';
            stop.className = 'button';
            stop.textContent = labels.labelTimerStop;
            stop.addEventListener('click', () => {
                delete state.timers[index];
                finished.delete(index);
                persist();
                renderTimers();
                renderStep();
                stopTickerIfIdle();
            });

            item.append(text, stop);

            return item;
        }));
    };

    const tick = () => {
        Object.keys(state.timers).map(Number).forEach((index) => {
            if (remainingSeconds(index) === 0 && !finished.has(index)) {
                announceFinished(index);
            }
        });
        renderTimers();
        renderStep();
        stopTickerIfIdle();
    };

    const ensureTicker = () => {
        if (ticker === null && Object.keys(state.timers).length > 0) {
            ticker = window.setInterval(tick, 1000);
        }
    };

    const goTo = (index, moveFocus = true) => {
        state.step = Math.max(0, Math.min(index, steps.length - 1));
        persist();
        renderStep();

        if (moveFocus) {
            els.heading.focus();
        }
    };

    /* Clone the live ingredient list so quantities always match the selected servings. */
    const renderIngredients = () => {
        const panel = document.querySelector('[data-ingredients-panel]');

        els.ingredients.replaceChildren();

        if (!panel) {
            return;
        }

        panel.querySelectorAll('h3, [data-ingredient-list]').forEach((node) => {
            const clone = node.cloneNode(true);

            clone.querySelectorAll('[id]').forEach((element) => {
                element.id = `cook-${element.id}`;
            });
            clone.querySelectorAll('label[for]').forEach((label) => {
                label.htmlFor = `cook-${label.htmlFor}`;
            });
            clone.querySelectorAll('[wire\\:key]').forEach((element) => element.removeAttribute('wire:key'));
            els.ingredients.append(clone);
        });

        syncIngredients(overlay, overlay.dataset.recipeId);
    };

    const renderWake = () => {
        els.wake.setAttribute('aria-pressed', String(wantsWake));
        els.wake.textContent = wantsWake ? labels.labelWakeOn : labels.labelWakeOff;
    };

    const releaseWakeLock = async () => {
        const lock = wakeLock;

        wakeLock = null;

        try {
            await lock?.release();
        } catch {
            // Already released by the browser.
        }
    };

    const requestWakeLock = async () => {
        try {
            wakeLock = await navigator.wakeLock.request('screen');
            wakeLock.addEventListener('release', () => {
                wakeLock = null;
            });
        } catch {
            wantsWake = false;
            renderWake();
        }
    };

    const onVisibility = () => {
        if (document.visibilityState !== 'visible' || !isOpen) {
            return;
        }

        // Browsers drop the lock when the tab is hidden, so take it again when we return.
        if (wantsWake && wakeLock === null) {
            requestWakeLock();
        }

        tick();
    };

    // The overlay lives inside <main>, so inert its siblings rather than <main> itself.
    const setBackgroundInert = (inert) => {
        const background = [
            ...(overlay.parentElement?.children ?? []),
            document.querySelector('.site-header'),
            document.querySelector('.site-footer'),
        ].filter((element) => element && element !== overlay);

        background.forEach((element) => element.toggleAttribute('inert', inert));
    };

    const close = () => {
        isOpen = false;
        overlay.hidden = true;
        document.documentElement.classList.remove('cooking-open');
        setBackgroundInert(false);
        document.removeEventListener('keydown', onKeydown);
        document.removeEventListener('visibilitychange', onVisibility);
        stopTicker();
        wantsWake = false;
        releaseWakeLock();
        renderWake();
        persist();
        start.focus();
    };

    function onKeydown(event) {
        const inField = event.target.closest?.('input, textarea, select');

        if (event.key === 'Escape') {
            close();
        } else if (event.key === 'ArrowRight' && !inField) {
            goTo(state.step + 1);
        } else if (event.key === 'ArrowLeft' && !inField) {
            goTo(state.step - 1);
        } else if (event.key === 'Tab') {
            const focusable = [...overlay.querySelectorAll('a[href], button:not([disabled]), input, summary')]
                .filter((element) => element.offsetParent !== null);

            if (focusable.length === 0) {
                return;
            }

            const first = focusable[0];
            const last = focusable[focusable.length - 1];

            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        }
    }

    const open = () => {
        isOpen = true;
        overlay.hidden = false;
        document.documentElement.classList.add('cooking-open');
        setBackgroundInert(true);
        renderIngredients();
        ensureTicker();
        tick();
        document.addEventListener('keydown', onKeydown);
        document.addEventListener('visibilitychange', onVisibility);
        els.heading.focus();
    };

    start.hidden = false;
    start.addEventListener('click', open);
    els.close.addEventListener('click', close);
    els.previous.addEventListener('click', () => goTo(state.step - 1));
    els.next.addEventListener('click', () => goTo(state.step + 1));
    els.smaller.addEventListener('click', () => {
        state.size = Math.max(0, state.size - 1);
        persist();
        renderStep();
    });
    els.larger.addEventListener('click', () => {
        state.size = Math.min(4, state.size + 1);
        persist();
        renderStep();
    });

    steps.forEach((step, index) => {
        step.querySelector('[data-step-done]').addEventListener('click', () => {
            if (state.done.has(index)) {
                state.done.delete(index);
            } else {
                state.done.add(index);
            }

            persist();
            renderStep();
        });

        step.querySelector('[data-timer-toggle]')?.addEventListener('click', () => {
            if (remainingSeconds(index) === null) {
                state.timers[index] = Date.now() + Number(step.dataset.minutes) * 60000;
                finished.delete(index);
                ensureTicker();
            } else {
                delete state.timers[index];
                finished.delete(index);
            }

            persist();
            renderTimers();
            renderStep();
            stopTickerIfIdle();
        });
    });

    if ('wakeLock' in navigator) {
        els.wake.hidden = false;
        renderWake();
        els.wake.addEventListener('click', () => {
            wantsWake = !wantsWake;
            renderWake();

            if (wantsWake) {
                requestWakeLock();
            } else {
                releaseWakeLock();
            }
        });
    }

    window.addEventListener('pagehide', () => {
        stopTicker();
        releaseWakeLock();
    });
};

document.addEventListener('DOMContentLoaded', () => {
    initIngredients();
    initJump();
    initPrint();
    initCookingMode();
});
