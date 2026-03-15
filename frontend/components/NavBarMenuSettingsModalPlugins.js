import { memo, useEffect, useMemo, useState } from "react";
import { Platform, Pressable, ScrollView, View, useWindowDimensions } from "react-native";
import * as DocumentPicker from "expo-document-picker";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Button, Card, Checkbox, Chip, Divider, Icon, IconButton, Menu, Searchbar, Switch, Text, TextInput, Tooltip, useTheme } from "react-native-paper";
import { useSnackbar } from "react-native-paper-snackbar-stack";
import API from "../includes/API";
import SimpleDialog from "./SimpleDialog";

const buildDependencyBadges = (plugin, theme) => {
  const badges = [];
  const dependencies = plugin.dependencies || {};

  badges.push(dependencies.classification === "heavyweight"
    ? {
        icon: "server-network",
        label: "Heavyweight",
        tooltip: dependencies.hint || "This plugin ships container image dependencies.",
        style: { backgroundColor: theme.colors.secondaryContainer },
        textStyle: { color: theme.colors.onSecondaryContainer },
      }
    : {
        icon: "leaf",
        label: "Lightweight",
        tooltip: dependencies.hint || "This plugin does not declare extra container image dependencies.",
        style: { backgroundColor: theme.colors.surfaceVariant },
        textStyle: { color: theme.colors.onSurfaceVariant },
      });

  if ((dependencies.images || []).length) {
    badges.push({
      icon: "package-variant-closed",
      label: `${dependencies.images.length} image${dependencies.images.length === 1 ? "" : "s"}`,
      tooltip: "Container images that WPrint 3D will prepare for this plugin.",
      style: { backgroundColor: theme.colors.primaryContainer },
      textStyle: { color: theme.colors.onPrimaryContainer },
    });
  }

  if (dependencies.host?.meetsRequirements === false) {
    badges.push({
      icon: "alert",
      label: "Host shortfall",
      tooltip: (dependencies.warnings || []).join(" ") || "This host is below the plugin's declared minimum requirements.",
      style: { backgroundColor: theme.colors.errorContainer },
      textStyle: { color: theme.colors.onErrorContainer },
    });
  }

  return badges;
};

const buildPluginBadges = (plugin, theme) => {
  const badges = [];

  if (plugin.loadStatus === "failed") {
    badges.push({
      icon: "alert-circle",
      label: "Failed to load",
      tooltip: plugin.lastError || "The plugin failed during startup or live source synchronization.",
      style: { backgroundColor: theme.colors.errorContainer },
      textStyle: { color: theme.colors.onErrorContainer },
    });
  } else if (plugin.enabled) {
    badges.push({
      icon: "check-circle",
      label: "Active",
      tooltip: "This plugin is enabled and its UI surfaces and actions are live.",
      style: { backgroundColor: theme.colors.primaryContainer },
      textStyle: { color: theme.colors.onPrimaryContainer },
    });
  } else {
    badges.push({
      icon: "pause-circle",
      label: "Disabled",
      tooltip: "This plugin is installed but currently inactive.",
      style: { backgroundColor: theme.colors.secondaryContainer },
      textStyle: { color: theme.colors.onSecondaryContainer },
    });
  }

  const trustBadges = {
    development: {
      icon: "flask",
      label: "Live source",
      tooltip: "Loaded from the development source mount and refreshed directly from disk.",
      style: { backgroundColor: theme.colors.inversePrimary },
      textStyle: { color: theme.colors.onPrimaryContainer },
    },
    official: {
      icon: "shield-check",
      label: "Official",
      tooltip: "Installed from the official reviewed registry.",
      style: { backgroundColor: theme.colors.tertiaryContainer },
      textStyle: { color: theme.colors.onTertiaryContainer },
    },
    signed: {
      icon: "certificate",
      label: "Signed",
      tooltip: "The plugin signature matched a trusted key on this WPrint 3D instance.",
      style: { backgroundColor: theme.colors.success || "#0a9900" },
      textStyle: { color: "#ffffff" },
    },
    trusted: {
      icon: "shield-check",
      label: "Trusted",
      tooltip: "Signed or otherwise trusted by this WPrint 3D instance.",
      style: { backgroundColor: theme.colors.tertiaryContainer },
      textStyle: { color: theme.colors.onTertiaryContainer },
    },
    invalid_signature: {
      icon: "shield-remove",
      label: "Bad signature",
      tooltip: "The plugin declared a signature, but it could not be verified with the configured or synced trusted keys.",
      style: { backgroundColor: theme.colors.errorContainer },
      textStyle: { color: theme.colors.onErrorContainer },
    },
    unsigned: {
      icon: "shield-alert",
      label: "Unsigned",
      tooltip: "Sideloaded without a trusted signature. Review before using.",
      style: { backgroundColor: theme.colors.errorContainer },
      textStyle: { color: theme.colors.onErrorContainer },
    },
  };

  badges.push(trustBadges[plugin.trustLevel] || {
    icon: "shield-outline",
    label: plugin.trustLevel || "Unknown",
    tooltip: `Trust level reported by the platform: ${plugin.trustLevel || "unknown"}.`,
    style: { backgroundColor: theme.colors.surfaceVariant },
    textStyle: { color: theme.colors.onSurfaceVariant },
  });

  if (plugin.installSource?.type === "trusted_registry") {
    badges.push({
      icon: "information",
      label: plugin.installSource?.registry?.source?.name || "Trusted registry",
      tooltip: "This plugin came from a third-party registry that this WPrint 3D instance trusts.",
      style: { backgroundColor: theme.colors.secondaryContainer },
      textStyle: { color: theme.colors.onSecondaryContainer },
    });
  }

  if (plugin.updateAvailable) {
    badges.push({
      icon: "update",
      label: plugin.latestVersion ? `Update ${plugin.latestVersion}` : "Update available",
      tooltip: plugin.latestVersion
        ? `A newer release (${plugin.latestVersion}) is available from the configured registry source.`
        : "A newer release is available from the configured registry source.",
      style: { backgroundColor: theme.colors.secondaryContainer },
      textStyle: { color: theme.colors.onSecondaryContainer },
    });
  }

  return [ ...badges, ...buildDependencyBadges(plugin, theme) ];
};

const buildRequirementSummary = (plugin) => {
  const requirements = plugin.dependencies?.requirements || {};
  const parts = [];

  if (requirements.cpuCores) {
    parts.push(`${requirements.cpuCores} CPU core${requirements.cpuCores === 1 ? "" : "s"}`);
  }

  if (requirements.memoryMb) {
    parts.push(`${requirements.memoryMb} MB RAM`);
  }

  return parts.length ? `Minimum host target: ${parts.join(" • ")}.` : null;
};

const buildRegistrySourceBadge = (source, theme) => {
  if (source?.official) {
    return {
      icon: "shield-check",
      label: "Official registry",
      style: { backgroundColor: theme.colors.tertiaryContainer },
      textStyle: { color: theme.colors.onTertiaryContainer },
      tooltip: "Reviewed packages from the official WPrint 3D registry.",
    };
  }

  return {
    icon: "information",
    label: source?.name || "Trusted source",
    style: { backgroundColor: theme.colors.secondaryContainer },
    textStyle: { color: theme.colors.onSecondaryContainer },
    tooltip: "Third-party source explicitly trusted by this WPrint 3D instance.",
  };
};

