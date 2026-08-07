import { memo, useEffect, useMemo, useState } from "react";
import { Platform, Pressable, ScrollView, View, useWindowDimensions } from "react-native";
import * as DocumentPicker from "expo-document-picker";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Button, Card, Checkbox, Chip, Divider, Icon, IconButton, Menu, Searchbar, Switch, Text, TextInput, Tooltip, useTheme } from "react-native-paper";
import { useSnackbar } from "react-native-paper-snackbar-stack";
import API from "../includes/API";
import { useLocalization } from "../includes/LocalizationProvider";
import { buildRegistryPluginInstallKey } from "../utils/pluginInstallUi";
import SimpleDialog from "./SimpleDialog";

const buildBadgeIcon = (source, color) => color
  ? ({ size }) => <Icon source={source} size={size} color={color} />
  : source;

const PluginBadgeChip = ({ badge }) => (
  <Chip
    icon={buildBadgeIcon(badge.icon, badge.textStyle?.color)}
    style={badge.style}
    textStyle={badge.textStyle}
  >
    {badge.label}
  </Chip>
);

const buildDependencyBadges = (plugin, theme, t) => {
  const badges = [];
  const dependencies = plugin.dependencies || {};

  badges.push(dependencies.classification === "heavyweight"
    ? {
        icon: "server-network",
        label: t("plugins.badges.heavyweight"),
        tooltip: dependencies.hint || t("plugins.badges.heavyweightTooltip"),
        style: { backgroundColor: theme.colors.secondaryContainer },
        textStyle: { color: theme.colors.onSecondaryContainer },
      }
    : {
        icon: "leaf",
        label: t("plugins.badges.lightweight"),
        tooltip: dependencies.hint || t("plugins.badges.lightweightTooltip"),
        style: { backgroundColor: theme.colors.surfaceVariant },
        textStyle: { color: theme.colors.onSurfaceVariant },
      });

  if ((dependencies.images || []).length) {
    badges.push({
      icon: "package-variant-closed",
      label: t("plugins.badges.imagesCount", { count: dependencies.images.length }),
      tooltip: t("plugins.badges.imagesTooltip"),
      style: { backgroundColor: theme.colors.primaryContainer },
      textStyle: { color: theme.colors.onPrimaryContainer },
    });
  }

  if (dependencies.host?.meetsRequirements === false) {
    badges.push({
      icon: "alert",
      label: t("plugins.badges.hostShortfall"),
      tooltip: (dependencies.warnings || []).join(" ") || t("plugins.badges.hostShortfallTooltip"),
      style: { backgroundColor: theme.colors.errorContainer },
      textStyle: { color: theme.colors.onErrorContainer },
    });
  }

  return badges;
};

const buildPluginBadges = (plugin, theme, t) => {
  const badges = [];

  if (plugin.loadStatus === "failed") {
    badges.push({
      icon: "alert-circle",
      label: t("plugins.badges.failedToLoad"),
      tooltip: plugin.lastError || t("plugins.badges.failedToLoadTooltip"),
      style: { backgroundColor: theme.colors.errorContainer },
      textStyle: { color: theme.colors.onErrorContainer },
    });
  } else if (plugin.enabled) {
    badges.push({
      icon: "check-circle",
      label: t("plugins.badges.active"),
      tooltip: t("plugins.badges.activeTooltip"),
      style: { backgroundColor: theme.colors.primaryContainer },
      textStyle: { color: theme.colors.onPrimaryContainer },
    });
  } else {
    badges.push({
      icon: "pause-circle",
      label: t("plugins.badges.disabled"),
      tooltip: t("plugins.badges.disabledTooltip"),
      style: { backgroundColor: theme.colors.secondaryContainer },
      textStyle: { color: theme.colors.onSecondaryContainer },
    });
  }

  const trustBadges = {
    development: {
      icon: "flask",
      label: t("plugins.badges.liveSource"),
      tooltip: t("plugins.badges.liveSourceTooltip"),
      style: { backgroundColor: theme.colors.inversePrimary },
      textStyle: { color: theme.colors.onPrimaryContainer },
    },
    official: {
      icon: "shield-check",
      label: t("plugins.badges.official"),
      tooltip: t("plugins.badges.officialTooltip"),
      style: { backgroundColor: theme.colors.tertiaryContainer },
      textStyle: { color: theme.colors.onTertiaryContainer },
    },
    signed: {
      icon: "certificate",
      label: t("plugins.badges.signed"),
      tooltip: t("plugins.badges.signedTooltip"),
      style: { backgroundColor: theme.colors.success || "#0a9900" },
      textStyle: { color: theme.colors.onSuccess || "#ffffff" },
    },
    trusted: {
      icon: "shield-check",
      label: t("plugins.badges.trusted"),
      tooltip: t("plugins.badges.trustedTooltip"),
      style: { backgroundColor: theme.colors.tertiaryContainer },
      textStyle: { color: theme.colors.onTertiaryContainer },
    },
    invalid_signature: {
      icon: "shield-remove",
      label: t("plugins.badges.badSignature"),
      tooltip: t("plugins.badges.badSignatureTooltip"),
      style: { backgroundColor: theme.colors.errorContainer },
      textStyle: { color: theme.colors.onErrorContainer },
    },
    unsigned: {
      icon: "shield-alert",
      label: t("plugins.badges.unsigned"),
      tooltip: t("plugins.badges.unsignedTooltip"),
      style: { backgroundColor: theme.colors.errorContainer },
      textStyle: { color: theme.colors.onErrorContainer },
    },
  };

  badges.push(trustBadges[plugin.trustLevel] || {
    icon: "shield-outline",
    label: plugin.trustLevel || t("plugins.badges.unknown"),
    tooltip: t("plugins.badges.unknownTooltip", { level: plugin.trustLevel || "unknown" }),
    style: { backgroundColor: theme.colors.surfaceVariant },
    textStyle: { color: theme.colors.onSurfaceVariant },
  });

  if (plugin.installSource?.type === "trusted_registry") {
    badges.push({
      icon: "information",
      label: plugin.installSource?.registry?.source?.name || t("plugins.badges.trustedRegistry"),
      tooltip: t("plugins.badges.trustedRegistryTooltip"),
      style: { backgroundColor: theme.colors.secondaryContainer },
      textStyle: { color: theme.colors.onSecondaryContainer },
    });
  }

  if (plugin.updateAvailable) {
    badges.push({
      icon: "update",
      label: plugin.latestVersion ? t("plugins.badges.updateVersion", { version: plugin.latestVersion }) : t("plugins.badges.updateAvailable"),
      tooltip: plugin.latestVersion
        ? t("plugins.badges.updateVersionTooltip", { version: plugin.latestVersion })
        : t("plugins.badges.updateAvailableTooltip"),
      style: { backgroundColor: theme.colors.secondaryContainer },
      textStyle: { color: theme.colors.onSecondaryContainer },
    });
  }

  return [ ...badges, ...buildDependencyBadges(plugin, theme, t) ];
};

