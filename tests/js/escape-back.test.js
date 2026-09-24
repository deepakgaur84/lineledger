import { test } from 'node:test';
import assert from 'node:assert/strict';

import { decideEscape } from '../../resources/js/escape-back.js';

const press = (overrides = {}) => ({ key: 'Escape', enabled: true, canGoBack: true, ...overrides });

test('a plain Escape goes back', () => {
    assert.equal(decideEscape(press()), 'back');
});

test('the legacy Esc key name goes back too', () => {
    assert.equal(decideEscape(press({ key: 'Esc' })), 'back');
});

test('other keys are ignored', () => {
    assert.equal(decideEscape(press({ key: 'Enter' })), 'ignore');
    assert.equal(decideEscape(press({ key: 'Backspace' })), 'ignore');
});

test('nothing happens while the preference is off', () => {
    assert.equal(decideEscape(press({ enabled: false })), 'ignore');
    assert.equal(decideEscape({ key: 'Escape', canGoBack: true }), 'ignore');
});

test('an open popup keeps the key', () => {
    assert.equal(decideEscape(press({ overlayOpen: true })), 'ignore');
    assert.equal(decideEscape(press({ overlayOpen: true, dirty: true })), 'ignore');
});

test('a handler that already used the key keeps it', () => {
    assert.equal(decideEscape(press({ handled: true })), 'ignore');
});

test('a held key, a modifier, or IME composition is ignored', () => {
    assert.equal(decideEscape(press({ repeat: true })), 'ignore');
    assert.equal(decideEscape(press({ modified: true })), 'ignore');
    assert.equal(decideEscape(press({ composing: true })), 'ignore');
});

test('a filled search box gets cleared instead', () => {
    assert.equal(decideEscape(press({ clearsField: true })), 'ignore');
});

test('with no page to go back to, nothing happens', () => {
    assert.equal(decideEscape(press({ canGoBack: false })), 'ignore');
    assert.equal(decideEscape({ key: 'Escape', enabled: true }), 'ignore');
});

test('unsaved changes ask first', () => {
    assert.equal(decideEscape(press({ dirty: true })), 'confirm');
});

test('unsaved changes with nowhere to go back to still do nothing', () => {
    assert.equal(decideEscape(press({ dirty: true, canGoBack: false })), 'ignore');
});
