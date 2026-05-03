export const buildRegistryPluginInstallKey = (plugin = {}) => (
    [
        plugin?.registrySource?.id || "registry",
        plugin?.id || "",
        plugin?.version || plugin?.latestVersion || "",
    ].join(":")
);