const buildRequirementSummary = (plugin, t) => {
  const requirements = plugin.dependencies?.requirements || {};
  const parts = [];

  if (requirements.cpuCores) {
    parts.push(t("plugins.requirements.cpuCores", { count: requirements.cpuCores }));
  }

  if (requirements.memoryMb) {
    parts.push(t("plugins.requirements.memoryMb", { count: requirements.memoryMb }));
  }

  return parts.length ? t("plugins.requirements.minimumHostTarget", { value: parts.join(" • ") }) : null;
};

const buildRegistrySourceBadge = (source, theme, t) => {
  if (source?.official) {
    return {
      icon: "shield-check",
      label: t("plugins.officialRegistry"),
      style: { backgroundColor: theme.colors.tertiaryContainer },
      textStyle: { color: theme.colors.onTertiaryContainer },
      tooltip: t("plugins.officialRegistryTooltip"),
    };
  }

  return {
    icon: "information",
    label: source?.name || t("plugins.trustedSource"),
    style: { backgroundColor: theme.colors.secondaryContainer },
    textStyle: { color: theme.colors.onSecondaryContainer },
    tooltip: t("plugins.trustedSourceTooltip"),
  };
};

const normalizeLocale = (locale) => (
  locale === "es_AR" ? "es_AR" : (locale || "en")
);

const buildFailedToStartSuffix = (locale, failedCount) => {
  if (!failedCount) {
    return "";
  }

  switch (normalizeLocale(locale)) {
    case "es":
    case "es_AR":
      return `, ${failedCount} no pudieron iniciar`;
    case "fr":
      return `, ${failedCount} n'ont pas pu démarrer`;
    case "pt":
      return `, ${failedCount} falharam ao iniciar`;
    case "it":
      return `, ${failedCount} non sono riusciti ad avviarsi`;
    case "de":
      return `, ${failedCount} konnten nicht gestartet werden`;
    default:
      return `, ${failedCount} failed to start`;
  }
};

const buildClearedOverridesSuffix = (locale, count) => {
  if (!count) {
    return "";
  }

  switch (normalizeLocale(locale)) {
    case "es":
    case "es_AR":
      return ` Se limpiaron ${count} anulaciones de plugins.`;
    case "fr":
      return ` ${count} surcharges de plugin ont été supprimées.`;
    case "pt":
      return ` ${count} substituições de plugins foram removidas.`;
    case "it":
      return ` Sono state rimosse ${count} eccezioni di plugin.`;
    case "de":
      return ` ${count} Plugin-Überschreibungen wurden entfernt.`;
    default:
      return ` Cleared ${count} plugin overrides.`;
  }
};

