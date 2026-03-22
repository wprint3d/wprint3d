export function splitConsoleLine(consoleLine) {
    if (!consoleLine) {
        return null;
    }

    const separatorIndex = consoleLine.indexOf(': ');

    if (separatorIndex === -1) {
        return {
            date: null,
            line: consoleLine.trim()
        };
    }

    return {
        date: consoleLine.slice(0, separatorIndex),
        line: consoleLine.slice(separatorIndex + 2).trim()
    };
}

export function parseTerminalHistory(history) {
    if (!history) {
        return [];
    }

    return history
        .split('\n')
        .map(splitConsoleLine)
        .filter(entry => entry && entry.line.length);
}

export function parseTerminalEvent(event) {
    if (!event?.command) {
        return [];
    }

    return event.command
        .split('\n')
        .map(line => line.trim())
        .filter(line => line.length)
        .map(line => ({
            date: event.dateString ?? null,
            line: line
        }));
}

export function filterTerminalEntries(entries, isMessageBlocked) {
    return entries.filter(entry => !isMessageBlocked(entry.line));
}

function parseTerminalTimestamp(dateString) {
    if (!dateString) {
        return null;
    }

    const match = /^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})$/.exec(dateString);

    if (!match) {
        return null;
    }

    const [ , year, month, day, hour, minute, second ] = match.map(Number);

    return Date.UTC(year, month - 1, day, hour, minute, second);
}

export function sortTerminalEntries(entries) {
    return entries
        .map((entry, index) => ({
            entry,
            index,
            timestamp: parseTerminalTimestamp(entry.date)
        }))
        .sort((left, right) => {
            if (left.timestamp !== null && right.timestamp !== null && left.timestamp !== right.timestamp) {
                return left.timestamp - right.timestamp;
            }

            if (left.timestamp !== null && right.timestamp === null) {
                return -1;
            }

            if (left.timestamp === null && right.timestamp !== null) {
                return 1;
            }

            return left.index - right.index;
        })
        .map(({ entry }) => entry);
}

export function trimTerminalEntries(entries, terminalMaxLines) {
    if (terminalMaxLines === 0) {
        return [];
    }

    if (!terminalMaxLines || entries.length <= terminalMaxLines) {
        return entries;
    }

    return entries.slice(entries.length - terminalMaxLines);
}

export function mergeTerminalEntries(previousEntries, nextEntries, terminalMaxLines) {
    return trimTerminalEntries(
        sortTerminalEntries([ ...previousEntries, ...nextEntries ]),
        terminalMaxLines
    );
}
