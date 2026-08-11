export const DEFAULT_PLUGIN_NAVIGATION_ICON = "puzzle";

export function shouldUseCompactWorkspaceTabs(windowWidth) {
    return windowWidth <= 1024;
}

export function getPluginNavigationIcon(extension) {
    return extension?.icon || DEFAULT_PLUGIN_NAVIGATION_ICON;
}

export function getPluginNavigationLabel(extension) {
    return extension?.navigationLabel || extension?.title || extension?.pluginName || "Plugin";
}

export function getPluginNavigationRoute(extension) {
    const icon = getPluginNavigationIcon(extension);

    return {
        key: `plugin:${extension.pluginId}:${extension.id}`,
        title: getPluginNavigationLabel(extension),
        accessibilityLabel: extension.title || extension.pluginName || getPluginNavigationLabel(extension),
        focusedIcon: icon,
        unfocusedIcon: icon,
        extension,
    };
}

export function getPluginHostNavigationIndex(destination, { mobile = false } = {}) {
    if (destination === "files" && mobile) { return 0; }
    const desktopDestinations = {
        terminal: 0,
        preview: 1,
        control: 2,
        recordings: 3,
    };
    const mobileDestinations = {
        home: 0,
        terminal: 1,
        preview: 2,
        control: 3,
        recordings: 4,
    };

    return (mobile ? mobileDestinations : desktopDestinations)[destination] ?? null;
}
