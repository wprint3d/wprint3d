import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from "react";
import { Portal, Card, ProgressBar, Text, ActivityIndicator, useTheme } from "react-native-paper";
import { View } from "react-native";
import Reanimated, { FadeInDown, FadeOutUp } from "react-native-reanimated";
import { useQuery } from "@tanstack/react-query";
import API from "../includes/API";
import { useLocalization } from "../includes/LocalizationProvider";

const PluginLoadingContext = createContext({
  registerSurface: () => {},
  unregisterSurface: () => {},
  reportSurfaceStatus: () => {},
  setPluginTaskState: () => {},
});

const normalizePluginIndex = (extensions = [], activeSurfaces = []) => {
  const surfaces = new Set(activeSurfaces);
  const plugins = new Map();

  for (const extension of (extensions || [])) {
    if (!surfaces.has(extension?.surface)) {
      continue;
    }

    const pluginId = extension?.pluginId;

    if (!pluginId) {
      continue;
    }

    const current = plugins.get(pluginId) || {
      id: pluginId,
      name: extension?.pluginName || pluginId,
      surfaces: new Set(),
    };

    current.surfaces.add(extension.surface);
    plugins.set(pluginId, current);
  }

  return Array.from(plugins.values())
    .map((plugin) => ({
      ...plugin,
      surfaces: Array.from(plugin.surfaces),
    }))
    .sort((left, right) => left.name.localeCompare(right.name));
};

const PluginLoadingToast = ({ visible, isComplete, totalPlugins, loadedPlugins, currentPluginName, detailMessage }) => {
  const theme = useTheme();
  const { t } = useLocalization();
  const progress = totalPlugins > 0 ? loadedPlugins / totalPlugins : 0;

  if (!visible) {
    return null;
  }

  return (
    <Portal>
      <Reanimated.View
        entering={FadeInDown.duration(180)}
        exiting={FadeOutUp.duration(180)}
        pointerEvents="none"
        style={{
          position: "absolute",
          top: 60,
          right: 16,
          left: 16,
          maxWidth: 420,
          zIndex: 9999,
          alignSelf: "flex-end",
        }}
      >
        <Card
          style={{
            borderRadius: 22,
            overflow: "hidden",
            borderWidth: 1,
            borderColor: theme.colors.outlineVariant,
            backgroundColor: theme.colors.elevation.level4,
            shadowColor: "#000",
            shadowOpacity: 0.2,
            shadowRadius: 18,
            shadowOffset: { width: 0, height: 10 },
          }}
        >
          <Card.Content style={{ gap: 12, paddingVertical: 16 }}>
            <View style={{ flexDirection: "row", alignItems: "center", gap: 12 }}>
              {isComplete
                ? (
                  <View
                    style={{
                      width: 28,
                      height: 28,
                      borderRadius: 14,
                      backgroundColor: theme.colors.primaryContainer,
                      alignItems: "center",
                      justifyContent: "center",
                    }}
                  >
                    <Text style={{ color: theme.colors.onPrimaryContainer, fontWeight: "700" }}>✓</Text>
                  </View>
                )
                : <ActivityIndicator size="small" animating color={theme.colors.primary} />}

              <View style={{ flex: 1, gap: 2 }}>
                <Text variant="titleMedium">
                  {isComplete ? t("plugins.loadingReadyTitle") : t("plugins.loadingPluginsTitle")}
                </Text>
                <Text style={{ color: theme.colors.onSurfaceVariant }}>
                  {detailMessage}
                </Text>
              </View>
            </View>

            {!isComplete && totalPlugins > 0 && (
              <>
                <ProgressBar
                  progress={progress}
                  color={theme.colors.primary}
                  style={{
                    height: 8,
                    borderRadius: 999,
                    backgroundColor: theme.colors.surfaceVariant,
                  }}
                />
                <View style={{ flexDirection: "row", justifyContent: "space-between", gap: 12 }}>
                  <Text style={{ color: theme.colors.onSurfaceVariant }}>
                    {t("plugins.loadingReadyCount", { loaded: loadedPlugins, total: totalPlugins })}
                  </Text>
                  {!!currentPluginName && (
                    <Text style={{ color: theme.colors.onSurfaceVariant, fontWeight: "600" }}>
                      {t("plugins.loadingCurrent", { name: currentPluginName })}
                    </Text>
                  )}
                </View>
              </>
            )}
          </Card.Content>
        </Card>
      </Reanimated.View>
    </Portal>
  );
};