const buildMutationFeedback = (response, variables = {}, t, locale = "en") => {
  const pluginId = response?.data?.id;
  const loadStatus = response?.data?.loadStatus;
  const lastError = response?.data?.lastError;
  const updateStatus = response?.data?.updateStatus;
  const latestVersion = response?.data?.latestVersion || response?.data?.version;

  if (loadStatus === "failed" && lastError) {
    return {
      message: t("plugins.feedback.failedToLoad", { id: pluginId, reason: lastError }),
      variant: "warning",
    };
  }

  if (variables.intent === "update") {
    if (updateStatus === "noop") {
      return {
        message: pluginId
          ? t("plugins.feedback.noUpdatesForPlugin", { id: pluginId, version: latestVersion ? ` (${latestVersion})` : "" })
          : t("plugins.feedback.noUpdates"),
        variant: "info",
      };
    }

    if (updateStatus === "unsupported") {
      return {
        message: pluginId
          ? t("plugins.feedback.noAutomaticUpdateSourceForPlugin", { id: pluginId })
          : t("plugins.feedback.noAutomaticUpdateSource"),
        variant: "info",
      };
    }

    if (updateStatus === "refreshed") {
      return {
        message: pluginId
          ? t("plugins.feedback.refreshedPlugin", { id: pluginId })
          : t("plugins.feedback.refreshed"),
        variant: "success",
      };
    }

    return {
      message: pluginId ? t("plugins.feedback.updatedPlugin", { id: pluginId }) : t("plugins.feedback.updated"),
      variant: "success",
    };
  }

  if (variables.intent === "toggle") {
    return {
      message: pluginId
        ? t(response?.data?.enabled ? "plugins.feedback.enabledPlugin" : "plugins.feedback.disabledPlugin", { id: pluginId })
        : t("plugins.feedback.stateUpdated"),
      variant: "success",
    };
  }

  if (variables.intent === "install") {
    return {
      message: pluginId ? t("plugins.feedback.installedPlugin", { id: pluginId }) : t("plugins.feedback.installed"),
      variant: "success",
    };
  }

  if (variables.intent === "safe-mode") {
    return {
      message: t("plugins.feedback.safeModeEnabled", { count: response?.data?.disabledCount ?? 0 }),
      variant: "info",
    };
  }

  if (variables.intent === "check-updates-all") {
    return {
      message: t("plugins.feedback.checkedUpdatesSummary", {
        checked: response?.data?.checkedCount ?? 0,
        available: response?.data?.updatesAvailableCount ?? 0,
        current: response?.data?.upToDateCount ?? 0,
        unsupported: response?.data?.unsupportedCount ?? 0,
      }),
      variant: "info",
    };
  }

  if (variables.intent === "update-all") {
    return {
      message: t("plugins.feedback.updateAllSummary", {
        checked: response?.data?.checkedCount ?? 0,
        updated: response?.data?.updatedCount ?? 0,
        current: response?.data?.noopCount ?? 0,
        unsupported: response?.data?.unsupportedCount ?? 0,
      }),
      variant: (response?.data?.updatedCount ?? 0) > 0 ? "success" : "info",
    };
  }

  if (variables.intent === "disable-all") {
    return {
      message: t("plugins.feedback.disabledMany", { count: response?.data?.disabledCount ?? 0 }),
      variant: "info",
    };
  }

  if (variables.intent === "enable-all") {
    const failedCount = response?.data?.failedCount ?? 0;

    return {
      message: `${t("plugins.feedback.enabledMany", {
        count: response?.data?.enabledCount ?? 0,
      })}${buildFailedToStartSuffix(locale, failedCount)}`,
      variant: failedCount > 0 ? "warning" : "success",
    };
  }

  if (variables.intent === "plugin-automatic-updates") {
    return {
      message: pluginId
        ? t(
            response?.data?.automaticUpdatesEnabled
              ? "plugins.feedback.enabledAutomaticUpdatesForPlugin"
              : "plugins.feedback.disabledAutomaticUpdatesForPlugin",
            { id: pluginId }
          )
        : t("plugins.feedback.pluginAutomaticUpdatePreferenceSaved"),
      variant: "info",
    };
  }

  if (variables.intent === "global-automatic-updates") {
    const disabledOverridesCount = response?.data?.disabledPluginAutomaticUpdatesCount ?? 0;

    return {
      message: response?.data?.automaticUpdatesEnabled
        ? t("plugins.feedback.globalAutomaticUpdatesEnabled")
        : `${t("plugins.feedback.globalAutomaticUpdatesDisabled")}${buildClearedOverridesSuffix(locale, disabledOverridesCount)}`,
      variant: "info",
    };
  }

  return {
    message: pluginId ? t("plugins.feedback.updatedPlugin", { id: pluginId }) : t("plugins.feedback.operationCompleted"),
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
  installErrorDialog: null,
  confirmationDialog: null,
  logsDialogPlugin: null,
};

const NavBarMenuSettingsModalPlugins = ({ pluginSettingsPages = [], onOpenSettingsPage = () => {} }) => {
  const queryClient = useQueryClient();
  const { enqueueSnackbar } = useSnackbar();
  const theme = useTheme();
  const { effectiveLanguage, t } = useLocalization();
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
  const [ activeInstall, setActiveInstall ] = useState(null);

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
  const installErrorDialog = overlayState.installErrorDialog;
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

  const setInstallErrorDialog = (value) => updateOverlayState({
    installErrorDialog: typeof value === "function" ? value(persistedOverlayState.installErrorDialog) : value,
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

  const pluginDoctorQuery = useQuery({
    queryKey: ["pluginDoctor"],
    queryFn: () => API.get("/plugins/doctor"),
    enabled: isAdministrator,
    retry: false,
    refetchOnWindowFocus: false,
    staleTime: 0,
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

  const pluginDiagnosticsById = useMemo(
    () => Object.fromEntries((pluginDoctorQuery?.data?.data || []).map((entry) => [entry.id, entry])),
    [ pluginDoctorQuery?.data?.data ],
  );

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
      queryClient.invalidateQueries({ queryKey: ["pluginDoctor"] });
      queryClient.invalidateQueries({ queryKey: ["pluginPreferences"] });

      const feedback = buildMutationFeedback(response, variables, t, effectiveLanguage);

      enqueueSnackbar({
        message: feedback.message,
        variant: feedback.variant,
        action: { label: t("notifications.gotIt") },
      });

      if (variables?.intent === "install" && variables?.source === "registry") {
        setMarketplaceVisible(false);
      }
    },
    onError: (error, variables) => {
      const message = error?.response?.data?.message || error?.message || t("plugins.installFailedBody");

      if (variables?.intent === "install") {
        setInstallErrorDialog({
          title: t("plugins.installFailedTitle"),
          message: t("plugins.installFailedBody"),
          details: message,
        });

        return;
      }

      enqueueSnackbar({
        message,
        variant: "error",
        action: { label: t("notifications.gotIt") },
      });
    },
    onSettled: (_response, _error, variables) => {
      if (variables?.intent === "install") {
        setActiveInstall(null);
      }
    },
  });

  const isAnyPluginInstallPending = mutateAndRefresh.isPending && !!activeInstall;

  const deleteMutation = useMutation({
    mutationFn: (pluginId) => API.delete(`/plugins/${pluginId}`),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["plugins"] });
      queryClient.invalidateQueries({ queryKey: ["pluginExtensions"] });

      enqueueSnackbar({
        message: t("plugins.pluginRemoved"),
        variant: "success",
        action: { label: t("notifications.gotIt") },
      });
    },
    onError: (error) => {
      enqueueSnackbar({
        message: error?.response?.data?.message || error.message,
        variant: "error",
        action: { label: t("notifications.gotIt") },
      });
    }
  });

  const deleteRuntimeStorageMutation = useMutation({
    mutationFn: ({ pluginId, expectedVolume }) => API.delete(`/plugins/${pluginId}/runtime-storage`, { expectedVolume }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["plugins"] });
      queryClient.invalidateQueries({ queryKey: ["pluginDoctor"] });
      enqueueSnackbar({
        message: t("plugins.retainedDataDeleted"),
        variant: "success",
        action: { label: t("notifications.gotIt") },
      });
    },
    onError: (error) => {
      enqueueSnackbar({
        message: error?.response?.data?.message || error.message,
        variant: "error",
        action: { label: t("notifications.gotIt") },
      });
    },
  });

  const updateRegistrySourcesMutation = useMutation({
    mutationFn: (sources) => API.put("/plugins/registry/sources", { sources }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["pluginRegistrySources"] });
      queryClient.invalidateQueries({ queryKey: ["pluginRegistry"] });

      enqueueSnackbar({
        message: t("plugins.registrySourcesUpdated"),
        variant: "success",
        action: { label: t("notifications.gotIt") },
      });

      setRegistrySourceName("");
      setRegistrySourceIndexUrl("");
      setRegistrySourceWebsiteUrl("");
    },
    onError: (error) => {
      enqueueSnackbar({
        message: error?.response?.data?.message || error.message,
        variant: "error",
        action: { label: t("notifications.gotIt") },
      });
    }
  });

  const handleUpload = (file) => {
    if (!file) { return; }

    setActiveInstall({
      kind: "upload",
      name: file?.name || t("plugins.choosePackage"),
    });
    mutateAndRefresh.mutate({
      url: "/plugins/install",
      body: { package: file },
      intent: "install",
      source: "upload",
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

    if (confirmationDialog.kind === "delete-retained-storage") {
      deleteRuntimeStorageMutation.mutate({
        pluginId: confirmationDialog.plugin.id,
        expectedVolume: confirmationDialog.expectedVolume,
      });
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

    setActiveInstall({
      kind: "url",
      name: installUrl,
    });
    mutateAndRefresh.mutate({
      url: "/plugins/install",
      body: { url: installUrl },
      intent: "install",
      source: "url",
    });

    setInstallModalVisible(false);
    setInstallUrl("");
  };

  const installFromRegistry = (plugin) => {
    const installKey = buildRegistryPluginInstallKey(plugin);

    setActiveInstall({
      kind: "registry",
      key: installKey,
      name: plugin.name || plugin.id,
    });
    mutateAndRefresh.mutate({
      url: "/plugins/install",
      body: {
        pluginId: plugin.id,
        version: plugin.version || plugin.latestVersion,
        sourceId: plugin.registrySource?.id,
      },
      intent: "install",
      source: "registry",
      installKey,
    });
  };

  const installFromDevelopmentPath = (path) => {
    setActiveInstall({
      kind: "development",
      name: path,
    });
    mutateAndRefresh.mutate({
      url: "/plugins/install",
      body: { unpackedPath: path },
      intent: "install",
      source: "development",
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
              <Text variant="headlineSmall">{t("plugins.title")}</Text>
              <Text>
                {t("plugins.manageDescription")}
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
                  {t("plugins.addPlugin")}
                </Button>
                <Button
                  mode="contained-tonal"
                  icon="store-search"
                  onPress={openMarketplace}
                  style={pillButtonStyle}
                  contentStyle={pillButtonContentStyle}
                >
                  {t("plugins.marketplace")}
                </Button>
                <Menu
                  visible={globalActionsMenuVisible}
                  onDismiss={() => setGlobalActionsMenuVisible(false)}
                  anchor={(
                    <View style={{ alignSelf: "flex-start" }}>
                      <IconButton
                        icon="dots-vertical"
                        mode="outlined"
                        accessibilityLabel={t("plugins.pluginManagerActions")}
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
                    title={t("plugins.checkForUpdates")}
                    onPress={() => {
                      setGlobalActionsMenuVisible(false);
                      mutateAndRefresh.mutate({ url: "/plugins/check-updates", body: {}, intent: "check-updates-all" });
                    }}
                  />
                  <Menu.Item
                    leadingIcon="update"
                    title={t("plugins.updateAll")}
                    onPress={() => {
                      setGlobalActionsMenuVisible(false);
                      mutateAndRefresh.mutate({ url: "/plugins/update-all", body: {}, intent: "update-all" });
                    }}
                  />
                  <Menu.Item
                    leadingIcon="pause-circle-outline"
                    title={t("plugins.disableAll")}
                    onPress={() => {
                      setGlobalActionsMenuVisible(false);
                      openConfirmationDialog({
                        kind: "disable-all",
                        title: t("plugins.disableAllConfirmTitle"),
                        body: t("plugins.disableAllConfirmBody"),
                        confirmLabel: t("plugins.disableAll"),
                      });
                    }}
                  />
                  <Menu.Item
                    leadingIcon="power"
                    title={t("plugins.enableAll")}
                    onPress={() => {
                      setGlobalActionsMenuVisible(false);
                      openConfirmationDialog({
                        kind: "enable-all",
                        title: t("plugins.enableAllConfirmTitle"),
                        body: t("plugins.enableAllConfirmBody"),
                        confirmLabel: t("plugins.enableAll"),
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
                    <Text>{t("plugins.automaticUpdates")}</Text>
                  </Pressable>
                  <Divider />
                  <Menu.Item
                    leadingIcon="shield-alert"
                    title={t("plugins.safeMode")}
                    onPress={() => {
                      setGlobalActionsMenuVisible(false);
                      openConfirmationDialog({
                        kind: "safe-mode",
                        title: t("plugins.safeModeConfirmTitle"),
                        body: t("plugins.safeModeConfirmBody"),
                        confirmLabel: t("plugins.disableAllPlugins"),
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
              <Text variant="titleMedium">{t("plugins.installedPlugins")}</Text>
              <Text style={{ color: theme.colors.onSurfaceVariant }}>
                {t("plugins.installedDescription")}
              </Text>
            </View>

            <View style={{ flexDirection: "row", flexWrap: "wrap", gap: 16 }}>
                {(installedPluginsQuery?.data?.data || []).map((plugin) => (
                  (() => {
                    const toggleActionLabel = plugin.enabled ? t("plugins.disable") : (plugin.loadStatus === "failed" ? t("plugins.retry") : t("plugins.enable"));
                    const toggleActionIcon = plugin.enabled ? "pause-circle-outline" : (plugin.loadStatus === "failed" ? "restart" : "power");
                    const updateActionLabel = plugin.installSource?.type === "development_mount" ? t("plugins.refresh") : t("plugins.update");
                    const updateActionIcon = plugin.installSource?.type === "development_mount" ? "refresh" : "update";
                    const hasSettingsPage = !!settingsPageMap[plugin.id];

                    const openToggleDialog = () => openConfirmationDialog({
                      kind: "toggle",
                      plugin,
                      title: plugin.enabled
                        ? t("plugins.disablePluginTitle", { name: plugin.name })
                        : t(
                            plugin.loadStatus === "failed"
                              ? "plugins.retryPluginTitle"
                              : "plugins.enablePluginTitle",
                            { name: plugin.name }
                          ),
                      body: plugin.enabled
                        ? t("plugins.disablePluginBody")
                        : (plugin.loadStatus === "failed"
                          ? t("plugins.retryPluginBody")
                          : t("plugins.enablePluginBody")),
                      confirmLabel: plugin.enabled
                        ? t("plugins.disablePlugin")
                        : (plugin.loadStatus === "failed" ? t("plugins.retryPlugin") : t("plugins.enablePlugin")),
                    });

                    const openRemoveDialog = () => openConfirmationDialog({
                      kind: "remove",
                      plugin,
                      title: t("plugins.removePluginTitle", { name: plugin.name }),
                      body: t("plugins.removePluginBody"),
                      confirmLabel: t("plugins.removePlugin"),
                    });

                    const retainedVolumes = (pluginDiagnosticsById[plugin.id]?.runtimeDiagnostics || [])
                      .flatMap((diagnostic) => diagnostic.volumes || [])
                      .filter((volume) => volume.present && volume.name);
                    const openRetainedStorageDialog = (volume) => openConfirmationDialog({
                      kind: "delete-retained-storage",
                      plugin,
                      expectedVolume: volume.name,
                      title: t("plugins.deleteRetainedDataTitle", { name: plugin.name }),
                      body: t("plugins.deleteRetainedDataBody"),
                      confirmLabel: t("plugins.deleteRetainedData"),
                    });

                    const secondaryActions = [
                      {
                        key: "logs",
                        icon: "text-box-search-outline",
                        label: t("plugins.logs"),
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
                        label: t("plugins.remove"),
                        titleStyle: { color: theme.colors.error },
                        onPress: openRemoveDialog,
                      },
                      ...(!plugin.enabled ? retainedVolumes.map((volume) => ({
                        key: `delete-retained-storage-${volume.name}`,
                        icon: "database-remove-outline",
                        label: t("plugins.deleteRetainedData"),
                        titleStyle: { color: theme.colors.error },
                        onPress: () => openRetainedStorageDialog(volume),
                      })) : []),
                    ];

                    return (
                      <Card key={plugin.id} style={gridCardStyle(theme, cardWidth, isWideLayout)}>
                        <Card.Title title={plugin.name} subtitle={`${plugin.id} • ${plugin.version}`} />
                        <Card.Content>
                          <View style={{ flexDirection: "row", flexWrap: "wrap", gap: 8, marginBottom: 12 }}>
                            {buildPluginBadges(plugin, theme, t).map((badge) => (
                              <Tooltip key={`${plugin.id}-${badge.label}`} title={badge.tooltip}>
                                <PluginBadgeChip badge={badge} />
                              </Tooltip>
                            ))}
                          </View>

                          {!!plugin.dependencies?.hint && (
                            <Text style={{ color: theme.colors.onSurfaceVariant, marginBottom: 10 }}>
                              {plugin.dependencies.hint}
                            </Text>
                          )}

                          {!!buildRequirementSummary(plugin, t) && (
                            <Text style={{ color: theme.colors.onSurfaceVariant, marginBottom: 12 }}>
                              {buildRequirementSummary(plugin, t)}
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
                                {t("plugins.pluginFailedToLoad")}
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
                              <Text style={{ fontWeight: "700" }}>{t("plugins.automaticUpdates")}</Text>
                              <Text style={{ color: theme.colors.onSurfaceVariant }}>
                                {plugin.automaticUpdatesSupported
                                  ? (globalAutomaticUpdatesEnabled
                                    ? t("plugins.automaticUpdatesDescriptionEnabled")
                                    : t("plugins.automaticUpdatesDescriptionDisabled"))
                                  : t("plugins.automaticUpdatesDescriptionUnsupported")}
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
                                  {t("profile.settings")}
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
                                    accessibilityLabel={t("plugins.moreActions", { name: plugin.name })}
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
                                  {t("profile.settings")}
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
                                {t("plugins.logs")}
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
                                {t("plugins.remove")}
                              </Button>
                              {!plugin.enabled && retainedVolumes.map((volume) => (
                                <Button
                                  key={`delete-retained-storage-${volume.name}`}
                                  mode="contained"
                                  icon="database-remove-outline"
                                  buttonColor={theme.colors.error}
                                  textColor={theme.colors.white || "#ffffff"}
                                  iconColor={theme.colors.white || "#ffffff"}
                                  style={pillButtonStyle}
                                  contentStyle={pillButtonContentStyle}
                                  onPress={() => openRetainedStorageDialog(volume)}
                                >
                                  {t("plugins.deleteRetainedData")}
                                </Button>
                              ))}
                            </View>
                          )}
                        </Card.Content>
                      </Card>
                    );
                  })()
                ))}

              {!installedPluginsQuery?.data?.data?.length && (
                <Text style={{ marginTop: 12 }}>
                  {t("plugins.emptyInstalled")}
                </Text>
              )}
            </View>
          </View>
        )}
      </ScrollView>

      <SimpleDialog
        visible={installModalVisible}
        setVisible={setInstallModalVisible}
        title={t("plugins.installTitle")}
        style={{ maxWidth: 920 }}
        content={
          <View style={{ minHeight: 340 }}>
            <View style={{ flexDirection: "row", flexWrap: "wrap", gap: 8, marginBottom: 16 }}>
              <Button
                mode={installModalTab === "url" ? "contained-tonal" : "text"}
                icon="link"
                onPress={() => setInstallModalTab("url")}
              >
                {t("plugins.installFromUrl")}
              </Button>
              <Button
                mode={installModalTab === "upload" ? "contained-tonal" : "text"}
                icon="upload"
                onPress={() => setInstallModalTab("upload")}
              >
                {t("plugins.uploadFromFile")}
              </Button>
              {developerModeEnabled && (
                <Button
                  mode={installModalTab === "development" ? "contained-tonal" : "text"}
                  icon="flask"
                  onPress={() => setInstallModalTab("development")}
                >
                  {t("plugins.installUnpacked")}
                </Button>
              )}
            </View>

            {installModalTab === "url" && (
              <View style={{ gap: 12, paddingTop: 8 }}>
                <Text>
                  {t("plugins.installUrlDescription")}
                </Text>
                <TextInput
                  mode="outlined"
                  label={t("plugins.packageUrl")}
                  value={installUrl}
                  onChangeText={setInstallUrl}
                  placeholder="https://example.com/plugin.w3dp"
                />
                <View style={{ flexDirection: "row", justifyContent: "flex-end" }}>
                  <Button mode="contained" onPress={installFromUrl} disabled={!installUrl}>
                    {t("plugins.installFromUrl")}
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
                    {t("plugins.choosePackage")}
                  </Button>

                  <Text
                    style={{
                      textAlign: "center",
                      color: theme.colors.onSurfaceVariant,
                      maxWidth: 320,
                    }}
                  >
                    {t("plugins.dragDropPackagePrefix")}<Text style={{ fontWeight: "700", color: theme.colors.onSurface }}>.w3dp</Text>{t("plugins.dragDropPackageSuffix")}
                  </Text>
                </View>
              </View>
            )}

            {installModalTab === "development" && developerModeEnabled && (
              <View style={{ gap: 14, paddingTop: 8 }}>
                <Text style={{ textAlign: "center", color: theme.colors.onSurfaceVariant }}>
                  {t("plugins.developmentDescription")}
                </Text>

                {developmentPluginsQuery.isPending && (
                  <Text style={{ textAlign: "center", color: theme.colors.onSurfaceVariant }}>
                    {t("plugins.checkingLiveDevelopmentMount")}
                  </Text>
                )}

                {developmentPluginsQuery.isError && (
                  <Card style={{ borderRadius: 18, backgroundColor: theme.colors.elevation.level1 }}>
                    <Card.Content style={{ gap: 10 }}>
                      <Text variant="titleMedium">{t("plugins.developmentMountUnavailable")}</Text>
                      <Text style={{ color: theme.colors.onSurfaceVariant }}>
                        {t("plugins.developmentMountUnavailableDescription")}
                      </Text>
                      <Button mode="outlined" onPress={() => developmentPluginsQuery.refetch()}>
                        {t("plugins.retry")}
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
                            {t("plugins.refresh")}
                          </Button>
                        </View>
                        <View style={{ gap: 8 }}>
                          <Text style={{ textAlign: "center" }}>
                            {t("plugins.sourceMounts")}
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
                                        <Chip compact icon={plugin.mountLabel === t("plugins.localPluginsMountLabel") ? "folder-home-outline" : "flask-outline"}>
                                          {plugin.mountLabel === t("plugins.localPluginsMountLabel") ? t("plugins.localPlugins") : plugin.mountLabel}
                                        </Chip>
                                      )}
                                    </View>
                                    <Text>{plugin.id} • {plugin.version}</Text>
                                  </View>
                                  {!!plugin.description && <Text>{plugin.description}</Text>}
                                  <Text style={{ color: theme.colors.onSurfaceVariant }}>
                                    {t("plugins.mountedPath", { path: plugin.relativePath || plugin.path })}
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
                                    {t("plugins.installUnpackedPlugin")}
                                  </Button>
                                </Card.Content>
                              </Card>
                            ))}
                            {!developmentPluginsQuery?.data?.data?.plugins?.length && (
                              <Text style={{ textAlign: "center", color: theme.colors.onSurfaceVariant, width: "100%" }}>
                                {t("plugins.noUnpackedPlugins")}
                              </Text>
                            )}
                          </View>
                        </ScrollView>
                      </>
                    ) : (
                      <Card style={{ borderRadius: 18, backgroundColor: theme.colors.elevation.level1 }}>
                        <Card.Content style={{ gap: 10 }}>
                          <Text variant="titleMedium">{t("plugins.liveDevelopmentMountUnavailable")}</Text>
                          <Text style={{ color: theme.colors.onSurfaceVariant }}>
                            {t("plugins.liveDevelopmentMountUnavailableDescription")}
                          </Text>
                          {!!developmentPluginsQuery?.data?.data?.configuredMountPath && (
                            <Text style={{ color: theme.colors.onSurfaceVariant }}>
                              {t("plugins.configuredMountPath", { path: developmentPluginsQuery?.data?.data?.configuredMountPath })}
                            </Text>
                          )}
                          <Button mode="outlined" icon="refresh" onPress={() => developmentPluginsQuery.refetch()}>
                            {t("plugins.refresh")}
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
            {t("plugins.close")}
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
        title={t("plugins.marketplaceTitle")}
        style={{ maxWidth: 1180 }}
        content={
          <View style={{ minHeight: 420, maxHeight: window.height * 0.72, gap: 16 }}>
            <View style={{ flexDirection: "row", justifyContent: "space-between", alignItems: "flex-start", gap: 16 }}>
              <View style={{ flex: 1, gap: 6 }}>
                <Text variant="titleMedium">{t("plugins.registryIntroTitle")}</Text>
                <Text style={{ color: theme.colors.onSurfaceVariant }}>
                  {t("plugins.registryIntroDescription")}
                </Text>
              </View>
              <Tooltip title={t("plugins.manageRegistrySources")}>
                <IconButton
                  accessibilityLabel={t("plugins.registrySources")}
                  icon="cog-outline"
                  mode="contained-tonal"
                  onPress={() => setRegistrySourcesVisible(true)}
                />
              </Tooltip>
            </View>

            <Searchbar
              value={registryQuery}
              onChangeText={setRegistryQuery}
              placeholder={t("plugins.searchRegistryPlugins")}
            />

            <ScrollView contentContainerStyle={{ paddingBottom: 8 }}>
              <View style={{ flexDirection: "row", flexWrap: "wrap", gap: 16 }}>
                {filteredRegistryPlugins.map((plugin) => {
                  const sourceBadge = buildRegistrySourceBadge(plugin.registrySource, theme, t);
                  const installKey = buildRegistryPluginInstallKey(plugin);
                  const isInstallingThisPlugin = isAnyPluginInstallPending
                    && activeInstall?.kind === "registry"
                    && activeInstall?.key === installKey;

                  return (
                    <Card key={`${plugin.registrySource?.id || "registry"}-${plugin.id}`} style={gridCardStyle(theme, cardWidth, isWideLayout)}>
                      <Card.Content style={{ gap: 10 }}>
                        <View style={{ gap: 4 }}>
                          <Text variant="titleMedium">{plugin.name}</Text>
                          <Text>{plugin.id} • {plugin.version || plugin.latestVersion}</Text>
                        </View>

                        <View style={{ flexDirection: "row", flexWrap: "wrap", gap: 8 }}>
                          <Tooltip title={sourceBadge.tooltip}>
                            <PluginBadgeChip badge={sourceBadge} />
                          </Tooltip>
                          {buildDependencyBadges(plugin, theme, t).map((badge) => (
                            <Tooltip key={`${plugin.registrySource?.id || "registry"}-${plugin.id}-${badge.label}`} title={badge.tooltip}>
                              <PluginBadgeChip badge={badge} />
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

                        {!!buildRequirementSummary(plugin, t) && (
                          <Text style={{ color: theme.colors.onSurfaceVariant }}>
                            {buildRequirementSummary(plugin, t)}
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
                                {t("plugins.trustedRegistry")}
                              </Text>
                              <Text style={{ color: theme.colors.onSecondaryContainer }}>
                                {t("plugins.trustedRegistryDescription")}
                              </Text>
                            </View>
                          </View>
                        )}

                        <Button
                          mode="contained"
                          loading={isInstallingThisPlugin}
                          disabled={isAnyPluginInstallPending}
                          onPress={() => installFromRegistry(plugin)}
                        >
                          {isInstallingThisPlugin ? t("plugins.installing") : t("plugins.install")}
                        </Button>
                      </Card.Content>
                    </Card>
                  );
                })}

                {!filteredRegistryPlugins.length && !registryPluginsQuery.isLoading && (
                  <Card style={{ borderRadius: 18, width: "100%" }}>
                    <Card.Content>
                      <Text>{t("plugins.noRegistryMatches")}</Text>
                    </Card.Content>
                  </Card>
                )}
              </View>
            </ScrollView>
          </View>
        }
        actions={
          <Button mode="text" onPress={() => setMarketplaceVisible(false)}>
            {t("plugins.close")}
          </Button>
        }
      />

      <SimpleDialog
        visible={registrySourcesVisible}
        setVisible={setRegistrySourcesVisible}
        title={t("plugins.registryTitle")}
        style={{ maxWidth: 980 }}
        content={
          <View style={{ gap: 16, minHeight: 320 }}>
            <Text style={{ color: theme.colors.onSurfaceVariant }}>
              {t("plugins.registryDescription")}
            </Text>

            <Card style={{ borderRadius: 18, backgroundColor: theme.colors.elevation.level1 }}>
              <Card.Title title={t("plugins.officialRegistry")} subtitle={t("plugins.builtInAlwaysEnabled")} />
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
                            {t("plugins.website", { url: source.websiteUrl })}
                          </Text>
                        )}
                      </View>
                      <View style={{ flexDirection: "row", justifyContent: "space-between", alignItems: "center", gap: 12 }}>
                        <Text style={{ color: theme.colors.onSurfaceVariant, flex: 1 }}>
                          {t("plugins.pluginsFromSourceDescription")}
                        </Text>
                        <Button
                          mode="outlined"
                          onPress={() => openConfirmationDialog({
                            kind: "remove-registry-source",
                            source,
                            title: t("plugins.removeSourceTitle", { name: source.name }),
                            body: t("plugins.removeSourceBody"),
                            confirmLabel: t("plugins.removeSource"),
                          })}
                        >
                          {t("plugins.remove")}
                        </Button>
                      </View>
                    </Card.Content>
                  </Card>
                ))}

                {!trustedRegistrySources.length && (
                  <Card style={{ borderRadius: 18, backgroundColor: theme.colors.elevation.level1 }}>
                    <Card.Content>
                      <Text>{t("plugins.noTrustedRegistrySources")}</Text>
                    </Card.Content>
                  </Card>
                )}
              </View>
            </ScrollView>

            <Card style={{ borderRadius: 18, backgroundColor: theme.colors.elevation.level2 }}>
              <Card.Title title={t("plugins.addTrustedRegistry")} subtitle={t("plugins.marketplaceSourceSettings")} />
              <Card.Content style={{ gap: 12 }}>
                <TextInput
                  mode="outlined"
                  label={t("plugins.registryName")}
                  value={registrySourceName}
                  onChangeText={setRegistrySourceName}
                  placeholder={t("plugins.registryNamePlaceholder")}
                />
                <TextInput
                  mode="outlined"
                  label={t("plugins.indexUrl")}
                  value={registrySourceIndexUrl}
                  onChangeText={setRegistrySourceIndexUrl}
                  placeholder="https://plugins.example.com/index.json"
                  autoCapitalize="none"
                />
                <TextInput
                  mode="outlined"
                  label={t("plugins.websiteUrlOptional")}
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
                    {t("plugins.saveSource")}
                  </Button>
                </View>
              </Card.Content>
            </Card>
          </View>
        }
        actions={
          <Button mode="text" onPress={() => setRegistrySourcesVisible(false)}>
            {t("plugins.close")}
          </Button>
        }
      />

      <SimpleDialog
        visible={!!logsDialogPlugin}
        setVisible={() => setLogsDialogPlugin(null)}
        title={logsDialogPlugin ? t("plugins.pluginLogsTitleWithName", { name: logsDialogPlugin.name }) : t("plugins.pluginLogsTitle")}
        style={{ maxWidth: 980 }}
        content={
          <View style={{ gap: 16, minHeight: 320, maxHeight: window.height * 0.7 }}>
            <View style={{ flexDirection: "row", justifyContent: "space-between", alignItems: "center", gap: 12 }}>
              <Text style={{ color: theme.colors.onSurfaceVariant, flex: 1 }}>
                {t("plugins.pluginLogsDescription")}
              </Text>
              <Button mode="outlined" icon="refresh" onPress={() => pluginLogsQuery.refetch()}>
                {t("plugins.refresh")}
              </Button>
            </View>

            {pluginLogsQuery.isPending && (
              <Text style={{ color: theme.colors.onSurfaceVariant }}>
                {t("plugins.loadingPluginLogs")}
              </Text>
            )}

            {pluginLogsQuery.isError && (
              <Card style={{ borderRadius: 18, backgroundColor: theme.colors.errorContainer }}>
                <Card.Content>
                  <Text style={{ color: theme.colors.onErrorContainer }}>
                    {pluginLogsQuery.error?.response?.data?.message || pluginLogsQuery.error?.message || t("plugins.unableToLoadPluginLogs")}
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
                        <Text>{t("plugins.noLogsYet")}</Text>
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
            {t("plugins.close")}
          </Button>
        }
      />

      <SimpleDialog
        visible={!!installErrorDialog}
        setVisible={() => setInstallErrorDialog(null)}
        title={installErrorDialog?.title || t("plugins.installFailedTitle")}
        content={
          <View style={{ gap: 12 }}>
            <Text>{installErrorDialog?.message || t("plugins.installFailedBody")}</Text>
            {!!installErrorDialog?.details && (
              <View
                style={{
                  padding: 12,
                  borderRadius: 14,
                  backgroundColor: theme.colors.errorContainer,
                }}
              >
                <Text selectable style={{ color: theme.colors.onErrorContainer }}>
                  {installErrorDialog.details}
                </Text>
              </View>
            )}
          </View>
        }
        actions={
          <Button mode="contained" onPress={() => setInstallErrorDialog(null)}>
            {t("plugins.dismiss")}
          </Button>
        }
      />

      <SimpleDialog
        visible={!!confirmationDialog}
        setVisible={closeConfirmationDialog}
        title={confirmationDialog?.title || t("plugins.confirmAction")}
        content={<Text>{confirmationDialog?.body}</Text>}
        actions={
          <>
            <Button mode="text" onPress={closeConfirmationDialog}>
              {t("plugins.cancel")}
            </Button>
            <Button mode="contained" onPress={executeConfirmedAction}>
              {confirmationDialog?.confirmLabel || t("plugins.continue")}
            </Button>
          </>
        }
      />
    </View>
  );
};

export default memo(NavBarMenuSettingsModalPlugins);
