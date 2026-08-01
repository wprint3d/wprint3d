export const PRINTER_CONNECTION_STATUS = {
    CONNECTING: "connecting",
    OFFLINE: "offline",
    ONLINE: "online",
    UNRESPONSIVE: "unresponsive",
};

export const CONNECTION_STATUS_REFETCH_INTERVAL_MS = 5000;

export function getConnectionStatusRefetchInterval({
    connectionStatus,
    realtimeConnectionState,
}) {
    if (
        realtimeConnectionState !== "connected"
        ||
        connectionStatus?.connectionStatus === PRINTER_CONNECTION_STATUS.UNRESPONSIVE
    ) {
        return CONNECTION_STATUS_REFETCH_INTERVAL_MS;
    }

    return false;
}

export function getPrinterConnectionStatusKey({
    connectionStatus,
    isRunningMapper = false,
    nowSecs = Date.now() / 1000,
}) {
    if (isRunningMapper) {
        return PRINTER_CONNECTION_STATUS.CONNECTING;
    }

    if (connectionStatus?.connectionStatus === PRINTER_CONNECTION_STATUS.UNRESPONSIVE) {
        return PRINTER_CONNECTION_STATUS.UNRESPONSIVE;
    }

    if (!connectionStatus || connectionStatus.lastSeen === null) {
        return PRINTER_CONNECTION_STATUS.OFFLINE;
    }

    const thresholdSecs = connectionStatus.thresholdSecs ?? 0;
    const diffSecs = nowSecs - connectionStatus.lastSeen;

    return diffSecs > thresholdSecs * 2
        ? PRINTER_CONNECTION_STATUS.OFFLINE
        : PRINTER_CONNECTION_STATUS.ONLINE;
}
