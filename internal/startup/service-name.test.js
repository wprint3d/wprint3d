const test = require('node:test');
const assert = require('node:assert/strict');

const { normalizeStartupServiceName } = require('./service-name.js');

test('normalizes Docker Compose style container names', () => {
    assert.equal(normalizeStartupServiceName('wprint3d-ws-server-1'), 'ws-server');
    assert.equal(normalizeStartupServiceName('wprint3d-concurrency-scheduler-1'), 'concurrency-scheduler');
});


test('leaves already-normalized service names intact', () => {
    assert.equal(normalizeStartupServiceName('backend'), 'backend');
    assert.equal(normalizeStartupServiceName('redis'), 'redis');
});
