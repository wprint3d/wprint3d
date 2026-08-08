const test = require('node:test');
const assert = require('node:assert/strict');

const { normalizeStartupServiceName } = require('./service-name.js');
const {
    areHostMetricsStale,
    createDiagnosticsState,
    formatServiceLabel,
    ingestHostMetrics,
    markStartupProgress,
    normalizeHostMetrics,
    normalizeLogText,
    parseServiceStatus,
    refreshNonPressureHint,
    summarizeStatuses,
    updateDiagnosticsVisibility
} = require('./startup-page.js');

const hostMetrics = ({
    sequence,
    cpu = 10,
    memory = 50,
    busy = 5,
    ioWait = 1,
    read = 0,
    write = 0
}) => normalizeHostMetrics({
    version: 1,
    sequence,
    sampledAt: sequence * 2000,
    intervalMs: 2000,
    cpu: { usedPercent: cpu, ioWaitPercent: ioWait },
    memory: { usedBytes: 500, totalBytes: 1000, usedPercent: memory },
    storage: {
        readBytesPerSecond: read,
        writeBytesPerSecond: write,
        busyPercent: busy
    },
    leaders: {
        cpu: [{ kind: 'metro', cpuPercent: cpu, residentBytes: 100, ioBytesPerSecond: read + write }],
        memory: [],
        io: []
    }
});

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

test('normalizes the public metrics contract and drops unsafe process fields', () => {
    const metrics = normalizeHostMetrics(JSON.stringify({
        version: 1,
        sequence: 4,
        sampledAt: 123,
        intervalMs: 2000,
        cpu: { usedPercent: 140, ioWaitPercent: -1 },
        memory: { usedBytes: 500, totalBytes: 1000, usedPercent: 50 },
        storage: { readBytesPerSecond: 10, writeBytesPerSecond: 20, busyPercent: 25 },
        leaders: {
            cpu: [{
                kind: 'unknown',
                name: '../../secret worker',
                cpuPercent: 101,
                residentBytes: 200,
                ioBytesPerSecond: 300,
                pid: 42,
                cmdline: '--token=secret'
            }]
        }
    }));

    assert.equal(metrics.cpu.usedPercent, 100);
    assert.equal(metrics.cpu.ioWaitPercent, 0);
    assert.deepEqual(metrics.leaders.cpu[0], {
        kind: 'other',
        name: 'secret_worker',
        cpuPercent: 100,
        residentBytes: 200,
        ioBytesPerSecond: 300
    });
    assert.equal(normalizeHostMetrics('{broken'), null);
    assert.equal(normalizeHostMetrics({ version: 2, sequence: 1 }), null);
});

test('reveals diagnostics after fifteen seconds without progress and never hides them again', () => {
    const diagnostics = createDiagnosticsState(0);

    assert.equal(updateDiagnosticsVisibility(diagnostics, 14999), false);
    markStartupProgress(diagnostics, 10000, { logChanged: true });
    assert.equal(updateDiagnosticsVisibility(diagnostics, 24999), false);
    assert.equal(updateDiagnosticsVisibility(diagnostics, 25000), true);

    markStartupProgress(diagnostics, 30000);
    assert.equal(updateDiagnosticsVisibility(diagnostics, 30001), true);
    assert.equal(diagnostics.lastLogChangeAt, 10000);
});

