import { test } from 'node:test';
import assert from 'node:assert/strict';

import { decideKeeperAction, shouldPollBlocked } from '../../resources/js/edit-lock.js';

test('a held lease at the same version carries on', () => {
    assert.equal(decideKeeperAction({ status: 'held', version: 'v1', localVersion: 'v1' }), 'continue');
});

test('a held lease whose version moved asks the component to sync', () => {
    assert.equal(decideKeeperAction({ status: 'held', version: 'v2', localVersion: 'v1' }), 'sync');
});

test('idle pauses the keeper', () => {
    assert.equal(decideKeeperAction({ status: 'idle' }), 'pause');
});

test('expired and lost leases sync so the server can reclaim or show the banner', () => {
    assert.equal(decideKeeperAction({ status: 'expired' }), 'sync');
    assert.equal(decideKeeperAction({ status: 'lost' }), 'sync');
});

test('an unknown status syncs rather than carrying on silently', () => {
    assert.equal(decideKeeperAction({ status: 'something-new' }), 'sync');
});

test('a single failed beat retries; a second in a row syncs', () => {
    assert.equal(decideKeeperAction({ status: null, failures: 1 }), 'retry');
    assert.equal(decideKeeperAction({ status: null, failures: 2 }), 'sync');
});

test('the blocked panel polls only while the tab is visible and the viewer was recently active', () => {
    const idleMs = 15 * 60 * 1000;

    assert.equal(shouldPollBlocked({ visible: true, lastActivityMs: 0, nowMs: 60_000, idleMs }), true);
    assert.equal(shouldPollBlocked({ visible: false, lastActivityMs: 0, nowMs: 60_000, idleMs }), false);
    assert.equal(shouldPollBlocked({ visible: true, lastActivityMs: 0, nowMs: idleMs, idleMs }), false);
});
