import { test } from 'node:test';
import assert from 'node:assert/strict';

import { opensCalendar } from '../../resources/js/date-picker.js';

const field = (overrides = {}) => ({ type: 'date', supported: true, ...overrides });

test('a click on a date field opens the calendar', () => {
    assert.equal(opensCalendar(field()), true);
});

test('the other calendar-backed input types open it too', () => {
    assert.equal(opensCalendar(field({ type: 'month' })), true);
    assert.equal(opensCalendar(field({ type: 'week' })), true);
    assert.equal(opensCalendar(field({ type: 'datetime-local' })), true);
});

test('the type is matched without regard to case', () => {
    assert.equal(opensCalendar(field({ type: 'DATE' })), true);
});

test('other inputs never open it', () => {
    assert.equal(opensCalendar(field({ type: 'text' })), false);
    assert.equal(opensCalendar(field({ type: 'number' })), false);
    assert.equal(opensCalendar(field({ type: 'search' })), false);
    assert.equal(opensCalendar(field({ type: 'time' })), false);
    assert.equal(opensCalendar(field({ type: '' })), false);
    assert.equal(opensCalendar(field({ type: undefined })), false);
    assert.equal(opensCalendar(field({ type: null })), false);
});

test('a read-only or disabled date field stays closed', () => {
    assert.equal(opensCalendar(field({ readOnly: true })), false);
    assert.equal(opensCalendar(field({ disabled: true })), false);
});

test('a browser without showPicker is left to its own behaviour', () => {
    assert.equal(opensCalendar(field({ supported: false })), false);
    assert.equal(opensCalendar({ type: 'date' }), false);
});
