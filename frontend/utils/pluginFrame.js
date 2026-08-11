export const PLUGIN_FULLSCREEN_REQUEST = "wprint3d.plugin-fullscreen-request";
export const PLUGIN_HOST_CONTEXT_READY = "wprint3d.plugin-host-context-ready";
export const PLUGIN_HOST_ACTION_REQUEST = "wprint3d.plugin-host-action-request";
export const PLUGIN_HOST_ACTION_RESULT = "wprint3d.plugin-host-action-result";
export const PLUGIN_HOST_ACTION_PROGRESS = "wprint3d.plugin-host-action-progress";

export function getPluginThemeMode(theme) {
    return theme?.dark ? "dark" : "light";
}

export function buildPluginThemeTokens(theme) {
    const colors = theme?.colors || {};

    return {
        colorScheme: getPluginThemeMode(theme),
        primary: colors.primary,
        onPrimary: colors.onPrimary,
        primaryContainer: colors.primaryContainer,
        onPrimaryContainer: colors.onPrimaryContainer,
        secondary: colors.secondary,
        onSecondary: colors.onSecondary,
        secondaryContainer: colors.secondaryContainer,
        onSecondaryContainer: colors.onSecondaryContainer,
        tertiary: colors.tertiary,
        onTertiary: colors.onTertiary,
        tertiaryContainer: colors.tertiaryContainer,
        onTertiaryContainer: colors.onTertiaryContainer,
        surface: colors.surface,
        surfaceVariant: colors.surfaceVariant,
        background: colors.background,
        onBackground: colors.onBackground,
        onSurface: colors.onSurface,
        onSurfaceVariant: colors.onSurfaceVariant,
        outline: colors.outline,
        outlineVariant: colors.outlineVariant,
        elevation: colors.elevation || {},
        error: colors.error,
        onError: colors.onError,
        errorContainer: colors.errorContainer,
        onErrorContainer: colors.onErrorContainer,
        success: colors.success,
        onSuccess: colors.onSuccess,
        warning: colors.warning,
        onWarning: colors.onWarning,
    };
}

export function getPluginFrameIdentity(rawUrl) {
    if (!rawUrl) { return rawUrl; }

    try {
        const resolvedUrl = new URL(rawUrl, "https://wprint.invalid");
        // These values are delivered reactively over postMessage. Treating
        // them as navigation identity would remount the slicer and discard
        // its in-memory scene whenever the host changes printer or theme.
        ["theme", "locale", "fallbackLocale", "currentPrinterId"].forEach((parameter) => {
            resolvedUrl.searchParams.delete(parameter);
        });

        return `${resolvedUrl.origin}${resolvedUrl.pathname}${resolvedUrl.search}${resolvedUrl.hash}`;
    } catch (_error) {
        return rawUrl;
    }
}

export function readPluginFullscreenRequest(message) {
    if (!message || message.type !== PLUGIN_FULLSCREEN_REQUEST || typeof message.fullscreen !== "boolean") {
        return null;
    }

    return message.fullscreen;
}

export function readPluginHostActionRequest(message) {
    if (
        !message
        || message.type !== PLUGIN_HOST_ACTION_REQUEST
        || typeof message.requestId !== "string"
        || message.requestId.length < 1
        || message.requestId.length > 128
        || typeof message.action !== "string"
        || !/^[a-z][a-z0-9.-]{0,63}$/.test(message.action)
        || (
            message.payload !== undefined
            && (
                !message.payload
                || typeof message.payload !== "object"
                || Array.isArray(message.payload)
            )
        )
    ) {
        return null;
    }

    return {
        requestId: message.requestId,
        action: message.action,
        payload: message.payload || {},
    };
}

export function buildPluginHostActionResult(requestId, result, error) {
    if (error) {
        return {
            type: PLUGIN_HOST_ACTION_RESULT,
            requestId,
            ok: false,
            error,
            ...(result === undefined ? {} : { result }),
        };
    }

    return {
        type: PLUGIN_HOST_ACTION_RESULT,
        requestId,
        ok: true,
        result,
    };
}

export function buildPluginHostActionProgress(requestId, progress) {
    return {
        type: PLUGIN_HOST_ACTION_PROGRESS,
        requestId,
        progress: {
            stage: typeof progress?.stage === "string" ? progress.stage : "transferring",
            percent: Math.min(100, Math.max(0, Number(progress?.percent) || 0)),
            ...(typeof progress?.message === "string" ? { message: progress.message } : {}),
            ...(typeof progress?.transferId === "string" ? { transferId: progress.transferId } : {}),
        },
    };
}
