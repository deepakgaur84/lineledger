/**
 * Escape goes back: on an app page, Escape does what the browser's Back button
 * does. Per user (Settings → Appearance); the app layout renders
 * <body data-escape-back> while it's on.
 *
 *  - An open popup keeps the key: a Flux modal (<dialog open>), a Flux dropdown
 *    (:popover-open, but not toasts or tooltips), or one of our own Alpine
 *    lists marked data-escape-guard. That's checked in the capture phase,
 *    before the popup closes itself; the decision waits until every other
 *    handler has run, so one that calls preventDefault() keeps the key too.
 *  - A page whose form has been typed into asks before leaving. The flag
 *    clears on navigation, or when a submit comes back without validation
 *    errors (a save that stays on the page).
 *  - Escape never leaves the app: with no same-site page behind this one, it
 *    does nothing.
 */

/**
 * What one Escape press should do.
 *
 * @param {{
 *   key: string,
 *   repeat?: boolean,
 *   modified?: boolean,
 *   composing?: boolean,
 *   enabled?: boolean,
 *   overlayOpen?: boolean,
 *   handled?: boolean,
 *   clearsField?: boolean,
 *   canGoBack?: boolean,
 *   dirty?: boolean,
 * }} press
 * @returns {'ignore'|'confirm'|'back'}
 */
export function decideEscape({
    key,
    repeat = false,
    modified = false,
    composing = false,
    enabled = false,
    overlayOpen = false,
    handled = false,
    clearsField = false,
    canGoBack = false,
    dirty = false,
}) {
    if (key !== 'Escape' && key !== 'Esc') {
        return 'ignore';
    }

    if (repeat || modified || composing) {
        return 'ignore';
    }

    if (!enabled || overlayOpen || handled || clearsField || !canGoBack) {
        return 'ignore';
    }

    return dirty ? 'confirm' : 'back';
}

const PASSIVE_POPOVERS = 'ui-toast, ui-toast-group, [data-flux-tooltip-content]';

function isVisible(el) {
    return typeof el.checkVisibility === 'function' ? el.checkVisibility() : el.getClientRects().length > 0;
}

function overlayOpen() {
    if (document.querySelector('dialog[open]')) {
        return true;
    }

    try {
        const popovers = document.querySelectorAll(':popover-open');
        if ([...popovers].some((el) => !el.matches(PASSIVE_POPOVERS))) {
            return true;
        }
    } catch (e) {
        /* :popover-open unsupported — nothing can be open */
    }

    return [...document.querySelectorAll('[data-escape-guard]')].some(isVisible);
}

function canGoBack() {
    const navigation = window.navigation;
    if (navigation && typeof navigation.canGoBack === 'boolean') {
        return navigation.canGoBack;
    }

    return window.history.length > 1;
}

/** A field whose edits count as unsaved work: in a page form, not a modal's, and not a search box. */
function tracksEdits(el) {
    return el instanceof Element
        && el.closest('form') !== null
        && el.closest('dialog') === null
        && !el.matches('input[type="search"]');
}

export function installEscapeBack() {
    if (window._escapeBackBound) {
        return;
    }

    window._escapeBackBound = true;

    // Set when the preference is switched on this document; beats the <body>
    // attribute, which Back restores from Livewire's page cache as it was.
    let preference = null;
    window.addEventListener('escape-back-changed', (event) => {
        preference = Boolean(event.detail?.enabled);
    });

    let dirty = false;
    // Flux and Alpine fire input/change while components set themselves up;
    // only edits after the user has touched the page count.
    let interacted = false;
    // wire:id of the component whose form was just submitted.
    let submittedId = null;

    const reset = () => {
        dirty = false;
        interacted = false;
        submittedId = null;
    };

    ['keydown', 'pointerdown'].forEach((name) => {
        document.addEventListener(name, (event) => {
            if (event.isTrusted) {
                interacted = true;
            }
        }, true);
    });

    ['input', 'change'].forEach((name) => {
        document.addEventListener(name, (event) => {
            if (interacted && tracksEdits(event.target)) {
                dirty = true;
            }
        }, true);
    });

    document.addEventListener('submit', (event) => {
        if (tracksEdits(event.target)) {
            submittedId = event.target.closest('[wire\\:id]')?.getAttribute('wire:id') ?? null;
        }
    }, true);

    document.addEventListener('livewire:navigated', reset);

    const watchSaves = () => window.Livewire.hook('commit', ({ component, succeed }) => {
        if (submittedId === null || component.id !== submittedId) {
            return;
        }

        succeed(({ snapshot }) => {
            const errors = snapshot?.memo?.errors ?? component.snapshot?.memo?.errors ?? {};
            submittedId = null;

            if (Object.keys(errors).length === 0) {
                dirty = false;
            }
        });
    });

    if (window.Livewire?.hook) {
        watchSaves();
    } else {
        document.addEventListener('livewire:init', watchSaves, { once: true });
    }

    window.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape' && event.key !== 'Esc') {
            return;
        }

        const target = event.target;
        const press = {
            key: event.key,
            repeat: event.repeat,
            modified: event.ctrlKey || event.altKey || event.metaKey || event.shiftKey,
            composing: event.isComposing,
            enabled: preference ?? document.body?.hasAttribute('data-escape-back') ?? false,
            overlayOpen: overlayOpen(),
            clearsField: target instanceof HTMLInputElement && target.type === 'search' && target.value !== '',
        };

        // Let the page's own Escape handlers run first.
        window.setTimeout(() => {
            const action = decideEscape({
                ...press,
                handled: event.defaultPrevented,
                canGoBack: canGoBack(),
                dirty,
            });

            if (action === 'ignore') {
                return;
            }

            if (action === 'confirm') {
                const message = document.body.dataset.escapeBackConfirm
                    || "Leave this page? Changes you haven't saved will be lost.";
                if (!window.confirm(message)) {
                    return;
                }
            }

            window.history.back();
        }, 0);
    }, true);
}