test('requires sustained pressure and keeps a stable dominant hint', () => {
    const diagnostics = createDiagnosticsState(0);

    ingestHostMetrics(diagnostics, hostMetrics({ sequence: 1, cpu: 90 }), 0);
    ingestHostMetrics(diagnostics, hostMetrics({ sequence: 2, cpu: 91 }), 2000);
    assert.equal(diagnostics.activeHint, 'active');

    ingestHostMetrics(diagnostics, hostMetrics({ sequence: 3, cpu: 92 }), 4000);
    assert.equal(diagnostics.activeHint, 'active');
    ingestHostMetrics(diagnostics, hostMetrics({ sequence: 4, cpu: 92 }), 6000);
    ingestHostMetrics(diagnostics, hostMetrics({ sequence: 5, cpu: 92 }), 8000);
    ingestHostMetrics(diagnostics, hostMetrics({ sequence: 6, cpu: 92 }), 10000);
    ingestHostMetrics(diagnostics, hostMetrics({ sequence: 7, cpu: 92 }), 12000);
    assert.equal(diagnostics.activeHint, 'cpu');

    ingestHostMetrics(diagnostics, hostMetrics({ sequence: 8, cpu: 50, memory: 95 }), 14000);
    ingestHostMetrics(diagnostics, hostMetrics({ sequence: 9, cpu: 50, memory: 96 }), 16000);
    ingestHostMetrics(diagnostics, hostMetrics({ sequence: 10, cpu: 50, memory: 97 }), 18000);
    assert.equal(diagnostics.activeHint, 'cpu');

    ingestHostMetrics(diagnostics, hostMetrics({ sequence: 11, cpu: 50, memory: 97 }), 20000);
    ingestHostMetrics(diagnostics, hostMetrics({ sequence: 12, cpu: 50, memory: 97 }), 22000);
    ingestHostMetrics(diagnostics, hostMetrics({ sequence: 13, cpu: 50, memory: 97 }), 24000);
    assert.equal(diagnostics.activeHint, 'memory');

    ingestHostMetrics(diagnostics, hostMetrics({ sequence: 14 }), 26000);
    ingestHostMetrics(diagnostics, hostMetrics({ sequence: 15 }), 28000);
    ingestHostMetrics(diagnostics, hostMetrics({ sequence: 16 }), 30000);
    assert.equal(diagnostics.activeHint, 'memory');

    ingestHostMetrics(diagnostics, hostMetrics({ sequence: 17 }), 32000);
    ingestHostMetrics(diagnostics, hostMetrics({ sequence: 18 }), 34000);
    ingestHostMetrics(diagnostics, hostMetrics({ sequence: 19 }), 36000);
    assert.equal(diagnostics.activeHint, 'active');
});

test('resets pressure streaks after delayed or skipped samples', () => {
    const diagnostics = createDiagnosticsState(0);

    ingestHostMetrics(diagnostics, hostMetrics({ sequence: 1, cpu: 95 }), 0);
    ingestHostMetrics(diagnostics, hostMetrics({ sequence: 2, cpu: 95 }), 2000);
    ingestHostMetrics(diagnostics, hostMetrics({ sequence: 4, cpu: 95 }), 4000);
    assert.equal(diagnostics.pressureStreaks.cpu, 1);

    ingestHostMetrics(diagnostics, hostMetrics({ sequence: 5, cpu: 95 }), 10000);
    assert.equal(diagnostics.pressureStreaks.cpu, 1);

    const pressuredDiagnostics = createDiagnosticsState(0);

    for (let sequence = 1; sequence <= 7; sequence += 1) {
        ingestHostMetrics(
            pressuredDiagnostics,
            hostMetrics({ sequence, cpu: 95 }),
            (sequence - 1) * 2000
        );
    }

    assert.equal(pressuredDiagnostics.activeHint, 'cpu');
    ingestHostMetrics(pressuredDiagnostics, hostMetrics({ sequence: 8, cpu: 95 }), 19000);
    assert.equal(pressuredDiagnostics.activeHint, 'active');
    assert.equal(pressuredDiagnostics.pressureStreaks.cpu, 1);
});

test('marks repeated samples stale and selects the low-activity hint after a minute', () => {
    const diagnostics = createDiagnosticsState(0);

    ingestHostMetrics(diagnostics, hostMetrics({ sequence: 1 }), 1000);
    assert.equal(areHostMetricsStale(diagnostics, 6999), false);
    assert.equal(areHostMetricsStale(diagnostics, 7000), true);

    refreshNonPressureHint(diagnostics, 60000);
    assert.equal(diagnostics.activeHint, 'lowActivity');
});
