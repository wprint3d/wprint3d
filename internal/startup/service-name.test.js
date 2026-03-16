const test = require('node:test');
const assert = require('node:assert/strict');

const { normalizeStartupServiceName } = require('./service-name.js');

test('normalizes Docker Compose style container names', () => {
    assert.equal(normalizeStartupServiceName('wprint3d-ws-server-1'), 'ws-server');
    assert.equal(normalizeStartupServiceName('wprint3d-concurrency-scheduler-1'), 'concurrency-scheduler');
});

test('normalizes Podman Compose style container names', () => {
    assert.equal(normalizeStartupServiceName('wprint3d-core_ws-server_1'), 'ws-server');
    assert.equal(normalizeStartupServiceName('wprint3d-core_concurrency-scheduler_1'), 'concurrency-scheduler');
    assert.equal(normalizeStartupServiceName('wprint3d-core_yv-streamer-software_1'), 'yv-streamer-software');
});

test('leaves already-normalized service names intact', () => {
    assert.equal(normalizeStartupServiceName('backend'), 'backend');
    assert.equal(normalizeStartupServiceName('redis'), 'redis');
});