const buildMutationFeedback = (response, variables = {}) => {
  const pluginId = response?.data?.id;
  const loadStatus = response?.data?.loadStatus;
  const lastError = response?.data?.lastError;
  const updateStatus = response?.data?.updateStatus;
  const latestVersion = response?.data?.latestVersion || response?.data?.version;

  if (loadStatus === "failed" && lastError) {
    return {
      message: `${pluginId} failed to load: ${lastError}`,
      variant: "warning",
    };
  }

  if (variables.intent === "update") {
    if (updateStatus === "noop") {
      return {
        message: pluginId
          ? `No updates found for ${pluginId}${latestVersion ? ` (${latestVersion})` : ""}.`
          : "No updates found.",
        variant: "info",
      };
    }

    if (updateStatus === "unsupported") {
      return {
        message: pluginId
          ? `No automatic update source is configured for ${pluginId}.`
          : "No automatic update source is configured for this plugin.",
        variant: "info",
      };
    }

    if (updateStatus === "refreshed") {
      return {
        message: pluginId
          ? `Refreshed ${pluginId} from the live source mount.`
          : "Plugin refreshed from the live source mount.",
        variant: "success",
      };
    }

    return {
      message: pluginId ? `Updated ${pluginId}.` : "Plugin updated.",
      variant: "success",
    };
  }

  if (variables.intent === "toggle") {
    return {
      message: pluginId
        ? `${response?.data?.enabled ? "Enabled" : "Disabled"} ${pluginId}.`
        : "Plugin state updated.",
      variant: "success",
    };
  }

  if (variables.intent === "install") {
    return {
      message: pluginId ? `Installed ${pluginId}.` : "Plugin installed.",
      variant: "success",
    };
  }

  if (variables.intent === "safe-mode") {
    return {
      message: `Safe mode enabled. Disabled ${response?.data?.disabledCount ?? 0} plugin${response?.data?.disabledCount === 1 ? "" : "s"}.`,
      variant: "info",
    };
  }

  if (variables.intent === "check-updates-all") {
    return {
      message: `Checked ${response?.data?.checkedCount ?? 0} plugin${response?.data?.checkedCount === 1 ? "" : "s"}: ${response?.data?.updatesAvailableCount ?? 0} update${response?.data?.updatesAvailableCount === 1 ? "" : "s"} available, ${response?.data?.upToDateCount ?? 0} already current, ${response?.data?.unsupportedCount ?? 0} unsupported.`,
      variant: "info",
    };
  }

  if (variables.intent === "update-all") {
    return {
      message: `Processed ${response?.data?.checkedCount ?? 0} plugin${response?.data?.checkedCount === 1 ? "" : "s"}: ${response?.data?.updatedCount ?? 0} updated, ${response?.data?.noopCount ?? 0} already current, ${response?.data?.unsupportedCount ?? 0} unsupported.`,
      variant: (response?.data?.updatedCount ?? 0) > 0 ? "success" : "info",
    };
  }

  if (variables.intent === "disable-all") {
    return {
      message: `Disabled ${response?.data?.disabledCount ?? 0} plugin${response?.data?.disabledCount === 1 ? "" : "s"}.`,
      variant: "info",
    };
  }

  if (variables.intent === "enable-all") {
    return {
      message: `Enabled ${response?.data?.enabledCount ?? 0} plugin${response?.data?.enabledCount === 1 ? "" : "s"}${(response?.data?.failedCount ?? 0) ? `, ${response?.data?.failedCount} failed to start` : ""}.`,
      variant: (response?.data?.failedCount ?? 0) > 0 ? "warning" : "success",
    };
  }

  if (variables.intent === "plugin-automatic-updates") {
    return {
      message: pluginId
        ? `${response?.data?.automaticUpdatesEnabled ? "Enabled" : "Disabled"} automatic updates for ${pluginId}.`
        : "Plugin automatic update preference saved.",
      variant: "info",
    };
  }

  if (variables.intent === "global-automatic-updates") {
    return {
      message: response?.data?.automaticUpdatesEnabled
        ? "Global automatic updates enabled."
        : `Global automatic updates disabled.${(response?.data?.disabledPluginAutomaticUpdatesCount ?? 0) ? ` Cleared ${response?.data?.disabledPluginAutomaticUpdatesCount} plugin override${response?.data?.disabledPluginAutomaticUpdatesCount === 1 ? "" : "s"}.` : ""}`,
      variant: "info",
    };
  }

  return {
    message: pluginId ? `Updated ${pluginId}.` : "Plugin operation completed.",
    variant: "success",
  };
};

const gridCardStyle = (theme, cardWidth, isWideLayout) => ({
  width: cardWidth,
  minWidth: isWideLayout ? 620 : undefined,
  borderRadius: 18,
  borderWidth: 1,
  borderColor: theme.colors.outlineVariant,
  backgroundColor: theme.colors.elevation.level2,
});

const pillButtonContentStyle = {
  minHeight: 44,
};

const pillButtonStyle = {
  borderRadius: 999,
  alignSelf: "flex-start",
};

const buildSettingsPageMap = (pluginSettingsPages = []) => (
  pluginSettingsPages.reduce((map, page) => {
    if (!map[page.pluginId]) {
      map[page.pluginId] = page;
    }

    return map;
  }, {})
);

let persistedOverlayState = {
  installModalVisible: false,
  installModalTab: "url",
  marketplaceVisible: false,
  registrySourcesVisible: false,
  confirmationDialog: null,
  logsDialogPlugin: null,
};

