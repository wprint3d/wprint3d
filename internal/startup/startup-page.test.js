const test = require('node:test');
const assert = require('node:assert/strict');

const { normalizeStartupServiceName } = require('./service-name.js');
const {
    formatServiceLabel,
    normalizeLogText,
    parseServiceStatus,
    summarizeStatuses
} = require('./startup-page.js');

test('interprets only the documented service status values', () => {
    assert.equal(parseServiceStatus('1\n'), 'running');
    assert.equal(parseServiceStatus('0'), 'starting');
    assert.equal(parseServiceStatus('10'), 'unavailable');
    assert.equal(parseServiceStatus('healthy: 1'), 'unavailable');
    assert.equal(parseServiceStatus(''), 'unavailable');
});

test('summarizes startup progress across all service states', () => {
    assert.deepEqual(
        summarizeStatuses(['running', 'starting', 'waiting', 'unavailable']),
        {
            total:       4,
            running:     1,
            starting:    1,
            waiting:     1,
            unavailable: 1,
            percent:     25
        }
    );

    assert.equal(summarizeStatuses([]).percent, 0);
});

test('removes ANSI sequences and normalizes startup log line endings', () => {
    const input = '\u001b[1mWeb\u001b[22m 17.9%\r\nReady\u0000';

    assert.equal(normalizeLogText(input), 'Web 17.9%\nReady');
});

test('formats known and unknown service names for display', () => {
    assert.equal(
        formatServiceLabel('wprint3d-yv-streamer-software-1', normalizeStartupServiceName),
        'YV camera streamer'
    );
    assert.equal(
        formatServiceLabel('wprint3d-custom-worker-1', normalizeStartupServiceName),
        'Custom worker'
    );
});
