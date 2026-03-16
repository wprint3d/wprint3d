(function (globalScope) {
    'use strict';

    const KNOWN_SERVICE_NAMES = [
        'concurrency-scheduler',
        'yv-streamer-software',
        'ws-server',
        'memcached',
        'scheduler',
        'streamer',
        'backend',
        'mapper',
        'redis',
        'proxy',
        'mongo',
        'queue',
        'web'
    ];

    const escapeRegExp = (value) => value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

    const extractPodmanComposeServiceName = (value) => {
        const lastUnderscoreIndex = value.lastIndexOf('_');

        if (lastUnderscoreIndex === -1 || !/^\d+$/.test(value.slice(lastUnderscoreIndex + 1))) {
            return null;
        }

        const previousUnderscoreIndex = value.lastIndexOf('_', lastUnderscoreIndex - 1);

        if (previousUnderscoreIndex === -1) {
            return null;
        }

        return value.slice(previousUnderscoreIndex + 1, lastUnderscoreIndex) || null;
    };

    const extractDockerComposeServiceName = (value) => {
        const match = value.match(/^wprint3d-(.+)-\d+$/);

        return match ? match[1] : null;
    };

    const normalizeStartupServiceName = (value) => {
        if (typeof value !== 'string') {
            return '';
        }

        const trimmedValue = value.trim();

        if (!trimmedValue) {
            return '';
        }

        const matchingKnownService = KNOWN_SERVICE_NAMES.find((serviceName) => {
            const pattern = new RegExp(`(?:^|[_-])${escapeRegExp(serviceName)}(?:$|[_-]\\d+$)`);

            return pattern.test(trimmedValue);
        });

        if (matchingKnownService) {
            return matchingKnownService;
        }

        return (
            extractPodmanComposeServiceName(trimmedValue)
            ?? extractDockerComposeServiceName(trimmedValue)
            ?? trimmedValue.replace(/^wprint3d-/, '')
        );
    };

    const startupServiceNameUtils = {
        normalizeStartupServiceName
    };

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = startupServiceNameUtils;
    }

    globalScope.startupServiceNameUtils = startupServiceNameUtils;
})(typeof window !== 'undefined' ? window : globalThis);