const NavBarMenuSettingsModalPlugins = ({ pluginSettingsPages = [], onOpenSettingsPage = () => {} }) => {
  const queryClient = useQueryClient();
  const { enqueueSnackbar } = useSnackbar();
  const theme = useTheme();
  const window = useWindowDimensions();

  const [ installUrl, setInstallUrl ] = useState("");
  const [ registryQuery, setRegistryQuery ] = useState("");
  const [ isDraggingFile, setIsDraggingFile ] = useState(false);
  const [ registrySourceName, setRegistrySourceName ] = useState("");
  const [ registrySourceIndexUrl, setRegistrySourceIndexUrl ] = useState("");
  const [ registrySourceWebsiteUrl, setRegistrySourceWebsiteUrl ] = useState("");
  const [ overlayState, setOverlayStateState ] = useState(() => ({ ...persistedOverlayState }));
  const [ pluginActionsMenuId, setPluginActionsMenuId ] = useState(null);
  const [ globalActionsMenuVisible, setGlobalActionsMenuVisible ] = useState(false);

  const updateOverlayState = (updates) => {
    const nextState = typeof updates === "function"
      ? updates(persistedOverlayState)
      : { ...persistedOverlayState, ...updates };

    persistedOverlayState = nextState;
    setOverlayStateState(nextState);
  };

  const installModalVisible = overlayState.installModalVisible;
  const installModalTab = overlayState.installModalTab;
  const marketplaceVisible = overlayState.marketplaceVisible;
  const registrySourcesVisible = overlayState.registrySourcesVisible;
  const confirmationDialog = overlayState.confirmationDialog;
  const logsDialogPlugin = overlayState.logsDialogPlugin;

  const setInstallModalVisible = (value) => updateOverlayState({
    installModalVisible: typeof value === "function" ? value(persistedOverlayState.installModalVisible) : value,
  });

  const setInstallModalTab = (value) => updateOverlayState({
    installModalTab: typeof value === "function" ? value(persistedOverlayState.installModalTab) : value,
  });

  const setMarketplaceVisible = (value) => updateOverlayState({
    marketplaceVisible: typeof value === "function" ? value(persistedOverlayState.marketplaceVisible) : value,
  });

  const setRegistrySourcesVisible = (value) => updateOverlayState({
    registrySourcesVisible: typeof value === "function" ? value(persistedOverlayState.registrySourcesVisible) : value,
  });

  const setConfirmationDialog = (value) => updateOverlayState({
    confirmationDialog: typeof value === "function" ? value(persistedOverlayState.confirmationDialog) : value,
  });

  const setLogsDialogPlugin = (value) => updateOverlayState({
    logsDialogPlugin: typeof value === "function" ? value(persistedOverlayState.logsDialogPlugin) : value,
  });

  const isWideLayout = window.width >= 1200;
  const isCompactActionLayout = window.width < 720;
  const isCompactHeaderLayout = window.width < 900;
  const cardWidth = isWideLayout ? 760 : (window.width >= 840 ? 620 : "100%");

  const userQuery = useQuery({
    queryKey: ["currentUser"],
    queryFn: () => API.get("/user"),
    retry: false,
    refetchOnWindowFocus: false,
    staleTime: 60000,
  });

  const developerModeQuery = useQuery({
    queryKey: ["developerMode"],
    queryFn: () => API.get("/config/developerMode"),
    retry: false,
    refetchOnWindowFocus: false,
    staleTime: 60000,
  });

  const isAdministrator = userQuery?.data?.data?.role === 0;
  const developerModeEnabled = developerModeQuery?.data?.data === true;

  const installedPluginsQuery = useQuery({
    queryKey: ["plugins"],
    queryFn: () => API.get("/plugins"),
    enabled: isAdministrator,
    retry: false,
    refetchOnWindowFocus: false,
    staleTime: 60000,
  });

  const registryPluginsQuery = useQuery({
    queryKey: ["pluginRegistry"],
    queryFn: () => API.get("/plugins/registry"),
    enabled: isAdministrator && marketplaceVisible,
    retry: false,
    refetchOnWindowFocus: false,
    staleTime: 60000,
  });

  const registrySourcesQuery = useQuery({
    queryKey: ["pluginRegistrySources"],
    queryFn: () => API.get("/plugins/registry/sources"),
    enabled: isAdministrator && (marketplaceVisible || registrySourcesVisible),
    retry: false,
    refetchOnWindowFocus: false,
    staleTime: 60000,
  });

  const developmentPluginsQuery = useQuery({
    queryKey: ["pluginDevelopment"],
    queryFn: () => API.get("/plugins/development"),
    enabled: isAdministrator && developerModeEnabled && installModalVisible && installModalTab === "development",
    retry: false,
    refetchOnWindowFocus: false,
    staleTime: 0,
  });

  const pluginLogsQuery = useQuery({
    queryKey: ["pluginLogs", logsDialogPlugin?.id],
    queryFn: () => API.get(`/plugins/${logsDialogPlugin.id}/logs`),
    enabled: isAdministrator && !!logsDialogPlugin,
    retry: false,
    refetchOnWindowFocus: false,
    staleTime: 0,
    refetchInterval: logsDialogPlugin ? 3000 : false,
  });

  useEffect(() => {
    if (isAdministrator && developerModeEnabled && installModalVisible && installModalTab === "development") {
      developmentPluginsQuery.refetch();
    }
  }, [ isAdministrator, developerModeEnabled, installModalVisible, installModalTab ]);

  useEffect(() => {
    if (logsDialogPlugin) {
      pluginLogsQuery.refetch();
    }
  }, [ logsDialogPlugin ]);

  const pluginPreferencesQuery = useQuery({
    queryKey: ["pluginPreferences"],
    queryFn: () => API.get("/plugins/preferences"),
    enabled: isAdministrator,
    retry: false,
    refetchOnWindowFocus: false,
    staleTime: 30000,
  });

  const settingsPageMap = useMemo(() => buildSettingsPageMap(pluginSettingsPages), [ pluginSettingsPages ]);

  const mutateAndRefresh = useMutation({
    mutationFn: ({ url, body = {}, method = "post" }) => API[method](url, body),
    onSuccess: (response, variables) => {
      queryClient.invalidateQueries({ queryKey: ["plugins"] });
      queryClient.invalidateQueries({ queryKey: ["pluginRegistry"] });
      queryClient.invalidateQueries({ queryKey: ["pluginRegistrySources"] });
      queryClient.invalidateQueries({ queryKey: ["pluginDevelopment"] });
      queryClient.invalidateQueries({ queryKey: ["pluginExtensions"] });
      queryClient.invalidateQueries({ queryKey: ["pluginLogs"] });
      queryClient.invalidateQueries({ queryKey: ["pluginPreferences"] });

      const feedback = buildMutationFeedback(response, variables);

      enqueueSnackbar({
        message: feedback.message,
        variant: feedback.variant,
        action: { label: "Got it" },
      });
    },
    onError: (error) => {
      enqueueSnackbar({
        message: error?.response?.data?.message || error.message,
        variant: "error",
        action: { label: "Got it" },
      });
    }
  });

  const deleteMutation = useMutation({
    mutationFn: (pluginId) => API.delete(`/plugins/${pluginId}`),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["plugins"] });
      queryClient.invalidateQueries({ queryKey: ["pluginExtensions"] });

      enqueueSnackbar({
        message: "Plugin removed.",
        variant: "success",
        action: { label: "Got it" },
      });
    },
    onError: (error) => {
      enqueueSnackbar({
        message: error?.response?.data?.message || error.message,
        variant: "error",
        action: { label: "Got it" },
      });
    }
  });

  const updateRegistrySourcesMutation = useMutation({
    mutationFn: (sources) => API.put("/plugins/registry/sources", { sources }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["pluginRegistrySources"] });
      queryClient.invalidateQueries({ queryKey: ["pluginRegistry"] });

      enqueueSnackbar({
        message: "Trusted registry sources updated.",
        variant: "success",
        action: { label: "Got it" },
      });

      setRegistrySourceName("");
      setRegistrySourceIndexUrl("");
      setRegistrySourceWebsiteUrl("");
    },
    onError: (error) => {
      enqueueSnackbar({
        message: error?.response?.data?.message || error.message,
        variant: "error",
        action: { label: "Got it" },
      });
    }
  });

  const handleUpload = (file) => {
    if (!file) { return; }

    mutateAndRefresh.mutate({
      url: "/plugins/install",
      body: { package: file },
      intent: "install",
    });
    setInstallModalVisible(false);
  };

  const registrySources = registrySourcesQuery?.data?.data || [];
  const trustedRegistrySources = registrySources.filter((source) => !source.official);
  const globalAutomaticUpdatesEnabled = pluginPreferencesQuery?.data?.data?.automaticUpdatesEnabled !== false;

  const filteredRegistryPlugins = useMemo(() => {
    const list = registryPluginsQuery?.data?.data || [];
    const loweredQuery = registryQuery.toLowerCase();

    if (!loweredQuery) { return list; }

    return list.filter((plugin) => {
      const haystack = [
        plugin?.id || "",
        plugin?.name || "",
        plugin?.description || "",
        plugin?.registrySource?.name || "",
        ...(plugin?.categories || []),
      ].join(" ").toLowerCase();

      return haystack.includes(loweredQuery);
    });
  }, [ registryPluginsQuery?.data?.data, registryQuery ]);

  const openConfirmationDialog = (payload) => setConfirmationDialog(payload);
  const closeConfirmationDialog = () => setConfirmationDialog(null);

  const executeConfirmedAction = () => {
    if (!confirmationDialog) { return; }

    if (confirmationDialog.kind === "toggle") {
      mutateAndRefresh.mutate({
        url: `/plugins/${confirmationDialog.plugin.id}/${confirmationDialog.plugin.enabled ? "disable" : "enable"}`,
        intent: "toggle",
      });
    }

    if (confirmationDialog.kind === "remove") {
      deleteMutation.mutate(confirmationDialog.plugin.id);
    }

    if (confirmationDialog.kind === "safe-mode") {
      mutateAndRefresh.mutate({ url: "/plugins/safe-mode", body: {}, intent: "safe-mode" });
    }

    if (confirmationDialog.kind === "disable-all") {
      mutateAndRefresh.mutate({ url: "/plugins/disable-all", body: {}, intent: "disable-all" });
    }

    if (confirmationDialog.kind === "enable-all") {
      mutateAndRefresh.mutate({ url: "/plugins/enable-all", body: {}, intent: "enable-all" });
    }

    if (confirmationDialog.kind === "remove-registry-source") {
      updateRegistrySourcesMutation.mutate(
        trustedRegistrySources.filter((source) => source.id !== confirmationDialog.source.id).map((source) => ({
          name: source.name,
          indexUrl: source.indexUrl,
          websiteUrl: source.websiteUrl,
        }))
      );
    }

    closeConfirmationDialog();
  };

  const installFromUrl = () => {
    if (!installUrl) { return; }

    mutateAndRefresh.mutate({
      url: "/plugins/install",
      body: { url: installUrl },
      intent: "install",
    });

    setInstallModalVisible(false);
    setInstallUrl("");
  };

  const installFromRegistry = (plugin) => {
    mutateAndRefresh.mutate({
      url: "/plugins/install",
      body: {
        pluginId: plugin.id,
        version: plugin.version || plugin.latestVersion,
        sourceId: plugin.registrySource?.id,
      },
      intent: "install",
    });
  };

  const installFromDevelopmentPath = (path) => {
    mutateAndRefresh.mutate({
      url: "/plugins/install",
      body: { unpackedPath: path },
      intent: "install",
    });
    setInstallModalVisible(false);
  };

  const openInstallModal = () => {
    setInstallModalTab("url");
    setInstallModalVisible(true);
  };

  const openMarketplace = () => {
    setMarketplaceVisible(true);
    queryClient.invalidateQueries({ queryKey: ["pluginRegistry"] });
    queryClient.invalidateQueries({ queryKey: ["pluginRegistrySources"] });
  };

  const addTrustedRegistrySource = () => {
    if (!registrySourceName || !registrySourceIndexUrl) { return; }

    const sources = [
      ...trustedRegistrySources.map((source) => ({
        name: source.name,
        indexUrl: source.indexUrl,
        websiteUrl: source.websiteUrl,
      })),
      {
        name: registrySourceName.trim(),
        indexUrl: registrySourceIndexUrl.trim(),
        websiteUrl: registrySourceWebsiteUrl.trim(),
      },
    ];

    updateRegistrySourcesMutation.mutate(sources);
  };

  const toggleGlobalAutomaticUpdates = () => {
    mutateAndRefresh.mutate({
      url: "/plugins/preferences",
      body: { automaticUpdatesEnabled: !globalAutomaticUpdatesEnabled },
      method: "put",
      intent: "global-automatic-updates",
    });
  };

  return (
    <View style={{ flex: 1, position: "relative" }}>
      <ScrollView contentContainerStyle={{ paddingBottom: 32 }}>
        <View style={{ marginBottom: 20, gap: 12 }}>
          <View
            style={{
              flexDirection: isCompactHeaderLayout ? "column" : "row",
              alignItems: isCompactHeaderLayout ? "flex-start" : "center",
              justifyContent: "space-between",
              gap: 12,
            }}
          >
            <View style={{ flexShrink: 1, gap: 8 }}>
              <Text variant="headlineSmall">Plugins</Text>
              <Text>
                Manage installed plugins and use the dedicated settings tabs for plugins that declare them.
              </Text>
            </View>

            {isAdministrator && (
              <View style={{ flexDirection: "row", flexWrap: "wrap", alignItems: "center", gap: 10 }}>
                <Button
                  mode="contained"
                  icon="plus"
                  onPress={openInstallModal}
                  style={pillButtonStyle}
                  contentStyle={pillButtonContentStyle}
                >
                  Add a plugin
                </Button>
                <Button
                  mode="contained-tonal"
                  icon="store-search"
                  onPress={openMarketplace}
                  style={pillButtonStyle}
                  contentStyle={pillButtonContentStyle}
                >
                  Marketplace
                </Button>
                <Menu
                  visible={globalActionsMenuVisible}
                  onDismiss={() => setGlobalActionsMenuVisible(false)}
                  anchor={(
                    <View style={{ alignSelf: "flex-start" }}>
                      <IconButton
                        icon="dots-vertical"
                        mode="outlined"
                        accessibilityLabel="Plugin manager actions"
                        containerColor={theme.colors.elevation.level1}
                        size={20}
                        style={{ margin: 0 }}
                        onPress={() => setGlobalActionsMenuVisible(true)}
                      />
                    </View>
                  )}
                >
                  <Menu.Item
                    leadingIcon="refresh"
                    title="Check for updates"
                    onPress={() => {
                      setGlobalActionsMenuVisible(false);
                      mutateAndRefresh.mutate({ url: "/plugins/check-updates", body: {}, intent: "check-updates-all" });
                    }}
                  />
                  <Menu.Item
                    leadingIcon="update"
                    title="Update all"
                    onPress={() => {
                      setGlobalActionsMenuVisible(false);
                      mutateAndRefresh.mutate({ url: "/plugins/update-all", body: {}, intent: "update-all" });
                    }}
                  />
                  <Menu.Item
                    leadingIcon="pause-circle-outline"
                    title="Disable all"
                    onPress={() => {
                      setGlobalActionsMenuVisible(false);
                      openConfirmationDialog({
                        kind: "disable-all",
                        title: "Disable all plugins?",
                        body: "This disables every currently enabled plugin on this instance.",
                        confirmLabel: "Disable all",
                      });
                    }}
                  />
                  <Menu.Item
                    leadingIcon="power"
                    title="Enable all"
                    onPress={() => {
                      setGlobalActionsMenuVisible(false);
                      openConfirmationDialog({
                        kind: "enable-all",
                        title: "Enable all plugins?",
                        body: "WPrint 3D will try to start every installed plugin again.",
                        confirmLabel: "Enable all",
                      });
                    }}
                  />
                  <Divider />
                  <Pressable
                    accessibilityRole="menuitemcheckbox"
                    accessibilityState={{ checked: globalAutomaticUpdatesEnabled }}
                    onPress={() => {
                      setGlobalActionsMenuVisible(false);
                      toggleGlobalAutomaticUpdates();
                    }}
                    style={{
                      minWidth: 220,
                      flexDirection: "row",
                      alignItems: "center",
                      gap: 12,
                      paddingHorizontal: 16,
                      paddingVertical: 12,
                    }}
                  >
                    <Checkbox
                      status={globalAutomaticUpdatesEnabled ? "checked" : "unchecked"}
                      pointerEvents="none"
                    />
                    <Text>Automatic updates</Text>
                  </Pressable>
                  <Divider />
                  <Menu.Item
                    leadingIcon="shield-alert"
                    title="Enable safe mode"
                    onPress={() => {
                      setGlobalActionsMenuVisible(false);
                      openConfirmationDialog({
                        kind: "safe-mode",
                        title: "Enable safe mode?",
                        body: "This disables all enabled plugins so you can recover from a bad install or runtime issue.",
                        confirmLabel: "Disable all plugins",
                      });
                    }}
                  />
                </Menu>
              </View>
            )}
          </View>
        </View>

        {isAdministrator && (
          <View style={{ marginBottom: 16 }}>
            <View style={{ marginBottom: 16, gap: 4 }}>
              <Text variant="titleMedium">Installed plugins</Text>
              <Text style={{ color: theme.colors.onSurfaceVariant }}>
                Manage what is already active on this instance.
              </Text>
            </View>

            <View style={{ flexDirection: "row", flexWrap: "wrap", gap: 16 }}>
                {(installedPluginsQuery?.data?.data || []).map((plugin) => (
                  (() => {
                    const toggleActionLabel = plugin.enabled ? "Disable" : (plugin.loadStatus === "failed" ? "Retry" : "Enable");
                    const toggleActionIcon = plugin.enabled ? "pause-circle-outline" : (plugin.loadStatus === "failed" ? "restart" : "power");
                    const updateActionLabel = plugin.installSource?.type === "development_mount" ? "Refresh" : "Update";
                    const updateActionIcon = plugin.installSource?.type === "development_mount" ? "refresh" : "update";
                    const hasSettingsPage = !!settingsPageMap[plugin.id];

                    const openToggleDialog = () => openConfirmationDialog({
                      kind: "toggle",
                      plugin,
                      title: plugin.enabled ? `Disable ${plugin.name}?` : `${plugin.loadStatus === "failed" ? "Retry" : "Enable"} ${plugin.name}?`,
                      body: plugin.enabled
                        ? "Its UI surfaces and actions will stop running until you enable it again."
                        : (plugin.loadStatus === "failed"
                          ? "WPrint 3D will try to start the plugin again and record fresh startup logs."
                          : "Its registered UI surfaces and actions will become active again."),
                      confirmLabel: plugin.enabled ? "Disable plugin" : (plugin.loadStatus === "failed" ? "Retry plugin" : "Enable plugin"),
                    });

                    const openRemoveDialog = () => openConfirmationDialog({
                      kind: "remove",
                      plugin,
                      title: `Remove ${plugin.name}?`,
                      body: "This uninstalls the plugin from the current WPrint 3D instance.",
                      confirmLabel: "Remove plugin",
                    });

                    const secondaryActions = [
                      {
                        key: "logs",
                        icon: "text-box-search-outline",
                        label: "Logs",
                        onPress: () => setLogsDialogPlugin({
                          id: plugin.id,
                          name: plugin.name,
                        }),
                      },
                      {
                        key: "update",
                        icon: plugin.installSource?.type === "development_mount" ? "refresh" : "update",
                        label: updateActionLabel,
                        onPress: () => mutateAndRefresh.mutate({ url: `/plugins/${plugin.id}/update`, intent: "update" }),
                      },
                      {
                        key: "remove",
                        icon: "trash-can-outline",
                        label: "Remove",
                        titleStyle: { color: theme.colors.error },
                        onPress: openRemoveDialog,
                      },
                    ];

                    return (
                      <Card key={plugin.id} style={gridCardStyle(theme, cardWidth, isWideLayout)}>
                        <Card.Title title={plugin.name} subtitle={`${plugin.id} • ${plugin.version}`} />
                        <Card.Content>
                          <View style={{ flexDirection: "row", flexWrap: "wrap", gap: 8, marginBottom: 12 }}>
                            {buildPluginBadges(plugin, theme).map((badge) => (
                              <Tooltip key={`${plugin.id}-${badge.label}`} title={badge.tooltip}>
                                <Chip icon={badge.icon} style={badge.style} textStyle={badge.textStyle}>
                                  {badge.label}
                                </Chip>
                              </Tooltip>
                            ))}
                          </View>

                          {!!plugin.dependencies?.hint && (
                            <Text style={{ color: theme.colors.onSurfaceVariant, marginBottom: 10 }}>
                              {plugin.dependencies.hint}
                            </Text>
                          )}

                          {!!buildRequirementSummary(plugin) && (
                            <Text style={{ color: theme.colors.onSurfaceVariant, marginBottom: 12 }}>
                              {buildRequirementSummary(plugin)}
                            </Text>
                          )}

                          {plugin.loadStatus === "failed" && !!plugin.lastError && (
                            <View
                              style={{
                                marginBottom: 12,
                                padding: 12,
                                borderRadius: 14,
                                backgroundColor: theme.colors.errorContainer,
                              }}
                            >
                              <Text style={{ color: theme.colors.onErrorContainer, fontWeight: "700", marginBottom: 4 }}>
                                Plugin failed to load
                              </Text>
                              <Text style={{ color: theme.colors.onErrorContainer }}>
                                {plugin.lastError}
                              </Text>
                            </View>
                          )}

                          {!!plugin.warnings?.length && (
                            <View
                              style={{
                                marginBottom: 12,
                                padding: 12,
                                borderRadius: 14,
                                backgroundColor: theme.colors.tertiaryContainer,
                              }}
                            >
                              <Text style={{ color: theme.colors.onTertiaryContainer }}>
                                {plugin.warnings.join(" ")}
                              </Text>
                            </View>
                          )}

                          <View
                            style={{
                              flexDirection: "row",
                              alignItems: "center",
                              justifyContent: "space-between",
                              gap: 12,
                              marginBottom: 12,
                              paddingVertical: 10,
                              paddingHorizontal: 12,
                              borderRadius: 14,
                              backgroundColor: theme.colors.elevation.level1,
                            }}
                          >
                            <View style={{ flex: 1, gap: 2 }}>
                              <Text style={{ fontWeight: "700" }}>Automatic updates</Text>
                              <Text style={{ color: theme.colors.onSurfaceVariant }}>
                                {plugin.automaticUpdatesSupported
                                  ? (globalAutomaticUpdatesEnabled
                                    ? "Install new registry releases for this plugin automatically."
                                    : "Global automatic updates are disabled for all plugins.")
                                  : "Only plugins installed from a trusted registry can update automatically."}
                              </Text>
                            </View>
                            <Switch
                              value={plugin.automaticUpdatesSupported && !!plugin.automaticUpdatesEnabled}
                              accessibilityLabel={`${plugin.name} automatic updates`}
                              disabled={!plugin.automaticUpdatesSupported || !globalAutomaticUpdatesEnabled}
                              onValueChange={(enabled) => mutateAndRefresh.mutate({
                                url: `/plugins/${plugin.id}/automatic-updates`,
                                body: { enabled },
                                method: "put",
                                intent: "plugin-automatic-updates",
                              })}
                            />
                          </View>

                          {isCompactActionLayout ? (
                            <View style={{ flexDirection: "row", alignItems: "center", gap: 8 }}>
                              {hasSettingsPage && (
                                <Button
                                  mode="contained-tonal"
                                  icon="cog-outline"
                                  style={{ flex: 1 }}
                                  contentStyle={{ minHeight: 44 }}
                                  onPress={() => onOpenSettingsPage(settingsPageMap[plugin.id].key)}
                                >
                                  Settings
                                </Button>
                              )}
                              <Button
                                mode={plugin.enabled ? "outlined" : "contained-tonal"}
                                icon={toggleActionIcon}
                                style={{ flex: hasSettingsPage ? 1 : undefined }}
                                contentStyle={{ minHeight: 44 }}
                                onPress={openToggleDialog}
                              >
                                {toggleActionLabel}
                              </Button>
                              <Menu
                                visible={pluginActionsMenuId === plugin.id}
                                onDismiss={() => setPluginActionsMenuId(null)}
                                anchor={(
                                  <IconButton
                                    icon="dots-vertical"
                                    accessibilityLabel={`${plugin.name} more actions`}
                                    mode="outlined"
                                    size={20}
                                    containerColor={theme.colors.elevation.level1}
                                    onPress={() => setPluginActionsMenuId(plugin.id)}
                                  />
                                )}
                              >
                                {secondaryActions.map((action) => (
                                  <Menu.Item
                                    key={`${plugin.id}-${action.key}`}
                                    leadingIcon={action.icon}
                                    title={action.label}
                                    titleStyle={action.titleStyle}
                                    onPress={() => {
                                      setPluginActionsMenuId(null);
                                      action.onPress();
                                    }}
                                  />
                                ))}
                              </Menu>
                            </View>
                          ) : (
                            <View style={{ flexDirection: "row", flexWrap: "wrap", gap: 8 }}>
                              {hasSettingsPage && (
                                <Button
                                  mode="contained-tonal"
                                  icon="cog-outline"
                                  style={pillButtonStyle}
                                  contentStyle={pillButtonContentStyle}
                                  onPress={() => onOpenSettingsPage(settingsPageMap[plugin.id].key)}
                                >
                                  Settings
                                </Button>
                              )}
                              <Button
                                mode="outlined"
                                icon="text-box-search-outline"
                                style={pillButtonStyle}
                                contentStyle={pillButtonContentStyle}
                                onPress={() => setLogsDialogPlugin({
                                  id: plugin.id,
                                  name: plugin.name,
                                })}
                              >
                                Logs
                              </Button>
                              <Button
                                mode={plugin.enabled ? "outlined" : "contained-tonal"}
                                icon={toggleActionIcon}
                                style={pillButtonStyle}
                                contentStyle={pillButtonContentStyle}
                                onPress={openToggleDialog}
                              >
                                {toggleActionLabel}
                              </Button>
                              <Button
                                mode="outlined"
                                icon={updateActionIcon}
                                style={pillButtonStyle}
                                contentStyle={pillButtonContentStyle}
                                onPress={() => mutateAndRefresh.mutate({ url: `/plugins/${plugin.id}/update`, intent: "update" })}
                              >
                                {updateActionLabel}
                              </Button>
                              <Button
                                mode="contained"
                                icon="trash-can-outline"
                                buttonColor={theme.colors.error}
                                textColor={theme.colors.white || "#ffffff"}
                                iconColor={theme.colors.white || "#ffffff"}
                                style={pillButtonStyle}
                                contentStyle={pillButtonContentStyle}
                                onPress={openRemoveDialog}
                              >
                                Remove
                              </Button>
                            </View>
                          )}
                        </Card.Content>
                      </Card>
                    );
                  })()
                ))}

              {!installedPluginsQuery?.data?.data?.length && (
                <Text style={{ marginTop: 12 }}>
                  No plugins are installed yet. Use `Add a plugin` or `Marketplace` to bring one in.
                </Text>
              )}
            </View>
          </View>
        )}
      </ScrollView>

      <SimpleDialog
        visible={installModalVisible}
        setVisible={setInstallModalVisible}
        title="Install a plugin"
        style={{ maxWidth: 920 }}
        content={
          <View style={{ minHeight: 340 }}>
            <View style={{ flexDirection: "row", flexWrap: "wrap", gap: 8, marginBottom: 16 }}>
              <Button
                mode={installModalTab === "url" ? "contained-tonal" : "text"}
                icon="link"
                onPress={() => setInstallModalTab("url")}
              >
                Install from URL
              </Button>
              <Button
                mode={installModalTab === "upload" ? "contained-tonal" : "text"}
                icon="upload"
                onPress={() => setInstallModalTab("upload")}
              >
                Upload from file
              </Button>
              {developerModeEnabled && (
                <Button
                  mode={installModalTab === "development" ? "contained-tonal" : "text"}
                  icon="flask"
                  onPress={() => setInstallModalTab("development")}
                >
                  Install unpacked
                </Button>
              )}
            </View>

            {installModalTab === "url" && (
              <View style={{ gap: 12, paddingTop: 8 }}>
                <Text>
                  Install a remote `.w3dp` package directly from a URL. Use this for internal distribution or third-party sources you trust.
                </Text>
                <TextInput
                  mode="outlined"
                  label="Package URL"
                  value={installUrl}
                  onChangeText={setInstallUrl}
                  placeholder="https://example.com/plugin.w3dp"
                />
                <View style={{ flexDirection: "row", justifyContent: "flex-end" }}>
                  <Button mode="contained" onPress={installFromUrl} disabled={!installUrl}>
                    Install from URL
                  </Button>
                </View>
              </View>
            )}

            {installModalTab === "upload" && (
              <View style={{ paddingTop: 8, alignItems: "center", justifyContent: "center", minHeight: 280 }}>
                <View
                  {...(
                    Platform.OS === "web"
                      ? {
                          onDragOver: (event) => {
                            event.preventDefault();
                            setIsDraggingFile(true);
                          },
                          onDragLeave: () => setIsDraggingFile(false),
                          onDrop: (event) => {
                            event.preventDefault();
                            setIsDraggingFile(false);
                            const file = event?.dataTransfer?.files?.[0];
                            handleUpload(file);
                          }
                        }
                      : {}
                  )}
                  style={{
                    width: "100%",
                    maxWidth: 520,
                    minHeight: 224,
                    alignItems: "center",
                    justifyContent: "center",
                    gap: 14,
                    borderWidth: 1.5,
                    borderStyle: "dashed",
                    borderColor: isDraggingFile ? theme.colors.primary : theme.colors.outlineVariant,
                    borderRadius: 22,
                    paddingVertical: 28,
                    paddingHorizontal: 24,
                    backgroundColor: isDraggingFile ? theme.colors.primaryContainer : theme.colors.elevation.level1,
                  }}
                >
                  <View
                    style={{
                      width: 62,
                      height: 62,
                      borderRadius: 31,
                      alignItems: "center",
                      justifyContent: "center",
                      backgroundColor: isDraggingFile ? theme.colors.primary : theme.colors.surfaceVariant,
                    }}
                  >
                    <Icon
                      source="tray-arrow-up"
                      size={30}
                      color={isDraggingFile ? theme.colors.onPrimary : theme.colors.onSurfaceVariant}
                    />
                  </View>

                  <Button
                    mode="contained-tonal"
                    icon="file-upload"
                    onPress={() => {
                      DocumentPicker.getDocumentAsync({ multiple: false }).then(({ assets, canceled }) => {
                        if (canceled || !assets?.length) { return; }
                        handleUpload(assets[0].file);
                      });
                    }}
                  >
                    Choose a .w3dp package
                  </Button>

                  <Text
                    style={{
                      textAlign: "center",
                      color: theme.colors.onSurfaceVariant,
                      maxWidth: 320,
                    }}
                  >
                    or drag and drop a <Text style={{ fontWeight: "700", color: theme.colors.onSurface }}>.w3dp</Text> file here
                  </Text>
                </View>
              </View>
            )}

            {installModalTab === "development" && developerModeEnabled && (
              <View style={{ gap: 14, paddingTop: 8 }}>
                <Text style={{ textAlign: "center", color: theme.colors.onSurfaceVariant }}>
                  Developer mode is enabled. Install plugins directly from the live development mount when this instance is running through `./run.sh -e dev`.
                </Text>

                {developmentPluginsQuery.isPending && (
                  <Text style={{ textAlign: "center", color: theme.colors.onSurfaceVariant }}>
                    Checking the live development mount…
                  </Text>
                )}

                {developmentPluginsQuery.isError && (
                  <Card style={{ borderRadius: 18, backgroundColor: theme.colors.elevation.level1 }}>
                    <Card.Content style={{ gap: 10 }}>
                      <Text variant="titleMedium">Development mount unavailable</Text>
                      <Text style={{ color: theme.colors.onSurfaceVariant }}>
                        The unpacked plugin mount is only available when WPrint 3D is running from the source checkout with `./run.sh -e dev`.
                      </Text>
                      <Button mode="outlined" onPress={() => developmentPluginsQuery.refetch()}>
                        Retry
                      </Button>
                    </Card.Content>
                  </Card>
                )}

                {!developmentPluginsQuery.isPending && !developmentPluginsQuery.isError && (
                  <>
                    {developmentPluginsQuery?.data?.data?.available ? (
                      <>
                        <View style={{ flexDirection: "row", justifyContent: "center" }}>
                          <Button mode="text" icon="refresh" onPress={() => developmentPluginsQuery.refetch()}>
                            Refresh
                          </Button>
                        </View>
                        <View style={{ gap: 8 }}>
                          <Text style={{ textAlign: "center" }}>
                            Source mounts
                          </Text>
                          <View style={{ flexDirection: "row", flexWrap: "wrap", gap: 8, justifyContent: "center" }}>
                            {(developmentPluginsQuery?.data?.data?.mountPaths || []).map((mountPath) => (
                              <Chip key={mountPath} icon="folder-multiple" compact>
                                {mountPath}
                              </Chip>
                            ))}
                          </View>
                        </View>
                        <ScrollView
                          style={{
                            maxHeight: Math.min(window.height * 0.48, 520),
                          }}
                          contentContainerStyle={{
                            paddingBottom: 8,
                          }}
                        >
                          <View style={{ flexDirection: "row", flexWrap: "wrap", gap: 12, justifyContent: "center" }}>
                            {(developmentPluginsQuery?.data?.data?.plugins || []).map((plugin) => (
                              <Card
                                key={`development-${plugin.path}`}
                                style={{
                                  width: "100%",
                                  maxWidth: 336,
                                  borderRadius: 18,
                                  borderWidth: 1,
                                  borderColor: theme.colors.outlineVariant,
                                  backgroundColor: theme.colors.elevation.level1,
                                }}
                              >
                                <Card.Content style={{ gap: 10 }}>
                                  <View style={{ gap: 6 }}>
                                    <View style={{ flexDirection: "row", flexWrap: "wrap", gap: 8, alignItems: "center" }}>
                                      <Text variant="titleMedium" style={{ flexShrink: 1 }}>{plugin.name}</Text>
                                      {!!plugin.mountLabel && (
                                        <Chip compact icon={plugin.mountLabel === "Local plugins" ? "folder-home-outline" : "flask-outline"}>
                                          {plugin.mountLabel}
                                        </Chip>
                                      )}
                                    </View>
                                    <Text>{plugin.id} • {plugin.version}</Text>
                                  </View>
                                  {!!plugin.description && <Text>{plugin.description}</Text>}
                                  <Text style={{ color: theme.colors.onSurfaceVariant }}>
                                    Mounted path: {plugin.relativePath || plugin.path}
                                  </Text>
                                  {!!plugin.warning && (
                                    <Text style={{ color: theme.colors.error }}>
                                      {plugin.warning}
                                    </Text>
                                  )}
                                  {!!plugin.warnings?.length && (
                                    <Text style={{ color: theme.colors.onSurfaceVariant }}>
                                      {plugin.warnings.join(" ")}
                                    </Text>
                                  )}
                                  <Button
                                    mode="contained"
                                    icon="rocket-launch-outline"
                                    onPress={() => installFromDevelopmentPath(plugin.path)}
                                    disabled={!!plugin.warning}
                                  >
                                    Install unpacked plugin
                                  </Button>
                                </Card.Content>
                              </Card>
                            ))}
                            {!developmentPluginsQuery?.data?.data?.plugins?.length && (
                              <Text style={{ textAlign: "center", color: theme.colors.onSurfaceVariant, width: "100%" }}>
                                No unpacked plugins were found in the live development mounts yet.
                              </Text>
                            )}
                          </View>
                        </ScrollView>
                      </>
                    ) : (
                      <Card style={{ borderRadius: 18, backgroundColor: theme.colors.elevation.level1 }}>
                        <Card.Content style={{ gap: 10 }}>
                          <Text variant="titleMedium">Live development mount unavailable</Text>
                          <Text style={{ color: theme.colors.onSurfaceVariant }}>
                            This instance is not running from `./run.sh -e dev`, so unpacked plugins cannot be installed live.
                          </Text>
                          {!!developmentPluginsQuery?.data?.data?.configuredMountPath && (
                            <Text style={{ color: theme.colors.onSurfaceVariant }}>
                              Configured mount path: {developmentPluginsQuery?.data?.data?.configuredMountPath}
                            </Text>
                          )}
                          <Button mode="outlined" icon="refresh" onPress={() => developmentPluginsQuery.refetch()}>
                            Refresh
                          </Button>
                        </Card.Content>
                      </Card>
                    )}
                  </>
                )}
              </View>
            )}
          </View>
        }
        actions={
          <Button mode="text" onPress={() => setInstallModalVisible(false)}>
            Close
          </Button>
        }
      />

      <SimpleDialog
        visible={marketplaceVisible}
        setVisible={(visible) => {
          setMarketplaceVisible(visible);
          if (!visible) {
            setRegistrySourcesVisible(false);
          }
        }}
        title="Marketplace"
        style={{ maxWidth: 1180 }}
        content={
          <View style={{ minHeight: 420, maxHeight: window.height * 0.72, gap: 16 }}>
            <View style={{ flexDirection: "row", justifyContent: "space-between", alignItems: "flex-start", gap: 16 }}>
              <View style={{ flex: 1, gap: 6 }}>
                <Text variant="titleMedium">Official registry and trusted sources</Text>
                <Text style={{ color: theme.colors.onSurfaceVariant }}>
                  Browse reviewed packages from WPrint 3D and any third-party registries you explicitly trust.
                </Text>
              </View>
              <Tooltip title="Manage trusted third-party registries">
                <IconButton
                  accessibilityLabel="Registry sources"
                  icon="cog-outline"
                  mode="contained-tonal"
                  onPress={() => setRegistrySourcesVisible(true)}
                />
              </Tooltip>
            </View>

            <Searchbar
              value={registryQuery}
              onChangeText={setRegistryQuery}
              placeholder="Search registry plugins"
            />

            <ScrollView contentContainerStyle={{ paddingBottom: 8 }}>
              <View style={{ flexDirection: "row", flexWrap: "wrap", gap: 16 }}>
                {filteredRegistryPlugins.map((plugin) => {
                  const sourceBadge = buildRegistrySourceBadge(plugin.registrySource, theme);

                  return (
                    <Card key={`${plugin.registrySource?.id || "registry"}-${plugin.id}`} style={gridCardStyle(theme, cardWidth, isWideLayout)}>
                      <Card.Content style={{ gap: 10 }}>
                        <View style={{ gap: 4 }}>
                          <Text variant="titleMedium">{plugin.name}</Text>
                          <Text>{plugin.id} • {plugin.version || plugin.latestVersion}</Text>
                        </View>

                        <View style={{ flexDirection: "row", flexWrap: "wrap", gap: 8 }}>
                          <Tooltip title={sourceBadge.tooltip}>
                            <Chip icon={sourceBadge.icon} style={sourceBadge.style} textStyle={sourceBadge.textStyle}>
                              {sourceBadge.label}
                            </Chip>
                          </Tooltip>
                          {buildDependencyBadges(plugin, theme).map((badge) => (
                            <Tooltip key={`${plugin.registrySource?.id || "registry"}-${plugin.id}-${badge.label}`} title={badge.tooltip}>
                              <Chip icon={badge.icon} style={badge.style} textStyle={badge.textStyle}>
                                {badge.label}
                              </Chip>
                            </Tooltip>
                          ))}
                          {(plugin.categories || []).slice(0, 3).map((category) => (
                            <Chip
                              key={`${plugin.registrySource?.id || "registry"}-${plugin.id}-${category}`}
                              icon="tag"
                              style={{ backgroundColor: theme.colors.secondaryContainer }}
                            >
                              {category}
                            </Chip>
                          ))}
                        </View>

                        {!!plugin.description && <Text>{plugin.description}</Text>}

                        {!!plugin.dependencies?.hint && (
                          <Text style={{ color: theme.colors.onSurfaceVariant }}>
                            {plugin.dependencies.hint}
                          </Text>
                        )}

                        {!!buildRequirementSummary(plugin) && (
                          <Text style={{ color: theme.colors.onSurfaceVariant }}>
                            {buildRequirementSummary(plugin)}
                          </Text>
                        )}

                        {!!plugin.warnings?.length && (
                          <View
                            style={{
                              gap: 6,
                              padding: 12,
                              borderRadius: 14,
                              backgroundColor: theme.colors.tertiaryContainer,
                            }}
                          >
                            <Text style={{ color: theme.colors.onTertiaryContainer }}>
                              {plugin.warnings.join(" ")}
                            </Text>
                          </View>
                        )}

                        {!plugin.registrySource?.official && (
                          <View
                            style={{
                              flexDirection: "row",
                              gap: 10,
                              padding: 12,
                              borderRadius: 14,
                              backgroundColor: theme.colors.secondaryContainer,
                            }}
                          >
                            <Icon source="information" size={18} color={theme.colors.onSecondaryContainer} />
                            <View style={{ flex: 1, gap: 4 }}>
                              <Text style={{ color: theme.colors.onSecondaryContainer, fontWeight: "700" }}>
                                Trusted third-party registry
                              </Text>
                              <Text style={{ color: theme.colors.onSecondaryContainer }}>
                                This plugin was signed by a third-party registry that you trust.
                              </Text>
                            </View>
                          </View>
                        )}

                        <Button mode="contained" onPress={() => installFromRegistry(plugin)}>
                          Install
                        </Button>
                      </Card.Content>
                    </Card>
                  );
                })}

                {!filteredRegistryPlugins.length && !registryPluginsQuery.isLoading && (
                  <Card style={{ borderRadius: 18, width: "100%" }}>
                    <Card.Content>
                      <Text>No registry plugins matched this search.</Text>
                    </Card.Content>
                  </Card>
                )}
              </View>
            </ScrollView>
          </View>
        }
        actions={
          <Button mode="text" onPress={() => setMarketplaceVisible(false)}>
            Close
          </Button>
        }
      />

      <SimpleDialog
        visible={registrySourcesVisible}
        setVisible={setRegistrySourcesVisible}
        title="Trusted registry sources"
        style={{ maxWidth: 980 }}
        content={
          <View style={{ gap: 16, minHeight: 320 }}>
            <Text style={{ color: theme.colors.onSurfaceVariant }}>
              Official registry packages are always available. Add extra registry indexes here if you want the marketplace to surface third-party plugins from sources you trust.
            </Text>

            <Card style={{ borderRadius: 18, backgroundColor: theme.colors.elevation.level1 }}>
              <Card.Title title="Official registry" subtitle="Built-in and always enabled" />
              <Card.Content>
                <Text selectable>{registrySources.find((source) => source.official)?.indexUrl}</Text>
              </Card.Content>
            </Card>

            <ScrollView style={{ maxHeight: 220 }}>
              <View style={{ gap: 12 }}>
                {trustedRegistrySources.map((source) => (
                  <Card
                    key={source.id}
                    style={{
                      borderRadius: 18,
                      borderWidth: 1,
                      borderColor: theme.colors.outlineVariant,
                      backgroundColor: theme.colors.elevation.level1,
                    }}
                  >
                    <Card.Content style={{ gap: 10 }}>
                      <View style={{ gap: 4 }}>
                        <Text variant="titleMedium">{source.name}</Text>
                        <Text selectable>{source.indexUrl}</Text>
                        {!!source.websiteUrl && (
                          <Text selectable style={{ color: theme.colors.onSurfaceVariant }}>
                            Website: {source.websiteUrl}
                          </Text>
                        )}
                      </View>
                      <View style={{ flexDirection: "row", justifyContent: "space-between", alignItems: "center", gap: 12 }}>
                        <Text style={{ color: theme.colors.onSurfaceVariant, flex: 1 }}>
                          Plugins from this source will be marked as trusted third-party listings in the marketplace.
                        </Text>
                        <Button
                          mode="outlined"
                          onPress={() => openConfirmationDialog({
                            kind: "remove-registry-source",
                            source,
                            title: `Remove ${source.name}?`,
                            body: "This removes the registry from the marketplace for this WPrint 3D instance.",
                            confirmLabel: "Remove source",
                          })}
                        >
                          Remove
                        </Button>
                      </View>
                    </Card.Content>
                  </Card>
                ))}

                {!trustedRegistrySources.length && (
                  <Card style={{ borderRadius: 18, backgroundColor: theme.colors.elevation.level1 }}>
                    <Card.Content>
                      <Text>No trusted third-party registries have been added yet.</Text>
                    </Card.Content>
                  </Card>
                )}
              </View>
            </ScrollView>

            <Card style={{ borderRadius: 18, backgroundColor: theme.colors.elevation.level2 }}>
              <Card.Title title="Add a trusted registry" subtitle="Marketplace source settings" />
              <Card.Content style={{ gap: 12 }}>
                <TextInput
                  mode="outlined"
                  label="Registry name"
                  value={registrySourceName}
                  onChangeText={setRegistrySourceName}
                  placeholder="Partner registry"
                />
                <TextInput
                  mode="outlined"
                  label="Index URL"
                  value={registrySourceIndexUrl}
                  onChangeText={setRegistrySourceIndexUrl}
                  placeholder="https://plugins.example.com/index.json"
                  autoCapitalize="none"
                />
                <TextInput
                  mode="outlined"
                  label="Website URL (optional)"
                  value={registrySourceWebsiteUrl}
                  onChangeText={setRegistrySourceWebsiteUrl}
                  placeholder="https://plugins.example.com"
                  autoCapitalize="none"
                />
                <View style={{ flexDirection: "row", justifyContent: "flex-end" }}>
                  <Button
                    mode="contained"
                    icon="plus"
                    onPress={addTrustedRegistrySource}
                    disabled={!registrySourceName.trim() || !registrySourceIndexUrl.trim() || updateRegistrySourcesMutation.isPending}
                  >
                    Save source
                  </Button>
                </View>
              </Card.Content>
            </Card>
          </View>
        }
        actions={
          <Button mode="text" onPress={() => setRegistrySourcesVisible(false)}>
            Close
          </Button>
        }
      />

      <SimpleDialog
        visible={!!logsDialogPlugin}
        setVisible={() => setLogsDialogPlugin(null)}
        title={logsDialogPlugin ? `${logsDialogPlugin.name} logs` : "Plugin logs"}
        style={{ maxWidth: 980 }}
        content={
          <View style={{ gap: 16, minHeight: 320, maxHeight: window.height * 0.7 }}>
            <View style={{ flexDirection: "row", justifyContent: "space-between", alignItems: "center", gap: 12 }}>
              <Text style={{ color: theme.colors.onSurfaceVariant, flex: 1 }}>
                Live startup and runtime diagnostics for this plugin. Entries refresh while this dialog is open.
              </Text>
              <Button mode="outlined" icon="refresh" onPress={() => pluginLogsQuery.refetch()}>
                Refresh
              </Button>
            </View>

            {pluginLogsQuery.isPending && (
              <Text style={{ color: theme.colors.onSurfaceVariant }}>
                Loading plugin logs…
              </Text>
            )}

            {pluginLogsQuery.isError && (
              <Card style={{ borderRadius: 18, backgroundColor: theme.colors.errorContainer }}>
                <Card.Content>
                  <Text style={{ color: theme.colors.onErrorContainer }}>
                    {pluginLogsQuery.error?.response?.data?.message || pluginLogsQuery.error?.message || "Unable to load plugin logs."}
                  </Text>
                </Card.Content>
              </Card>
            )}

            {!pluginLogsQuery.isPending && !pluginLogsQuery.isError && (
              <ScrollView style={{ maxHeight: window.height * 0.55 }}>
                <View style={{ gap: 10, paddingBottom: 8 }}>
                  {[ ...(pluginLogsQuery?.data?.data || []) ].reverse().map((entry, index) => {
                    const isError = entry.level === "error";
                    const surfaceColor = isError ? theme.colors.errorContainer : theme.colors.elevation.level1;
                    const textColor = isError ? theme.colors.onErrorContainer : theme.colors.onSurface;
                    const mutedColor = isError ? theme.colors.onErrorContainer : theme.colors.onSurfaceVariant;

                    return (
                      <Card
                        key={`${entry.timestamp || "entry"}-${index}`}
                        style={{
                          borderRadius: 18,
                          borderWidth: 1,
                          borderColor: isError ? theme.colors.error : theme.colors.outlineVariant,
                          backgroundColor: surfaceColor,
                        }}
                      >
                        <Card.Content style={{ gap: 8 }}>
                          <View style={{ flexDirection: "row", flexWrap: "wrap", gap: 8 }}>
                            <Chip compact icon={isError ? "alert-circle" : "information"}>{entry.level || "info"}</Chip>
                            <Chip compact icon="play-circle-outline">{entry.stage || "runtime"}</Chip>
                            {!!entry.timestamp && (
                              <Chip compact icon="clock-outline">
                                {new Date(entry.timestamp).toLocaleString()}
                              </Chip>
                            )}
                          </View>
                          <Text style={{ color: textColor }}>{entry.message}</Text>
                          {!!Object.keys(entry.context || {}).length && (
                            <Text selectable style={{ color: mutedColor }}>
                              {JSON.stringify(entry.context)}
                            </Text>
                          )}
                        </Card.Content>
                      </Card>
                    );
                  })}

                  {!pluginLogsQuery?.data?.data?.length && (
                    <Card style={{ borderRadius: 18, backgroundColor: theme.colors.elevation.level1 }}>
                      <Card.Content>
                        <Text>No plugin lifecycle logs have been recorded yet.</Text>
                      </Card.Content>
                    </Card>
                  )}
                </View>
              </ScrollView>
            )}
          </View>
        }
        actions={
          <Button mode="text" onPress={() => setLogsDialogPlugin(null)}>
            Close
          </Button>
        }
      />

      <SimpleDialog
        visible={!!confirmationDialog}
        setVisible={closeConfirmationDialog}
        title={confirmationDialog?.title || "Confirm action"}
        content={<Text>{confirmationDialog?.body}</Text>}
        actions={
          <>
            <Button mode="text" onPress={closeConfirmationDialog}>
              Cancel
            </Button>
            <Button mode="contained" onPress={executeConfirmedAction}>
              {confirmationDialog?.confirmLabel || "Continue"}
            </Button>
          </>
        }
      />
    </View>
  );
};

export default memo(NavBarMenuSettingsModalPlugins);
