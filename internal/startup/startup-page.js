(function (globalScope) {
    'use strict';

    const POLL_INTERVAL = 1500;
    const TIMER_INTERVAL = 1000;
    const DELAYED_REVEAL_MILLISECONDS = 15000;
    const METRICS_STALE_MILLISECONDS = 6000;
    const LOW_ACTIVITY_MILLISECONDS = 60000;
    const HINT_MINIMUM_MILLISECONDS = 12000;
    const PRESSURE_SAMPLE_COUNT = 3;
    const CPU_PRESSURE_PERCENT = 85;
    const MEMORY_PRESSURE_PERCENT = 90;
    const STORAGE_PRESSURE_PERCENT = 80;
    const IO_WAIT_PRESSURE_PERCENT = 20;
    const CPU_ACTIVITY_PERCENT = 20;
    const IO_ACTIVITY_BYTES_PER_SECOND = 256 * 1024;
    const PRESSURE_SWITCH_MARGIN = 0.15;

    const SERVICE_NAME_KEYS = {
        'proxy':                 'serviceProxy',
        'web':                   'serviceWeb',
        'streamer':              'serviceStreamer',
        'yv-streamer-software':  'serviceYvStreamer',
        'mapper':                'serviceMapper',
        'backend':               'serviceBackend',
        'ws-server':             'serviceWebSockets',
        'scheduler':             'serviceScheduler',
        'concurrency-scheduler': 'serviceConcurrency',
        'queue':                 'serviceQueue',
        'mongo':                 'serviceMongo',
        'redis':                 'serviceRedis',
        'memcached':             'serviceMemcached'
    };

    const DEFAULT_DISPLAY_NAMES = {
        'proxy':                 'Proxy',
        'web':                   'Web UI',
        'streamer':              'Camera streamer',
        'yv-streamer-software':  'YV camera streamer',
        'mapper':                'Device mapper',
        'backend':               'Backend API',
        'ws-server':             'WebSockets server',
        'scheduler':             'Task scheduler',
        'concurrency-scheduler': 'Concurrency scheduler',
        'queue':                 'Queue workers',
        'mongo':                 'Persistent database',
        'redis':                 'Redis cache',
        'memcached':             'Memcached cache'
    };

    const STATUS_KEYS = {
        waiting:     'serviceWaiting',
        starting:    'serviceStarting',
        running:     'serviceRunning',
        unavailable: 'serviceUnavailable'
    };

    const PROCESS_KEYS = {
        metro:    'processMetro',
        composer: 'processComposer',
        laravel:  'processLaravel',
        mongodb:  'processMongoDb',
        redis:    'processRedis',
        proxy:    'processProxy',
        docker:   'processDocker'
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

    const formatServiceLabel = (serviceName, normalizeServiceName, translate) => {
        const normalizer = normalizeServiceName
            ?? globalScope.startupServiceNameUtils?.normalizeStartupServiceName;
        const simplifiedName = normalizer
            ? normalizer(serviceName)
            : String(serviceName ?? '').trim();
        const translationKey = SERVICE_NAME_KEYS[simplifiedName];

        if (translationKey && typeof translate === 'function') {
            return translate(translationKey);
        }

        return DEFAULT_DISPLAY_NAMES[simplifiedName] ?? humanizeServiceName(simplifiedName);
    };

    const finiteNumber = (value, minimum = 0, maximum = Number.MAX_SAFE_INTEGER) => {
        const parsedValue = Number(value);

        if (!Number.isFinite(parsedValue)) {
            return minimum;
        }

        return Math.min(maximum, Math.max(minimum, parsedValue));
    };

    const sanitizePublicProcessName = (value) => {
        const sanitized = String(value ?? '')
            .replace(/[^A-Za-z0-9._+-]/g, '_')
            .replace(/^[._-]+|[._-]+$/g, '')
            .slice(0, 32);

        return sanitized || 'process';
    };

    const normalizeLeader = (value) => {
        if (!value || typeof value !== 'object') {
            return null;
        }

        const knownKind = Object.prototype.hasOwnProperty.call(PROCESS_KEYS, value.kind)
            ? value.kind
            : 'other';
        const leader = {
            kind: knownKind,
            cpuPercent: finiteNumber(value.cpuPercent, 0, 100),
            residentBytes: Math.round(finiteNumber(value.residentBytes)),
            ioBytesPerSecond: Math.round(finiteNumber(value.ioBytesPerSecond))
        };

        if (knownKind === 'other') {
            leader.name = sanitizePublicProcessName(value.name);
        }

        return leader;
    };

    const normalizeHostMetrics = (value) => {
        let parsedValue = value;

        if (typeof parsedValue === 'string') {
            try {
                parsedValue = JSON.parse(parsedValue);
            } catch (_error) {
                return null;
            }
        }

        if (!parsedValue || typeof parsedValue !== 'object' || parsedValue.version !== 1) {
            return null;
        }

        if (!Number.isInteger(parsedValue.sequence) || parsedValue.sequence < 0) {
            return null;
        }

        const normalizeLeaders = (resource) => (
            Array.isArray(parsedValue.leaders?.[resource])
                ? parsedValue.leaders[resource].map(normalizeLeader).filter(Boolean).slice(0, 3)
                : []
        );

        return {
            version: 1,
            sequence: parsedValue.sequence,
            sampledAt: Math.round(finiteNumber(parsedValue.sampledAt)),
            intervalMs: Math.round(finiteNumber(parsedValue.intervalMs, 1, 60000)),
            cpu: {
                usedPercent: finiteNumber(parsedValue.cpu?.usedPercent, 0, 100),
                ioWaitPercent: finiteNumber(parsedValue.cpu?.ioWaitPercent, 0, 100)
            },
            memory: {
                usedBytes: Math.round(finiteNumber(parsedValue.memory?.usedBytes)),
                totalBytes: Math.round(finiteNumber(parsedValue.memory?.totalBytes)),
                usedPercent: finiteNumber(parsedValue.memory?.usedPercent, 0, 100)
            },
            storage: {
                readBytesPerSecond: Math.round(finiteNumber(parsedValue.storage?.readBytesPerSecond)),
                writeBytesPerSecond: Math.round(finiteNumber(parsedValue.storage?.writeBytesPerSecond)),
                busyPercent: finiteNumber(parsedValue.storage?.busyPercent, 0, 100)
            },
            leaders: {
                cpu: normalizeLeaders('cpu'),
                memory: normalizeLeaders('memory'),
                io: normalizeLeaders('io')
            }
        };
    };

    const createDiagnosticsState = (now = Date.now()) => ({
        revealed: false,
        lastProgressAt: now,
        lastLogChangeAt: now,
        lastMetricAdvanceAt: null,
        lastSequence: null,
        latestMetrics: null,
        pressureStreaks: { cpu: 0, memory: 0, storage: 0 },
        activeHint: null,
        activeHintSince: null,
        recoveryStreak: 0,
        challenger: null,
        challengerStreak: 0
    });

    const markStartupProgress = (diagnostics, now = Date.now(), { logChanged = false } = {}) => {
        diagnostics.lastProgressAt = now;

        if (logChanged) {
            diagnostics.lastLogChangeAt = now;
        }

        return diagnostics;
    };

    const updateDiagnosticsVisibility = (diagnostics, now = Date.now()) => {
        if (!diagnostics.revealed && now - diagnostics.lastProgressAt >= DELAYED_REVEAL_MILLISECONDS) {
            diagnostics.revealed = true;
        }

        return diagnostics.revealed;
    };

    const getPressureScores = (metrics) => ({
        cpu: metrics.cpu.usedPercent / CPU_PRESSURE_PERCENT,
        memory: metrics.memory.usedPercent / MEMORY_PRESSURE_PERCENT,
        storage: Math.max(
            metrics.storage.busyPercent / STORAGE_PRESSURE_PERCENT,
            metrics.cpu.ioWaitPercent / IO_WAIT_PRESSURE_PERCENT
        )
    });

    const isHostActive = (metrics) => (
        metrics.cpu.usedPercent >= CPU_ACTIVITY_PERCENT
        || metrics.storage.readBytesPerSecond + metrics.storage.writeBytesPerSecond >= IO_ACTIVITY_BYTES_PER_SECOND
    );

    const chooseBaseHint = (diagnostics, metrics, now) => (
        now - diagnostics.lastProgressAt >= LOW_ACTIVITY_MILLISECONDS && !isHostActive(metrics)
            ? 'lowActivity'
            : 'active'
    );

    const setActiveHint = (diagnostics, hint, now) => {
        if (diagnostics.activeHint === hint) {
            return;
        }

        diagnostics.activeHint = hint;
        diagnostics.activeHintSince = now;
        diagnostics.recoveryStreak = 0;
        diagnostics.challenger = null;
        diagnostics.challengerStreak = 0;
    };

    const ingestHostMetrics = (diagnostics, metrics, now = Date.now()) => {
        if (!metrics || metrics.sequence === diagnostics.lastSequence) {
            return false;
        }

        const previousSequence = diagnostics.lastSequence;
        const previousMetricAdvanceAt = diagnostics.lastMetricAdvanceAt;
        const samplesWereStale = previousMetricAdvanceAt !== null
            && now - previousMetricAdvanceAt >= METRICS_STALE_MILLISECONDS;
        const samplesAreContinuous = previousSequence === null
            || (
                metrics.sequence === previousSequence + 1
                && !samplesWereStale
            );

        if (!samplesAreContinuous) {
            diagnostics.pressureStreaks = { cpu: 0, memory: 0, storage: 0 };
            diagnostics.recoveryStreak = 0;
            diagnostics.challenger = null;
            diagnostics.challengerStreak = 0;

            if (samplesWereStale) {
                diagnostics.activeHint = null;
                diagnostics.activeHintSince = null;
            }
        }

        diagnostics.lastSequence = metrics.sequence;
        diagnostics.lastMetricAdvanceAt = now;
        diagnostics.latestMetrics = metrics;

        const scores = getPressureScores(metrics);

        Object.keys(diagnostics.pressureStreaks).forEach((resource) => {
            diagnostics.pressureStreaks[resource] = scores[resource] >= 1
                ? diagnostics.pressureStreaks[resource] + 1
                : 0;
        });

        const candidate = Object.keys(scores)
            .filter((resource) => diagnostics.pressureStreaks[resource] >= PRESSURE_SAMPLE_COUNT)
            .sort((left, right) => scores[right] - scores[left])[0] ?? null;
        const rawChallenger = Object.keys(scores)
            .filter((resource) => scores[resource] >= 1)
            .sort((left, right) => scores[right] - scores[left])[0] ?? null;
        const pressureHints = new Set(['cpu', 'memory', 'storage']);
        const currentIsPressure = pressureHints.has(diagnostics.activeHint);
        const hintAge = diagnostics.activeHintSince === null
            ? Number.POSITIVE_INFINITY
            : now - diagnostics.activeHintSince;

        if (!currentIsPressure) {
            if (candidate && hintAge >= HINT_MINIMUM_MILLISECONDS) {
                setActiveHint(diagnostics, candidate, now);
            } else if (diagnostics.activeHint === null) {
                setActiveHint(diagnostics, chooseBaseHint(diagnostics, metrics, now), now);
            }

            return true;
        }

        const currentScore = scores[diagnostics.activeHint] ?? 0;
        diagnostics.recoveryStreak = currentScore < 1 ? diagnostics.recoveryStreak + 1 : 0;

        if (
            rawChallenger
            && rawChallenger !== diagnostics.activeHint
            && scores[rawChallenger] >= currentScore + PRESSURE_SWITCH_MARGIN
        ) {
            if (diagnostics.challenger === rawChallenger) {
                diagnostics.challengerStreak += 1;
            } else {
                diagnostics.challenger = rawChallenger;
                diagnostics.challengerStreak = 1;
            }
        } else {
            diagnostics.challenger = null;
            diagnostics.challengerStreak = 0;
        }

        if (hintAge < HINT_MINIMUM_MILLISECONDS) {
            return true;
        }

        if (diagnostics.challengerStreak >= PRESSURE_SAMPLE_COUNT) {
            setActiveHint(diagnostics, diagnostics.challenger, now);
        } else if (diagnostics.recoveryStreak >= PRESSURE_SAMPLE_COUNT) {
            setActiveHint(diagnostics, candidate ?? chooseBaseHint(diagnostics, metrics, now), now);
        }

        return true;
    };

    const refreshNonPressureHint = (diagnostics, now = Date.now()) => {
        const metrics = diagnostics.latestMetrics;

        if (!metrics || ['cpu', 'memory', 'storage'].includes(diagnostics.activeHint)) {
            return diagnostics.activeHint;
        }

        const nextHint = chooseBaseHint(diagnostics, metrics, now);
        const hintAge = diagnostics.activeHintSince === null
            ? Number.POSITIVE_INFINITY
            : now - diagnostics.activeHintSince;

        if (nextHint !== diagnostics.activeHint && hintAge >= HINT_MINIMUM_MILLISECONDS) {
            setActiveHint(diagnostics, nextHint, now);
        }

        return diagnostics.activeHint;
    };

    const areHostMetricsStale = (diagnostics, now = Date.now()) => (
        diagnostics.lastMetricAdvanceAt === null
        || now - diagnostics.lastMetricAdvanceAt >= METRICS_STALE_MILLISECONDS
    );

    const startupPageUtils = {
        areHostMetricsStale,
        createDiagnosticsState,
        formatServiceLabel,
        getPressureScores,
        ingestHostMetrics,
        markStartupProgress,
        normalizeHostMetrics,
        normalizeLogText,
        parseServiceStatus,
        refreshNonPressureHint,
        summarizeStatuses,
        updateDiagnosticsVisibility
    };

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = startupPageUtils;
    }

    globalScope.startupPageUtils = startupPageUtils;

    if (typeof document === 'undefined') {
        return;
    }

    const localization = globalScope.startupLocalizationUtils;
    const state = {
        services: [],
        statuses: new Map(),
        serviceElements: new Map(),
        logText: '',
        logState: 'waiting',
        logUpdatedAt: null,
        hasReceivedStartupLog: false,
        fetchingServices: false,
        fetchingStatuses: false,
        fetchingStartupLog: false,
        fetchingHostMetrics: false,
        copyingLog: false,
        copyState: 'idle',
        copyResetTimer: null,
        emptyServiceState: 'waiting',
        diagnostics: createDiagnosticsState(),
        language: 'en',
        t: (key) => key
    };

    const elements = {};
    const getElement = (id) => document.getElementById(id);
    const localeTag = () => state.language.replace('_', '-');

    const formatTime = (date) => date.toLocaleTimeString(localeTag(), {
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit'
    });

    const formatNumber = (value, maximumFractionDigits = 1) => new Intl.NumberFormat(localeTag(), {
        maximumFractionDigits,
        minimumFractionDigits: maximumFractionDigits > 0 && value > 0 && value < 10 ? 1 : 0
    }).format(value);

    const formatPercent = (value) => `${formatNumber(value)}%`;

    const formatDuration = (milliseconds) => {
        const seconds = Math.max(0, Math.floor(milliseconds / 1000));

        if (seconds < 60) {
            return state.t('durationSeconds', { count: seconds });
        }

        return state.t('durationMinutes', { count: Math.floor(seconds / 60) });
    };

    const formatBytes = (value) => {
        const units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
        let normalizedValue = Math.max(0, Number(value) || 0);
        let unitIndex = 0;

        while (normalizedValue >= 1024 && unitIndex < units.length - 1) {
            normalizedValue /= 1024;
            unitIndex += 1;
        }

        return `${formatNumber(normalizedValue)} ${units[unitIndex]}`;
    };

    const formatByteRate = (value) => `${formatBytes(value)}/s`;

    const fetchText = (path) => fetch(path, {
        cache: 'no-store',
        headers: { Accept: 'text/plain' }
    });

    const markProgress = ({ logChanged = false } = {}) => {
        markStartupProgress(state.diagnostics, Date.now(), { logChanged });
    };

    const renderEmptyServicesMessage = () => {
        if (!elements.servicesEmpty?.isConnected) {
            return;
        }

        const messages = {
            waiting: ['emptyWaitingTitle', 'emptyWaitingDescription'],
            preparing: ['emptyWaitingTitle', 'emptyPreparingDescription'],
            empty: ['emptyNoServicesTitle', 'emptyNoServicesDescription'],
            unavailable: ['emptyUnavailableTitle', 'emptyUnavailableDescription']
        };
        const [titleKey, descriptionKey] = messages[state.emptyServiceState] ?? messages.waiting;

        elements.servicesEmpty.querySelector('strong').textContent = state.t(titleKey);
        elements.servicesEmpty.querySelector('p').textContent = state.t(descriptionKey);
    };

    const createServiceCard = (service) => {
        const card = document.createElement('li');
        const indicator = document.createElement('span');
        const information = document.createElement('span');
        const displayName = document.createElement('strong');
        const internalName = document.createElement('code');
        const status = document.createElement('span');
        const serviceStatus = state.statuses.get(service) ?? 'waiting';

        card.className = 'service-card';
        card.dataset.internalName = service;
        card.dataset.status = serviceStatus;

        indicator.className = 'service-indicator';
        indicator.setAttribute('aria-hidden', 'true');

        displayName.className = 'service-name';
        displayName.textContent = formatServiceLabel(service, undefined, state.t);

        internalName.className = 'service-internal-name';
        internalName.textContent = service;
        internalName.title = service;

        information.append(displayName, internalName);

        status.className = 'service-status';
        status.textContent = state.t(STATUS_KEYS[serviceStatus] ?? STATUS_KEYS.unavailable);

        card.append(indicator, information, status);
        state.serviceElements.set(service, { card, status });

        return card;
    };

    const renderServices = () => {
        state.serviceElements.clear();
        elements.services.replaceChildren();

        if (state.services.length === 0) {
            elements.services.append(elements.servicesEmpty);
            renderEmptyServicesMessage();
        } else {
            state.services.forEach((service) => elements.services.append(createServiceCard(service)));
        }

        elements.serviceCount.textContent = String(state.services.length);
        elements.serviceCount.setAttribute(
            'aria-label',
            state.t(state.services.length === 1 ? 'serviceCountOne' : 'serviceCountMany', {
                count: state.services.length
            })
        );
    };

    const updateServiceCard = (service, serviceStatus) => {
        const serviceElements = state.serviceElements.get(service);

        if (!serviceElements) {
            return;
        }

        serviceElements.card.dataset.status = serviceStatus;
        serviceElements.status.textContent = state.t(STATUS_KEYS[serviceStatus] ?? STATUS_KEYS.unavailable);
    };

    const updateOverview = () => {
        const summary = summarizeStatuses(state.services.map((service) => state.statuses.get(service) ?? 'waiting'));
        let titleKey = 'overviewDiscoverTitle';
        let descriptionKey = 'overviewDiscoverDescription';
        let descriptionParams = {};
        let badgeState = 'starting';
        let badgeKey = 'badgeStarting';

        if (summary.total > 0 && summary.running === summary.total) {
            titleKey = 'overviewOnlineTitle';
            descriptionKey = 'overviewOnlineDescription';
            badgeState = 'running';
            badgeKey = 'badgeOnline';
        } else if (summary.total > 0 && summary.unavailable > 0) {
            titleKey = 'overviewUnavailableTitle';
            descriptionKey = summary.unavailable === 1 ? 'overviewUnavailableOne' : 'overviewUnavailableMany';
            descriptionParams = { count: summary.unavailable };
            badgeState = 'unavailable';
            badgeKey = 'badgeRetrying';
        } else if (summary.total > 0) {
            const pendingCount = summary.starting + summary.waiting;
            titleKey = 'overviewStartingTitle';
            descriptionKey = pendingCount === 1 ? 'overviewStartingOne' : 'overviewStartingMany';
            descriptionParams = { count: pendingCount };
        }

        elements.overviewTitle.textContent = state.t(titleKey);
        elements.overviewDescription.textContent = state.t(descriptionKey, descriptionParams);
        elements.progressValue.textContent = `${summary.percent}%`;
        elements.progressBar.style.width = `${summary.percent}%`;
        elements.progressTrack.setAttribute('aria-valuenow', String(summary.percent));
        elements.progressTrack.setAttribute(
            'aria-valuetext',
            summary.total > 0
                ? state.t('progressValue', { running: summary.running, total: summary.total })
                : state.t('progressWaiting')
        );
        elements.progressSummary.textContent = state.t(
            summary.total === 1 ? 'progressSummaryOne' : 'progressSummaryMany',
            { running: summary.running, total: summary.total }
        );

        elements.liveBadge.dataset.state = badgeState;
        elements.liveBadgeText.textContent = state.t(badgeKey);
        document.title = state.t(
            summary.total > 0 ? 'documentProgress' : 'documentStarting',
            { percent: summary.percent }
        );
    };

    const fetchServicesList = async () => {
        if (state.fetchingServices) {
            return;
        }

        state.fetchingServices = true;

        try {
            const response = await fetchText('services.txt');

            if (response.status === 502) {
                state.emptyServiceState = 'preparing';
                renderEmptyServicesMessage();
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
                state.emptyServiceState = 'empty';
                renderEmptyServicesMessage();
                return;
            }

            const serviceListChanged = services.length !== state.services.length
                || services.some((service, index) => state.services[index] !== service);

            if (!serviceListChanged) {
                return;
            }

            state.services = services;
            state.services.forEach((service) => {
                if (!state.statuses.has(service)) {
                    state.statuses.set(service, 'waiting');
                }
            });
            markProgress();
            renderServices();
            updateOverview();
        } catch (error) {
            console.error(error);
            state.emptyServiceState = 'unavailable';
            renderEmptyServicesMessage();
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
                const previousStatus = state.statuses.get(service);

                if (previousStatus !== status) {
                    state.statuses.set(service, status);
                    updateServiceCard(service, status);
                    markProgress();
                }
            });

            elements.lastUpdated.textContent = state.t('lastChecked', { time: formatTime(new Date()) });
            updateOverview();
        } finally {
            state.fetchingStatuses = false;
        }
    };

    const renderLogStatus = (now = Date.now()) => {
        if (state.logState === 'waiting') {
            elements.logStatus.textContent = state.t('logsControllerWaiting');
            return;
        }

        if (state.logState === 'unavailable') {
            elements.logStatus.textContent = state.t('logsUnavailable');
            return;
        }

        const timeSinceChange = now - state.diagnostics.lastLogChangeAt;

        elements.logStatus.textContent = timeSinceChange < POLL_INTERVAL * 2 && state.logUpdatedAt
            ? state.t('logsLiveUpdated', { time: formatTime(state.logUpdatedAt) })
            : state.t('logsNoChanges', { duration: formatDuration(timeSinceChange) });
    };

    const setLogContent = (content) => {
        const normalizedContent = normalizeLogText(content);
        const contentChanged = normalizedContent !== state.logText;
        const shouldFollowLog = !state.hasReceivedStartupLog
            || elements.startupLog.scrollHeight - elements.startupLog.scrollTop - elements.startupLog.clientHeight < 48;

        if (contentChanged) {
            state.logText = normalizedContent;
            state.logUpdatedAt = new Date();
            markProgress({ logChanged: true });
        }

        state.logState = 'live';
        elements.startupLog.textContent = normalizedContent || state.t('logsEmpty');
        elements.copyLogs.disabled = normalizedContent.length === 0 || state.copyingLog;

        if (shouldFollowLog && normalizedContent.length > 0) {
            elements.startupLog.scrollTop = elements.startupLog.scrollHeight;
        }

        state.hasReceivedStartupLog = true;
        renderLogStatus();
    };

    const fetchStartupLog = async () => {
        if (state.fetchingStartupLog) {
            return;
        }

        state.fetchingStartupLog = true;

        try {
            const response = await fetchText('startup.txt');

            if (response.status === 502) {
                state.logState = 'waiting';

                if (!state.hasReceivedStartupLog) {
                    elements.startupLog.textContent = state.t('logsWaiting');
                }

                renderLogStatus();
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

            setLogContent(await response.text());
        } catch (error) {
            console.error(error);
            state.logState = 'unavailable';
            renderLogStatus();
        } finally {
            state.fetchingStartupLog = false;
        }
    };

    const fetchHostMetrics = async () => {
        if (state.fetchingHostMetrics) {
            return;
        }

        state.fetchingHostMetrics = true;

        try {
            const response = await fetchText('host-metrics.txt');

            if (!response.ok) {
                return;
            }

            const metrics = normalizeHostMetrics(await response.text());

            if (metrics) {
                ingestHostMetrics(state.diagnostics, metrics, Date.now());
            }
        } catch (error) {
            console.error('Could not fetch host metrics.', error);
        } finally {
            state.fetchingHostMetrics = false;
        }
    };

    const processLabel = (leader) => (
        leader.kind === 'other'
            ? leader.name
            : state.t(PROCESS_KEYS[leader.kind] ?? 'noProcesses')
    );

    const renderProcessLeaders = (resource, metrics) => {
        elements.processList.replaceChildren();

        const leaders = metrics.leaders[resource] ?? [];

        if (leaders.length === 0) {
            const empty = document.createElement('span');
            empty.className = 'process-empty';
            empty.textContent = state.t('noProcesses');
            elements.processList.append(empty);
            return;
        }

        leaders.forEach((leader) => {
            const chip = document.createElement('span');
            const label = processLabel(leader);
            let details = `${formatPercent(leader.cpuPercent)} CPU · ${formatBytes(leader.residentBytes)}`;

            if (resource === 'memory') {
                details = `${formatBytes(leader.residentBytes)} · ${formatPercent(leader.cpuPercent)} CPU`;
            } else if (resource === 'io') {
                details = `${formatByteRate(leader.ioBytesPerSecond)} · ${formatPercent(leader.cpuPercent)} CPU`;
            }

            chip.className = 'process-chip';
            chip.textContent = `${label} · ${details}`;
            elements.processList.append(chip);
        });
    };

    const renderHint = (hint, metrics, stale) => {
        if (stale || !metrics) {
            elements.hostHintText.textContent = state.t('hintUnavailable');
            return;
        }

        const resource = hint === 'memory' ? 'memory' : hint === 'storage' ? 'io' : 'cpu';
        const leader = metrics.leaders[resource]?.[0] ?? null;
        const process = leader ? processLabel(leader) : null;
        let key = 'hintActive';

        if (hint === 'cpu') {
            key = process
                ? leader.kind === 'metro' ? 'hintCpuMetro' : 'hintCpuProcess'
                : 'hintCpuGeneric';
        } else if (hint === 'memory') {
            key = process ? 'hintMemoryProcess' : 'hintMemoryGeneric';
        } else if (hint === 'storage') {
            key = process ? 'hintStorageProcess' : 'hintStorageGeneric';
        } else if (hint === 'lowActivity') {
            key = 'hintLowActivity';
        }

        elements.hostHintText.textContent = state.t(key, { process });
    };

    const renderHostMetrics = (now = Date.now()) => {
        const diagnostics = state.diagnostics;
        const revealed = updateDiagnosticsVisibility(diagnostics, now);

        elements.startupShell.classList.toggle('has-diagnostics', revealed);
        elements.hostMetrics.hidden = !revealed;

        if (!revealed) {
            return;
        }

        refreshNonPressureHint(diagnostics, now);

        const metrics = diagnostics.latestMetrics;
        const stale = areHostMetricsStale(diagnostics, now);
        elements.hostMetrics.dataset.stale = String(stale);

        if (!metrics) {
            elements.metricsStatus.textContent = state.t('metricsWaiting');
            elements.cpuValue.textContent = '—';
            elements.memoryValue.textContent = '—';
            elements.memoryDetail.textContent = '—';
            elements.storageRead.textContent = '—';
            elements.storageWrite.textContent = '—';
            elements.cpuHigh.hidden = true;
            elements.memoryHigh.hidden = true;
            elements.storageHigh.hidden = true;
            renderProcessLeaders('cpu', { leaders: { cpu: [] } });
            renderHint(null, null, true);
            return;
        }

        elements.metricsStatus.textContent = stale
            ? state.t('metricsDelayed')
            : state.t('metricsUpdated', {
                duration: formatDuration(Math.max(
                    0,
                    now - (metrics.sampledAt || diagnostics.lastMetricAdvanceAt)
                ))
            });
        elements.cpuValue.textContent = formatPercent(metrics.cpu.usedPercent);
        elements.memoryValue.textContent = formatPercent(metrics.memory.usedPercent);
        elements.memoryDetail.textContent = state.t('memoryUsed', {
            used: formatBytes(metrics.memory.usedBytes),
            total: formatBytes(metrics.memory.totalBytes)
        });
        elements.storageRead.textContent = state.t('readRate', {
            rate: formatByteRate(metrics.storage.readBytesPerSecond)
        });
        elements.storageWrite.textContent = state.t('writeRate', {
            rate: formatByteRate(metrics.storage.writeBytesPerSecond)
        });

        const pressureScores = getPressureScores(metrics);
        const sustainedPressure = {
            cpu: diagnostics.pressureStreaks.cpu >= PRESSURE_SAMPLE_COUNT,
            memory: diagnostics.pressureStreaks.memory >= PRESSURE_SAMPLE_COUNT,
            storage: diagnostics.pressureStreaks.storage >= PRESSURE_SAMPLE_COUNT
        };
        elements.cpuHigh.hidden = !sustainedPressure.cpu;
        elements.memoryHigh.hidden = !sustainedPressure.memory;
        elements.storageHigh.hidden = !sustainedPressure.storage;
        elements.cpuMetric.dataset.pressure = String(sustainedPressure.cpu);
        elements.memoryMetric.dataset.pressure = String(sustainedPressure.memory);
        elements.storageMetric.dataset.pressure = String(sustainedPressure.storage);

        const dominantResource = Object.keys(pressureScores)
            .sort((left, right) => pressureScores[right] - pressureScores[left])[0];
        const hintedResource = ['cpu', 'memory', 'storage'].includes(diagnostics.activeHint)
            ? diagnostics.activeHint
            : dominantResource;
        const leaderResource = hintedResource === 'storage' ? 'io' : hintedResource;
        renderProcessLeaders(leaderResource, metrics);
        renderHint(diagnostics.activeHint, metrics, stale);
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

    const renderCopyState = () => {
        const keys = {
            idle: 'copyLogs',
            copied: 'copied',
            failed: 'copyFailed'
        };

        elements.copyButtonLabel.textContent = state.t(keys[state.copyState] ?? keys.idle);
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

            state.copyState = 'copied';
            elements.copyFeedback.textContent = state.t('copySuccessFeedback');
        } catch (error) {
            console.error(error);
            state.copyState = 'failed';
            elements.copyFeedback.textContent = state.t('copyFailedFeedback');
        } finally {
            renderCopyState();
            state.copyingLog = false;
            elements.copyLogs.disabled = state.logText.length === 0;
            state.copyResetTimer = globalScope.setTimeout(() => {
                state.copyState = 'idle';
                renderCopyState();
            }, 2000);
        }
    };

    const applyLanguage = () => {
        state.language = localization?.resolveStartupLanguage?.() ?? 'en';
        state.t = localization?.createStartupTranslator?.(state.language) ?? ((key) => key);
        document.documentElement.lang = localeTag();

        document.querySelectorAll('[data-i18n]').forEach((element) => {
            element.textContent = state.t(element.dataset.i18n);
        });
        document.querySelectorAll('[data-i18n-aria-label]').forEach((element) => {
            element.setAttribute('aria-label', state.t(element.dataset.i18nAriaLabel));
        });

        if (!state.hasReceivedStartupLog) {
            elements.startupLog.textContent = state.t('logsWaiting');
        } else {
            elements.startupLog.textContent = state.logText || state.t('logsEmpty');
        }

        renderServices();
        updateOverview();
        renderLogStatus();
        renderCopyState();
        renderHostMetrics();
    };

    const refresh = async () => {
        await Promise.all([
            fetchStartupLog(),
            fetchHostMetrics(),
            fetchServicesList().then(refreshServicesStatus)
        ]);
        renderHostMetrics();
    };

    const renderTimers = () => {
        renderLogStatus();
        renderHostMetrics();
    };

    const initialize = () => {
        Object.assign(elements, {
            activeProcessesTitle: getElement('activeProcessesTitle'),
            copyButtonLabel: getElement('copyButtonLabel'),
            copyFeedback: getElement('copyFeedback'),
            copyLogs: getElement('copyLogs'),
            cpuHigh: getElement('cpuHigh'),
            cpuMetric: getElement('cpuMetric'),
            cpuValue: getElement('cpuValue'),
            hostHintText: getElement('hostHintText'),
            hostMetrics: getElement('hostMetrics'),
            lastUpdated: getElement('lastUpdated'),
            liveBadge: getElement('liveBadge'),
            liveBadgeText: getElement('liveBadgeText'),
            logStatus: getElement('logStatus'),
            memoryDetail: getElement('memoryDetail'),
            memoryHigh: getElement('memoryHigh'),
            memoryMetric: getElement('memoryMetric'),
            memoryValue: getElement('memoryValue'),
            metricsStatus: getElement('metricsStatus'),
            overviewDescription: getElement('overviewDescription'),
            overviewTitle: getElement('overviewTitle'),
            processList: getElement('processList'),
            progressBar: getElement('progressBar'),
            progressSummary: getElement('progressSummary'),
            progressTrack: getElement('progressTrack'),
            progressValue: getElement('progressValue'),
            serviceCount: getElement('serviceCount'),
            services: getElement('services'),
            servicesEmpty: getElement('servicesEmpty'),
            startupLog: getElement('startupLog'),
            startupShell: getElement('startupShell'),
            storageHigh: getElement('storageHigh'),
            storageMetric: getElement('storageMetric'),
            storageRead: getElement('storageRead'),
            storageWrite: getElement('storageWrite')
        });

        elements.copyLogs.addEventListener('click', copyStartupLog);
        globalScope.addEventListener('languagechange', applyLanguage);
        globalScope.addEventListener('storage', (event) => {
            if (event.key === 'wprint3d.language') {
                applyLanguage();
            }
        });

        applyLanguage();
        refresh();
        globalScope.setInterval(refresh, POLL_INTERVAL);
        globalScope.setInterval(renderTimers, TIMER_INTERVAL);
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialize, { once: true });
    } else {
        initialize();
    }
})(typeof window !== 'undefined' ? window : globalThis);
