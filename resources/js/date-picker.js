/**
 * Clicking a date field opens its calendar. Every date field in the app is a
 * native <input type="date"> (<flux:input type="date">), whose calendar Chrome
 * and Edge open only from the small icon at the field's edge — a click
 * anywhere else just lands the cursor on the month, day or year. This opens
 * the browser's own calendar from a click anywhere in the field; Firefox and
 * Safari already do that, and showPicker() leaves an open calendar alone.
 *
 *  - Click only, not focus: tabbing into a date field doesn't pop a calendar,
 *    so typing a date stays the fast path (Alt+↓ or Space still opens it).
 *  - One listener on document, so it covers every date field — in modals,
 *    swapped in by Livewire, or arriving via wire:navigate — without any
 *    markup on the fields themselves.
 *  - Read-only and disabled fields are left alone; showPicker() throws on
 *    them anyway.
 */

const PICKER_TYPES = new Set(['date', 'month', 'week', 'datetime-local']);

/**
 * Should a click on this input open the browser's calendar?
 *
 * @param {{
 *   type?: string|null,
 *   readOnly?: boolean,
 *   disabled?: boolean,
 *   supported?: boolean,
 * }} field
 * @returns {boolean}
 */
export function opensCalendar({ type, readOnly = false, disabled = false, supported = false }) {
    return supported
        && PICKER_TYPES.has(String(type ?? '').toLowerCase())
        && !readOnly
        && !disabled;
}

export function installDatePicker() {
    if (window._datePickerBound) {
        return;
    }

    window._datePickerBound = true;

    document.addEventListener('click', (event) => {
        const el = event.target;
        if (!(el instanceof HTMLInputElement)) {
            return;
        }

        const open = opensCalendar({
            type: el.type,
            readOnly: el.readOnly,
            disabled: el.disabled,
            supported: typeof el.showPicker === 'function',
        });

        if (!open) {
            return;
        }

        try {
            el.showPicker();
        } catch (e) {
            /* no user activation, or inside a cross-origin frame — the icon still works */
        }
    });
}