export default function PluginLoadingProvider({ children }) {
  const { t } = useLocalization();
  const [ surfaceCounts, setSurfaceCounts ] = useState({});
  const [ surfaceStatuses, setSurfaceStatuses ] = useState({});
  const [ pluginTasks, setPluginTasks ] = useState({});
  const [ showCompletedToast, setShowCompletedToast ] = useState(false);
  const completionTimeoutRef = useRef(null);

  const activeSurfaces = useMemo(
    () => Object.entries(surfaceCounts).filter(([, count]) => count > 0).map(([surface]) => surface).sort(),
    [ surfaceCounts ]
  );

  const pluginIndexQuery = useQuery({
    queryKey: ["pluginLoadingIndex", activeSurfaces],
    queryFn: () => API.get("/plugins/ui"),
    enabled: activeSurfaces.length > 0,
    retry: false,
    refetchOnWindowFocus: false,
    staleTime: 30000,
  });

  const expectedPlugins = useMemo(
    () => normalizePluginIndex(pluginIndexQuery?.data?.data || [], activeSurfaces),
    [ activeSurfaces, pluginIndexQuery?.data?.data ]
  );

  const pendingTaskPlugins = useMemo(
    () => Object.values(pluginTasks).filter((plugin) => plugin?.pendingKeys?.length),
    [ pluginTasks ]
  );

  const trackedPlugins = useMemo(() => {
    const map = new Map(expectedPlugins.map((plugin) => [plugin.id, {
      ...plugin,
      pendingKeys: pluginTasks[plugin.id]?.pendingKeys || [],
    }]));

    for (const plugin of pendingTaskPlugins) {
      if (!map.has(plugin.id)) {
        map.set(plugin.id, {
          id: plugin.id,
          name: plugin.name,
          surfaces: [],
          pendingKeys: plugin.pendingKeys,
        });
      } else {
        map.set(plugin.id, {
          ...map.get(plugin.id),
          pendingKeys: plugin.pendingKeys,
        });
      }
    }

    return Array.from(map.values()).sort((left, right) => left.name.localeCompare(right.name));
  }, [ expectedPlugins, pendingTaskPlugins, pluginTasks ]);

  const isPluginSettled = useCallback((plugin) => {
    const surfacesSettled = (plugin?.surfaces || []).every((surface) => {
      const status = surfaceStatuses[surface];
      return status === "success" || status === "error";
    });

    const tasksSettled = !(plugin?.pendingKeys?.length);

    return surfacesSettled && tasksSettled;
  }, [ surfaceStatuses ]);

  const loadedPlugins = trackedPlugins.filter(isPluginSettled).length;
  const totalPlugins = trackedPlugins.length;
  const currentPlugin = trackedPlugins.find((plugin) => !isPluginSettled(plugin)) || null;
  const isLoading = activeSurfaces.length > 0
    && (pluginIndexQuery.isFetching || totalPlugins > loadedPlugins || pendingTaskPlugins.length > 0);

  useEffect(() => {
    if (!isLoading && totalPlugins > 0 && loadedPlugins === totalPlugins) {
      setShowCompletedToast(true);
      clearTimeout(completionTimeoutRef.current);
      completionTimeoutRef.current = setTimeout(() => setShowCompletedToast(false), 1400);
      return;
    }

    if (isLoading) {
      setShowCompletedToast(false);
      clearTimeout(completionTimeoutRef.current);
    }
  }, [ isLoading, loadedPlugins, totalPlugins ]);

  useEffect(() => () => clearTimeout(completionTimeoutRef.current), []);

  const registerSurface = useCallback((surface) => {
    setSurfaceCounts((previous) => ({
      ...previous,
      [surface]: (previous[surface] || 0) + 1,
    }));

    setSurfaceStatuses((previous) => ({
      ...previous,
      [surface]: previous[surface] === "success" ? "success" : "loading",
    }));
  }, []);

  const unregisterSurface = useCallback((surface) => {
    setSurfaceCounts((previous) => {
      const nextCount = (previous[surface] || 0) - 1;

      if (nextCount > 0) {
        return { ...previous, [surface]: nextCount };
      }

      const next = { ...previous };
      delete next[surface];

      return next;
    });

    setSurfaceStatuses((previous) => {
      const next = { ...previous };
      delete next[surface];

      return next;
    });
  }, []);

  const reportSurfaceStatus = useCallback((surface, status) => {
    setSurfaceStatuses((previous) => {
      if (previous[surface] === status) {
        return previous;
      }

      return {
        ...previous,
        [surface]: status,
      };
    });
  }, []);

  const setPluginTaskState = useCallback(({ pluginId, pluginName, taskKey, status }) => {
    setPluginTasks((previous) => {
      const current = previous[pluginId] || {
        id: pluginId,
        name: pluginName || pluginId,
        pendingKeys: [],
      };

      const pendingKeys = new Set(current.pendingKeys || []);

      if (status === "loading") {
        pendingKeys.add(taskKey);
      } else {
        pendingKeys.delete(taskKey);
      }

      if (pendingKeys.size === 0) {
        const next = { ...previous };
        delete next[pluginId];
        return next;
      }

      return {
        ...previous,
        [pluginId]: {
          ...current,
          name: pluginName || current.name,
          pendingKeys: Array.from(pendingKeys),
        },
      };
    });
  }, []);

  const detailMessage = isLoading
    ? currentPlugin?.name
      ? `__PLUGIN_LOADING_CURRENT__${currentPlugin.name}`
      : pluginIndexQuery.isFetching
        ? "__PLUGIN_LOADING_DISCOVERING__"
        : "__PLUGIN_LOADING_PREPARING__"
    : totalPlugins > 0
      ? `__PLUGIN_LOADING_COMPLETE__${totalPlugins}`
      : "";

  const resolvedDetailMessage = detailMessage.startsWith("__PLUGIN_LOADING_CURRENT__")
    ? t("plugins.loadingCurrentDetail", { name: detailMessage.replace("__PLUGIN_LOADING_CURRENT__", "") })
    : detailMessage === "__PLUGIN_LOADING_DISCOVERING__"
      ? t("plugins.loadingDiscovering")
      : detailMessage === "__PLUGIN_LOADING_PREPARING__"
        ? t("plugins.loadingPreparingView")
        : detailMessage.startsWith("__PLUGIN_LOADING_COMPLETE__")
          ? t("plugins.loadingCompleteDetail", { total: detailMessage.replace("__PLUGIN_LOADING_COMPLETE__", "") })
          : detailMessage;

  const value = useMemo(() => ({
    registerSurface,
    unregisterSurface,
    reportSurfaceStatus,
    setPluginTaskState,
  }), [ registerSurface, unregisterSurface, reportSurfaceStatus, setPluginTaskState ]);

  return (
    <PluginLoadingContext.Provider value={value}>
      {children}
      <PluginLoadingToast
        visible={isLoading || showCompletedToast}
        isComplete={!isLoading}
        totalPlugins={totalPlugins}
        loadedPlugins={loadedPlugins}
        currentPluginName={currentPlugin?.name || null}
        detailMessage={resolvedDetailMessage}
      />
    </PluginLoadingContext.Provider>
  );
}

export const usePluginLoading = () => useContext(PluginLoadingContext);
