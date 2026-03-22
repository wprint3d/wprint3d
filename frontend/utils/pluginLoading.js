export const PLUGIN_LOADING_SETTLE_DELAY_MS = 400;

export function shouldFinalizePluginLoadingSession({
    isLoading,
    totalPlugins,
    loadedPlugins,
    lastActivityAt,
    now = Date.now(),
    settleDelayMs = PLUGIN_LOADING_SETTLE_DELAY_MS,
}) {
    if (isLoading || totalPlugins <= 0 || loadedPlugins !== totalPlugins) {
        return false;
    }

    if (!Number.isFinite(lastActivityAt)) {
        return false;
    }

    return (now - lastActivityAt) >= settleDelayMs;
}

export function getPluginLoadingToastState({
    isLoading,
    sessionActive,
    showCompletedToast,
    totalPlugins,
    loadedPlugins,
}) {
    const isSettling = (
        sessionActive &&
        !isLoading &&
        !showCompletedToast &&
        totalPlugins > 0 &&
        loadedPlugins === totalPlugins
    );

    return {
        visible: isLoading || showCompletedToast || isSettling,
        isComplete: showCompletedToast,
    };
}
