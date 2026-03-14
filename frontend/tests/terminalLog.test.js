import test from 'node:test';
import assert from 'node:assert/strict';

import {
    filterTerminalEntries,
    mergeTerminalEntries,
    parseTerminalEvent,
    parseTerminalHistory,
} from '../utils/terminalLog.js';

test('parseTerminalEvent preserves rapid command and response events', () => {
    const queuedEvents = [
        {
            dateString: '2026-03-14 00:49:37',
            command: ' > M105\n'
        },
        {
            dateString: '2026-03-14 00:49:37',
            command: 'ok T:24.00 /0.00 B:24.00 /0.00 T0:24.00 /0.00 @:0 B@:0\n'
        }
    ];

    const mergedEntries = mergeTerminalEntries(
        [],
        queuedEvents.flatMap(parseTerminalEvent),
        50
    );

    assert.deepEqual(mergedEntries, [
        {
            date: '2026-03-14 00:49:37',
            line: '> M105'
        },
        {
            date: '2026-03-14 00:49:37',
            line: 'ok T:24.00 /0.00 B:24.00 /0.00 T0:24.00 /0.00 @:0 B@:0'
        }
    ]);
});

test('parseTerminalHistory keeps full timestamps intact', () => {
    const entries = parseTerminalHistory(
        '2026-03-14 00:49:37: > M105\n2026-03-14 00:49:37: ok T:24.00 /0.00\n'
    );

    assert.deepEqual(entries, [
        {
            date: '2026-03-14 00:49:37',
            line: '> M105'
        },
        {
            date: '2026-03-14 00:49:37',
            line: 'ok T:24.00 /0.00'
        }
    ]);
});

test('filterTerminalEntries hides sensor chatter when requested', () => {
    const filteredEntries = filterTerminalEntries(
        [
            { date: '2026-03-14 00:49:37', line: '> M105' },
            { date: '2026-03-14 00:49:37', line: 'ok T:24.00 /0.00' },
            { date: '2026-03-14 00:49:43', line: '> G28' }
        ],
        line => line.includes('> M105') || line.includes('ok T:')
    );

    assert.deepEqual(filteredEntries, [
        { date: '2026-03-14 00:49:43', line: '> G28' }
    ]);
});
