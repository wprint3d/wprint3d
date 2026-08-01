(function (globalScope) {
    'use strict';

    const POLL_INTERVAL = 1500;

    const DISPLAY_NAMES = {
        'proxy':                    'Proxy',
        'web':                      'Web UI',
        'streamer':                 'Camera streamer',
        'yv-streamer-software':     'YV camera streamer',
        'mapper':                   'Device mapper',
        'backend':                  'Backend API',
        'ws-server':                'WebSockets server',
        'scheduler':                'Task scheduler',
        'concurrency-scheduler':    'Concurrency scheduler',
        'queue':                    'Queue workers',
        'mongo':                    'Persistent database',
        'redis':                    'Redis cache',
        'memcached':                'Memcached cache'
    };

    const STATUS_LABELS = {
        waiting:     'Waiting',
        starting:    'Starting',
        running:     'Running',
        unavailable: 'Unavailable'
    };

    const normalizeLogText = (value) => String(value ?? '')
        .replace(/\u001B\][^\u0007]*(?:\u0007|\u001B\\)/g, '')
        .replace(/\u001B\[[0-?]*[ -/]*[@-~]/g, '')
        .replace(/\r\n?/g, '\n')
        .replace(/[\u0000-\u0008\u000B\u000C\u000E-\u001F\u007F]/g, '');

    const parseServiceStatus = (value) => {
        const normalizedValue = String(value ?? '').trim();

        if (normalizedValue === '1') {
            return 'running';
        }

        if (normalizedValue === '0') {
            return 'starting';
        }

        return 'unavailable';
    };

    const summarizeStatuses = (statuses) => {
        const summary = {
            total:       statuses.length,
            running:     0,
            starting:    0,
            waiting:     0,
            unavailable: 0,
            percent:     0
        };

        statuses.forEach((status) => {
            if (Object.prototype.hasOwnProperty.call(summary, status) && status !== 'total' && status !== 'percent') {
                summary[status] += 1;
            } else {
                summary.unavailable += 1;
            }
        });

        if (summary.total > 0) {
            summary.percent = Math.round((summary.running / summary.total) * 100);
        }

        return summary;
    };

    const humanizeServiceName = (value) => String(value ?? '')
        .replace(/[_-]+/g, ' ')
        .replace(/\s+/g, ' ')
        .trim()
        .replace(/^./, (firstCharacter) => firstCharacter.toUpperCase());

    const formatServiceLabel = (serviceName, normalizeServiceName) => {
        const normalizer = normalizeServiceName
            ?? globalScope.startupServiceNameUtils?.normalizeStartupServiceName;
        const simplifiedName = normalizer
            ? normalizer(serviceName)
            : String(serviceName ?? '').trim();

        return DISPLAY_NAMES[simplifiedName] ?? humanizeServiceName(simplifiedName);
    };

    const startupPageUtils = {
        formatServiceLabel,
        normalizeLogText,
        parseServiceStatus,
        summarizeStatuses
    };

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = startupPageUtils;
    }

    globalScope.startupPageUtils = startupPageUtils;

    if (typeof document === 'undefined') {
        return;
    }

    const state = {
        services:              [],
        statuses:              new Map(),
        serviceElements:       new Map(),
        logText:               '',
        fetchingServices:      false,
        fetchingStatuses:      false,
        fetchingStartupLog:    false,
        hasReceivedStartupLog: false,
        copyingLog:            false,
        copyResetTimer:        null
    };

    const elements = {};

    const getElement = (id) => document.getElementById(id);

    const formatTime = (date) => date.toLocaleTimeString([], {
        hour:   '2-digit',
        minute: '2-digit',
        second: '2-digit'
    });

    const fetchText = (path) => fetch(path, {
        cache:   'no-store',
        headers: {
            Accept: 'text/plain'
        }
    });

    const setEmptyServicesMessage = (title, description) => {
        if (!elements.servicesEmpty) {
            return;
        }

        const titleElement = elements.servicesEmpty.querySelector('strong');
        const descriptionElement = elements.servicesEmpty.querySelector('p');

        titleElement.textContent = title;
        descriptionElement.textContent = description;
    };

    const createServiceCard = (service) => {
        const card = document.createElement('li');
        const indicator = document.createElement('span');
        const information = document.createElement('span');
        const displayName = document.createElement('strong');
        const internalName = document.createElement('code');
        const status = document.createElement('span');

        card.className = 'service-card';
        card.dataset.internalName = service;
        card.dataset.status = 'waiting';

        indicator.className = 'service-indicator';
        indicator.setAttribute('aria-hidden', 'true');

        displayName.className = 'service-name';
        displayName.textContent = formatServiceLabel(service);

        internalName.className = 'service-internal-name';
        internalName.textContent = service;
        internalName.title = service;

        information.append(displayName, internalName);

        status.className = 'service-status';
        status.textContent = STATUS_LABELS.waiting;

        card.append(indicator, information, status);

        state.serviceElements.set(service, { card, status });

        return card;
    };

    const renderServices = () => {
        state.serviceElements.clear();
        elements.services.replaceChildren();

        state.services.forEach((service) => {
            elements.services.append(createServiceCard(service));
        });

        elements.serviceCount.textContent = String(state.services.length);
        elements.serviceCount.setAttribute(
            'aria-label',
            `${state.services.length} ${state.services.length === 1 ? 'service' : 'services'}`
        );
    };

    const updateServiceCard = (service, serviceStatus) => {
        const serviceElements = state.serviceElements.get(service);

        if (!serviceElements) {
            return;
        }

        serviceElements.card.dataset.status = serviceStatus;
        serviceElements.status.textContent = STATUS_LABELS[serviceStatus] ?? STATUS_LABELS.unavailable;
    };

    const updateOverview = () => {
        const summary = summarizeStatuses(state.services.map((service) => state.statuses.get(service) ?? 'waiting'));
        const pluralizedService = summary.total === 1 ? 'service' : 'services';
        let title = 'Discovering local services';
        let description = 'Waiting for the startup controller to publish its service list.';
        let badgeState = 'starting';
        let badgeText = 'Startup in progress';

        if (summary.total > 0 && summary.running === summary.total) {
            title = 'All services are online';
            description = 'The local stack is ready. Redirecting to WPrint 3D…';
            badgeState = 'running';
            badgeText = 'Services online';
        } else if (summary.total > 0 && summary.unavailable > 0) {
            title = 'Waiting for service responses';
            description = `${summary.unavailable} ${summary.unavailable === 1 ? 'service is' : 'services are'} temporarily unavailable. Retrying automatically.`;
            badgeState = 'unavailable';
            badgeText = 'Retrying services';
        } else if (summary.total > 0) {
            title = 'Starting local services';
            description = `${summary.starting + summary.waiting} ${summary.starting + summary.waiting === 1 ? 'service is' : 'services are'} still initializing.`;
        }

        elements.overviewTitle.textContent = title;
        elements.overviewDescription.textContent = description;
        elements.progressValue.textContent = `${summary.percent}%`;
        elements.progressBar.style.width = `${summary.percent}%`;
        elements.progressTrack.setAttribute('aria-valuenow', String(summary.percent));
        elements.progressTrack.setAttribute(
            'aria-valuetext',
            summary.total > 0
                ? `${summary.running} of ${summary.total} services running`
                : 'Waiting for service discovery'
        );
        elements.progressSummary.replaceChildren();

        const progressCount = document.createElement('strong');
        progressCount.textContent = `${summary.running} of ${summary.total}`;
        elements.progressSummary.append(progressCount, ` ${pluralizedService} running`);

        elements.liveBadge.dataset.state = badgeState;

        if (elements.liveBadgeText.textContent !== badgeText) {
            elements.liveBadgeText.textContent = badgeText;
        }

        document.title = summary.total > 0
            ? `${summary.percent}% · WPrint 3D startup`
            : 'Starting up · WPrint 3D';
    };

    const fetchServicesList = async () => {
        if (state.fetchingServices || state.services.length > 0) {
            return;
        }

        state.fetchingServices = true;

        try {
            const response = await fetchText('services.txt');

            if (response.status === 502) {
                setEmptyServicesMessage(
                    'Waiting for service discovery',
                    'The startup controller is still preparing its service list. Retrying automatically.'
                );

                return;
            }

            if (!response.ok) {
                throw new Error(`Service list request failed with status ${response.status}.`);
            }

            const content = await response.text();
            const services = [...new Set(content
                .split(/\r?\n/)
                .map((service) => service.trim())
                .filter(Boolean))];

            if (services.length === 0) {
                setEmptyServicesMessage(
                    'No services reported yet',
                    'The service list is empty. Retrying automatically.'
                );

                return;
            }

            state.services = services;
            state.services.forEach((service) => state.statuses.set(service, 'waiting'));
            renderServices();
            updateOverview();
        } catch (error) {
            console.error(error);
            setEmptyServicesMessage(
                'Service discovery unavailable',
                'The service list could not be read. Retrying automatically.'
            );
        } finally {
            state.fetchingServices = false;
        }
    };

    const fetchServiceStatus = async (service) => {
        try {
            const response = await fetchText(`${service}_status.txt`);

            if (!response.ok) {
                return 'unavailable';
            }

            return parseServiceStatus(await response.text());
        } catch (error) {
            console.error(`Could not fetch status for ${service}.`, error);

            return 'unavailable';
        }
    };

    const refreshServicesStatus = async () => {
        if (state.fetchingStatuses || state.services.length === 0) {
            return;
        }

        state.fetchingStatuses = true;

        try {
            const serviceStatuses = await Promise.all(state.services.map(async (service) => ({
                service,
                status: await fetchServiceStatus(service)
            })));

            serviceStatuses.forEach(({ service, status }) => {
                state.statuses.set(service, status);
                updateServiceCard(service, status);
            });

            elements.lastUpdated.textContent = `Last checked ${formatTime(new Date())}`;
            updateOverview();
        } finally {
            state.fetchingStatuses = false;
        }
    };

    const setLogContent = (content, statusMessage) => {
        const normalizedContent = normalizeLogText(content);
        const shouldFollowLog = !state.hasReceivedStartupLog
            || elements.startupLog.scrollHeight - elements.startupLog.scrollTop - elements.startupLog.clientHeight < 48;

        state.logText = normalizedContent;
        elements.startupLog.textContent = normalizedContent || 'No startup output yet.';
        elements.logStatus.textContent = statusMessage;
        elements.copyLogs.disabled = normalizedContent.length === 0 || state.copyingLog;

        if (shouldFollowLog && normalizedContent.length > 0) {
            elements.startupLog.scrollTop = elements.startupLog.scrollHeight;
        }

        state.hasReceivedStartupLog = true;
    };

    const fetchStartupLog = async () => {
        if (state.fetchingStartupLog) {
            return;
        }

        state.fetchingStartupLog = true;

        try {
            const response = await fetchText('startup.txt');

            if (response.status === 502) {
                setLogContent('', 'Waiting for the startup controller…');

                return;
            }

            const contentType = response.headers.get('content-type');

            if (!contentType || !contentType.includes('text/plain')) {
                globalScope.location.assign('/');

                return;
            }

            if (!response.ok) {
                throw new Error(`Startup log request failed with status ${response.status}.`);
            }

            setLogContent(await response.text(), `Live · updated ${formatTime(new Date())}`);
        } catch (error) {
            console.error(error);
            elements.logStatus.textContent = 'Log unavailable · retrying automatically';
        } finally {
            state.fetchingStartupLog = false;
        }
    };

    const fallbackCopyText = (text) => {
        const textArea = document.createElement('textarea');

        textArea.value = text;
        textArea.setAttribute('readonly', '');
        textArea.style.position = 'fixed';
        textArea.style.opacity = '0';
        document.body.append(textArea);
        textArea.select();

        const copied = document.execCommand('copy');
        textArea.remove();

        if (!copied) {
            throw new Error('The browser did not copy the startup log.');
        }
    };

    const copyStartupLog = async () => {
        if (!state.logText || state.copyingLog) {
            return;
        }

        globalScope.clearTimeout(state.copyResetTimer);
        state.copyingLog = true;
        elements.copyLogs.disabled = true;
        elements.copyFeedback.textContent = '';

        try {
            if (globalScope.navigator.clipboard?.writeText) {
                try {
                    await globalScope.navigator.clipboard.writeText(state.logText);
                } catch (clipboardError) {
                    console.warn('Clipboard API unavailable; using the browser fallback.', clipboardError);
                    fallbackCopyText(state.logText);
                }
            } else {
                fallbackCopyText(state.logText);
            }

            elements.copyButtonLabel.textContent = 'Copied';
            elements.copyFeedback.textContent = 'Startup logs copied to the clipboard.';
        } catch (error) {
            console.error(error);
            elements.copyButtonLabel.textContent = 'Copy failed';
            elements.copyFeedback.textContent = 'Startup logs could not be copied.';
        } finally {
            state.copyingLog = false;
            elements.copyLogs.disabled = state.logText.length === 0;
            state.copyResetTimer = globalScope.setTimeout(() => {
                elements.copyButtonLabel.textContent = 'Copy logs';
            }, 2000);
        }
    };

    const refresh = async () => {
        await Promise.all([
            fetchStartupLog(),
            fetchServicesList().then(refreshServicesStatus)
        ]);
    };

    const initialize = () => {
        Object.assign(elements, {
            copyButtonLabel:    getElement('copyButtonLabel'),
            copyFeedback:       getElement('copyFeedback'),
            copyLogs:           getElement('copyLogs'),
            lastUpdated:        getElement('lastUpdated'),
            liveBadge:          getElement('liveBadge'),
            liveBadgeText:      getElement('liveBadgeText'),
            logStatus:          getElement('logStatus'),
            overviewDescription:getElement('overviewDescription'),
            overviewTitle:      getElement('overviewTitle'),
            progressBar:        getElement('progressBar'),
            progressSummary:    getElement('progressSummary'),
            progressTrack:      getElement('progressTrack'),
            progressValue:      getElement('progressValue'),
            serviceCount:       getElement('serviceCount'),
            services:           getElement('services'),
            servicesEmpty:      getElement('servicesEmpty'),
            startupLog:         getElement('startupLog')
        });

        elements.copyLogs.addEventListener('click', copyStartupLog);
        refresh();
        globalScope.setInterval(refresh, POLL_INTERVAL);
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialize, { once: true });
    } else {
        initialize();
    }
})(typeof window !== 'undefined' ? window : globalThis);
