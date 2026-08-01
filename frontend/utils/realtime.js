export const REALTIME_QUERY_KEY_PREFIXES = [
    ['connectionStatus'],
    ['fileList'],
    ['notifications'],
    ['plugins'],
    ['printersList'],
    ['printStatus'],
    ['selectedPrinter'],
    ['terminalLastLog'],
    ['updateStatus'],
    ['user', 'printer'],
];

const queryKeyStartsWith = (queryKey, prefix) => (
    Array.isArray(queryKey)
    &&
    prefix.every((value, index) => queryKey[index] === value)
);

export const shouldReconcileRealtimeQuery = (query) => (
    REALTIME_QUERY_KEY_PREFIXES.some(prefix => queryKeyStartsWith(query?.queryKey, prefix))
);

export const getRealtimePort = (location) => {
    const explicitPort = Number.parseInt(location?.port, 10);

    if (Number.isInteger(explicitPort) && explicitPort > 0) {
        return explicitPort;
    }

    return location?.protocol === 'http:' ? 80 : 443;
};

export const getRealtimeConnectionOptions = ({ appKey, location }) => {
    const port = getRealtimePort(location);

    return {
        authEndpoint: '/backend/broadcasting/auth',
        broadcaster: 'pusher',
        key: appKey,
        wsHost: location.hostname,
        wsPort: port,
        wssPort: port,
        forceTLS: location.protocol === 'https:',
        cluster: 'mt1',
        enabledTransports: ['ws', 'wss'],
    };
};

export const shouldRetryRealtimeConfig = (failureCount, error) => {
    const status = error?.response?.status ?? error?.status;

    if ([401, 403, 423].includes(status)) {
        return false;
    }

    return failureCount < 8;
};

export const getRealtimeRetryDelay = attempt => Math.min(1000 * (2 ** attempt), 30000);
