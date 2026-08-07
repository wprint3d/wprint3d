import { Component, useEffect, useMemo, useRef, useState } from "react";
import { AccessibilityInfo, Animated, Platform, ScrollView, View } from "react-native";
import { Button, Card, Chip, Dialog, Divider, Icon, List, Portal, ProgressBar, Switch, Text, TextInput, TouchableRipple, useTheme } from "react-native-paper";
import { WebView } from "react-native-webview";
import Svg, { Circle } from "react-native-svg";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useSnackbar } from "react-native-paper-snackbar-stack";
import API from "../includes/API";
import { usePluginLoading } from "./PluginLoadingProvider";
import useActivePrinterId from "../hooks/useActivePrinterId";
import { useLocalization } from "../includes/LocalizationProvider";

const clampPercentage = (value) => {
  if (!Number.isFinite(value)) { return 0; }
  return Math.min(100, Math.max(0, value));
};

const readValueByPath = (object, path) => {
  if (!path) { return undefined; }

  return path.split(".").reduce((value, key) => (
    value && typeof value === "object" ? value[key] : undefined
  ), object);
};

const resolveTemplateString = (value, props) => {
  if (typeof value !== "string") {
    return value;
  }

  return value.replace(/\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}/g, (_match, key) => {
    const resolved = readValueByPath(props, key);

    return resolved === undefined || resolved === null ? "" : String(resolved);
  });
};

const resolveRemoteComponentValue = (value, props) => {
  if (Array.isArray(value)) {
    return value.map((item) => resolveRemoteComponentValue(item, props));
  }

  if (value && typeof value === "object") {
    if (Object.prototype.hasOwnProperty.call(value, "$prop")) {
      const resolved = readValueByPath(props, value.$prop);

      return resolved === undefined ? value.default : resolved;
    }

    return Object.fromEntries(
      Object.entries(value).map(([key, nestedValue]) => [key, resolveRemoteComponentValue(nestedValue, props)])
    );
  }

  return resolveTemplateString(value, props);
};

const buildEmbeddedUiUrl = (rawUrl, extension, colors, effectiveLanguage) => {
  if (!rawUrl) { return rawUrl; }

  let resolvedUrl = null;

  try {
    resolvedUrl = new URL(rawUrl);
  } catch (_error) {
    if (typeof window !== "undefined" && window?.location?.origin) {
      resolvedUrl = new URL(rawUrl, window.location.origin);
    }
  }

  if (!resolvedUrl) { return rawUrl; }

  resolvedUrl.searchParams.set("pluginId", extension.pluginId);
  resolvedUrl.searchParams.set("pluginName", extension.pluginName || "");
  resolvedUrl.searchParams.set("extensionId", extension.id || "");
  resolvedUrl.searchParams.set("extensionMode", extension.mode || "declarative");
  resolvedUrl.searchParams.set("actionId", extension.dataActionId || extension.actionId || "");
  resolvedUrl.searchParams.set("pluginApiBase", `/backend/api/plugins/${extension.pluginId}`);
  resolvedUrl.searchParams.set("pluginSettingsBase", `/backend/api/plugins/${extension.pluginId}/settings`);
  resolvedUrl.searchParams.set("pluginStateBase", `/backend/api/plugins/${extension.pluginId}/state`);
  resolvedUrl.searchParams.set("octoPrintCompatUrl", "/backend/api/plugins/sdk/octoprint-compat.js");
  resolvedUrl.searchParams.set("currentPrinterId", extension.currentPrinterId || "");
  resolvedUrl.searchParams.set("components", JSON.stringify(extension.pluginManifest?.components || []));
  resolvedUrl.searchParams.set("componentIds", JSON.stringify(extension.components || []));
  resolvedUrl.searchParams.set("locale", (effectiveLanguage || "en").replace("_", "-"));
  resolvedUrl.searchParams.set("fallbackLocale", "en");
  resolvedUrl.searchParams.set("theme", JSON.stringify({
    primary: colors.primary,
    secondary: colors.secondary,
    tertiary: colors.tertiary,
    surface: colors.surface,
    surfaceVariant: colors.surfaceVariant,
    background: colors.background,
    onSurface: colors.onSurface,
    onSurfaceVariant: colors.onSurfaceVariant,
    outline: colors.outline,
    outlineVariant: colors.outlineVariant,
    elevation: colors.elevation || {},
    error: colors.error,
    onError: colors.onError,
  }));

  return resolvedUrl.toString();
};

const normalizeHostComponentName = (componentName) => {
  if (!componentName || typeof componentName !== "string") {
    return componentName;
  }

  return componentName.startsWith("host.") ? componentName.slice(5) : componentName;
};

const ProgressMetric = ({ label, percentage, accentColor }) => {
  const { colors } = useTheme();
  const hasValue = Number.isFinite(Number(percentage));
  const clamped = hasValue ? clampPercentage(Number(percentage)) : 0;
  const textColor = colors.onSurface;
  const mutedColor = colors.onSurfaceVariant || colors.onSurfaceDisabled || colors.outline;
  const trackColor = colors.elevation?.level2 || colors.surfaceVariant || colors.backdrop || "#c6c6c6";

  return (
    <View
      style={{
        width: 88,
      }}
    >
      <View style={{ flexDirection: "row", alignItems: "center", justifyContent: "space-between", marginBottom: 2 }}>
        <Text
          variant="bodySmall"
          style={{
            color: mutedColor,
            fontWeight: "700",
            fontSize: 8,
            lineHeight: 8,
            letterSpacing: 0.2,
            textTransform: "uppercase",
          }}
        >
          {label}
        </Text>
        <Text variant="bodySmall" style={{ color: textColor, fontSize: 8, fontWeight: "700", lineHeight: 8 }}>
          {hasValue ? `${Math.round(clamped)}%` : "--"}
        </Text>
      </View>
      <ProgressBar
        progress={clamped / 100}
        color={accentColor}
        style={{
          height: 5,
          borderRadius: 999,
          backgroundColor: trackColor,
        }}
      />
    </View>
  );
};

const ProgressClusterNode = ({ extension, node, printerId = null }) => {
  const { colors } = useTheme();
  const { t } = useLocalization();
  const { setPluginTaskState } = usePluginLoading();
  const tones = [
    colors.primary,
    colors.secondary || colors.primary,
    colors.tertiary || colors.primary,
  ];
  const queryKeyPayload = JSON.stringify(node.dataActionPayload || {});
  const taskKey = `data:${extension.id}:${node.dataActionId}:${printerId || "global"}:${queryKeyPayload}`;
  const hasSettledInitialLoadRef = useRef(false);

  const dataQuery = useQuery({
    queryKey: ["pluginExtensionData", extension.pluginId, extension.id, printerId, node.dataActionId, queryKeyPayload],
    queryFn: async () => {
      const response = await API.post(`/plugins/${extension.pluginId}/actions/${node.dataActionId}`, {
        payload: node.dataActionPayload || {},
        printerId,
      });

      return response?.data?.data || response?.data || {};
    },
    enabled: !!node.dataActionId,
    refetchInterval: node.pollIntervalMs || 10000,
    retry: false,
    refetchOnWindowFocus: false,
    staleTime: node.pollIntervalMs || 10000,
  });

  useEffect(() => {
    if (!node.dataActionId || hasSettledInitialLoadRef.current) {
      return undefined;
    }

    if (dataQuery.isPending || dataQuery.isFetching) {
      setPluginTaskState({
        pluginId: extension.pluginId,
        pluginName: extension.pluginName,
        taskKey,
        status: "loading",
      });
    }

    if (dataQuery.isSuccess || dataQuery.isError) {
      hasSettledInitialLoadRef.current = true;
      setPluginTaskState({
        pluginId: extension.pluginId,
        pluginName: extension.pluginName,
        taskKey,
        status: "complete",
      });
    }

    return () => {
      if (!hasSettledInitialLoadRef.current) {
        setPluginTaskState({
          pluginId: extension.pluginId,
          pluginName: extension.pluginName,
          taskKey,
          status: "complete",
        });
      }
    };
  }, [
    dataQuery.isError,
    dataQuery.isFetching,
    dataQuery.isPending,
    dataQuery.isSuccess,
    extension.pluginId,
    extension.pluginName,
    node.dataActionId,
    setPluginTaskState,
    taskKey,
  ]);

  const metrics = dataQuery.data || {};

  return (
    <View
      style={{
        gap: 5,
        paddingHorizontal: 2,
      }}
    >
      {(node.items || []).map((item, index) => {
        const resolvedValue = readValueByPath(metrics, item.valueKey);

        return (
          <ProgressMetric
            key={item.id || item.label || index}
            label={item.label || item.id || t("plugins.metric")}
            percentage={resolvedValue}
            accentColor={tones[index % tones.length]}
          />
        );
      })}
    </View>
  );
};

const NavbarStripItem = ({ item }) => {
  const { colors } = useTheme();

  return (
    <View
      style={{
        flexDirection: "row",
        alignItems: "center",
        gap: 4,
        flexShrink: 0,
      }}
    >
      {item.icon ? <Icon source={item.icon} size={15} color={item.color || colors.onSurface} /> : null}
      <Text
        variant="bodyMedium"
        numberOfLines={1}
        style={{
          color: item.color || colors.onSurface,
          fontWeight: item.emphasis ? "700" : "600",
          fontSize: 12,
          lineHeight: 14,
        }}
      >
        {item.text || item.label || String(item.value || "")}
      </Text>
    </View>
  );
};

const MobileGaugeStripItem = ({ item, compact = false }) => {
  const { colors } = useTheme();
  const [ showDetails, setShowDetails ] = useState(false);
  const [ reduceMotion, setReduceMotion ] = useState(false);
  const opacity = useRef(new Animated.Value(1)).current;
  const detailsTimeoutRef = useRef(null);
  const size = compact ? 28 : 36;
  const strokeWidth = compact ? 2.5 : 3;
  const radius = (size - strokeWidth) / 2;
  const circumference = 2 * Math.PI * radius;
  const value = Number(item.value);
  const min = Number.isFinite(Number(item.min)) ? Number(item.min) : 0;
  const max = Number.isFinite(Number(item.max)) ? Number(item.max) : 100;
  const hasRange = Number.isFinite(value) && max > min;
  const progress = hasRange ? clampPercentage(((value - min) / (max - min)) * 100) / 100 : 0;
  const label = item.label || item.id || "";
  const displayValue = item.displayValue || item.text || (Number.isFinite(value) ? String(value) : "--");
  const targetValue = Number(item.targetValue);
  const hasTarget = item.targetValue !== null
    && item.targetValue !== undefined
    && Number.isFinite(targetValue);
  const formatCompactValue = (candidate) => {
    if (!Number.isFinite(candidate)) { return "--"; }

    return `${Number.isInteger(candidate) ? candidate : candidate.toFixed(1)}°`;
  };
  const detailsText = Number.isFinite(value)
    ? (
        hasTarget
          ? `${formatCompactValue(value)}/${formatCompactValue(targetValue)}`
          : formatCompactValue(value)
      )
    : displayValue;

  useEffect(() => {
    let mounted = true;

    AccessibilityInfo.isReduceMotionEnabled().then((enabled) => {
      if (mounted) {
        setReduceMotion(enabled);
      }
    });

    return () => {
      mounted = false;
      clearTimeout(detailsTimeoutRef.current);
      opacity.stopAnimation();
    };
  }, [opacity]);

  const returnToGauge = () => {
    if (reduceMotion) {
      setShowDetails(false);
      return;
    }

    Animated.timing(opacity, {
      toValue: 0,
      duration: 250,
      useNativeDriver: true,
    }).start(({ finished }) => {
      if (!finished) { return; }

      setShowDetails(false);
      opacity.setValue(0);
      Animated.timing(opacity, {
        toValue: 1,
        duration: 200,
        useNativeDriver: true,
      }).start();
    });
  };

  const showTemperatureDetails = () => {
    clearTimeout(detailsTimeoutRef.current);
    opacity.stopAnimation();
    opacity.setValue(1);
    setShowDetails(true);
    detailsTimeoutRef.current = setTimeout(returnToGauge, 5000);
  };

  const gauge = (
    <View style={{ width: size, height: size, alignItems: "center", justifyContent: "center" }}>
      <Svg width={size} height={size} viewBox={`0 0 ${size} ${size}`}>
        <Circle
          cx={size / 2}
          cy={size / 2}
          r={radius}
          fill="none"
          stroke={colors.outlineVariant}
          strokeWidth={strokeWidth}
        />
        <Circle
          cx={size / 2}
          cy={size / 2}
          r={radius}
          fill="none"
          stroke={item.color || colors.primary}
          strokeWidth={strokeWidth}
          strokeLinecap="round"
          strokeDasharray={`${circumference} ${circumference}`}
          strokeDashoffset={circumference * (1 - progress)}
          rotation="-90"
          origin={`${size / 2}, ${size / 2}`}
        />
      </Svg>
      <View
        pointerEvents="none"
        style={{
          position: "absolute",
          top: 0,
          right: 0,
          bottom: 0,
          left: 0,
          alignItems: "center",
          justifyContent: "center",
        }}
      >
        <Icon source={item.icon || "gauge"} size={compact ? 13 : 15} color={item.color || colors.onSurface} />
      </View>
    </View>
  );

  if (compact) {
    return (
      <TouchableRipple
        onPress={showTemperatureDetails}
        accessibilityRole="button"
        accessibilityLabel={`${label}: ${detailsText}`}
        accessibilityState={{ expanded: showDetails }}
        borderless
        hitSlop={8}
        style={{ borderRadius: 999 }}
      >
        <Animated.View
          style={{
            width: showDetails ? 72 : 30,
            height: 32,
            opacity,
            alignItems: "center",
            justifyContent: "center",
          }}
        >
          {showDetails ? (
            <Text
              variant="labelSmall"
              numberOfLines={1}
              style={{ color: colors.onSurface, fontWeight: "700", fontSize: 9, lineHeight: 11 }}
            >
              {detailsText}
            </Text>
          ) : gauge}
        </Animated.View>
      </TouchableRipple>
    );
  }

  return (
    <View
      accessible
      accessibilityLabel={`${label}: ${displayValue}`}
      style={{ width: compact ? 30 : 68, alignItems: "center", gap: 1 }}
    >
      {gauge}
      {!compact && (
        <Text
          variant="labelSmall"
          numberOfLines={1}
          style={{ color: colors.onSurface, fontWeight: "700", fontSize: 8, lineHeight: 10 }}
        >
          {`${label} ${displayValue}`}
        </Text>
      )}
    </View>
  );
};

const DataStripNode = ({ extension, node, printerId = null, navbarLayout = "inline" }) => {
  const { colors } = useTheme();
  const { t } = useLocalization();
  const { setPluginTaskState } = usePluginLoading();
  const queryKeyPayload = JSON.stringify(node.dataActionPayload || {});
  const taskKey = `data-strip:${extension.id}:${node.dataActionId}:${printerId || "global"}:${queryKeyPayload}`;
  const hasSettledInitialLoadRef = useRef(false);

  const dataQuery = useQuery({
    queryKey: ["pluginExtensionStripData", extension.pluginId, extension.id, printerId, node.dataActionId, queryKeyPayload],
    queryFn: async () => {
      const response = await API.post(`/plugins/${extension.pluginId}/actions/${node.dataActionId}`, {
        payload: node.dataActionPayload || {},
        printerId,
      });

      return response?.data?.data || response?.data || {};
    },
    enabled: !!node.dataActionId,
    refetchInterval: node.pollIntervalMs || 10000,
    retry: false,
    refetchOnWindowFocus: false,
    staleTime: node.pollIntervalMs || 10000,
  });

  useEffect(() => {
    if (!node.dataActionId || hasSettledInitialLoadRef.current) {
      return undefined;
    }

    if (dataQuery.isPending || dataQuery.isFetching) {
      setPluginTaskState({
        pluginId: extension.pluginId,
        pluginName: extension.pluginName,
        taskKey,
        status: "loading",
      });
    }

    if (dataQuery.isSuccess || dataQuery.isError) {
      hasSettledInitialLoadRef.current = true;
      setPluginTaskState({
        pluginId: extension.pluginId,
        pluginName: extension.pluginName,
        taskKey,
        status: "complete",
      });
    }

    return () => {
      if (!hasSettledInitialLoadRef.current) {
        setPluginTaskState({
          pluginId: extension.pluginId,
          pluginName: extension.pluginName,
          taskKey,
          status: "complete",
        });
      }
    };
  }, [
    dataQuery.isError,
    dataQuery.isFetching,
    dataQuery.isPending,
    dataQuery.isSuccess,
    extension.pluginId,
    extension.pluginName,
    node.dataActionId,
    setPluginTaskState,
    taskKey,
  ]);

  const data = dataQuery.data || {};
  const items = readValueByPath(data, node.itemsPath || "items");
  const resolvedItems = Array.isArray(items) ? items : [];

  if (!resolvedItems.length) {
    return null;
  }

  if (extension.surface === "navbar_widget") {
    const stripItems = resolvedItems.map((item, index) => (
      <NavbarStripItem
        key={item.id || item.label || item.text || index}
        item={item}
      />
    ));

    if (navbarLayout === "gauges") {
      return (
        <View
          style={{
            flexDirection: "row",
            alignItems: "center",
            justifyContent: "flex-end",
            gap: node.gap ?? 4,
          }}
        >
          {resolvedItems.map((item, index) => (
            <MobileGaugeStripItem
              key={item.id || item.label || item.text || index}
              item={item}
              compact
            />
          ))}
        </View>
      );
    }

    if (navbarLayout === "card") {
      return (
        <View
          style={{
            marginHorizontal: 8,
            marginTop: 4,
            marginBottom: 8,
            paddingHorizontal: 12,
            paddingVertical: 10,
            borderWidth: 1,
            borderRadius: 8,
            borderColor: colors.outlineVariant,
            backgroundColor: colors.elevation?.level1 || colors.surface,
            flexDirection: "row",
            flexWrap: "wrap",
            alignItems: "center",
            justifyContent: "flex-start",
            gap: node.gap ?? 12,
          }}
        >
          {stripItems}
        </View>
      );
    }

    return (
      <ScrollView
        horizontal
        showsHorizontalScrollIndicator={false}
        contentContainerStyle={{
          flexDirection: "row",
          alignItems: "center",
          justifyContent: "flex-end",
          gap: node.gap ?? 12,
          paddingLeft: 8,
        }}
        style={{
          flexGrow: 0,
          flexShrink: 1,
          maxWidth: "100%",
        }}
      >
        {stripItems}
      </ScrollView>
    );
  }

  return (
    <View
      key={`${extension.pluginId}-${extension.id}-strip`}
      style={{
        flexDirection: "row",
        flexWrap: "wrap",
        alignItems: "center",
        gap: node.gap ?? 6,
        maxWidth: node.maxWidth || 440,
      }}
    >
      {resolvedItems.map((item, index) => (
        <Chip
          key={item.id || item.label || item.text || index}
          compact
          mode={item.mode || "flat"}
          icon={item.icon || undefined}
          style={{
            backgroundColor: item.backgroundColor || colors.elevation?.level2 || colors.surfaceVariant,
          }}
          textStyle={{
            color: item.color || colors.onSurface,
            fontWeight: item.emphasis ? "700" : "500",
          }}
        >
          {item.text || item.label || String(item.value || "")}
        </Chip>
      ))}
    </View>
  );
};

const EmbeddedBrowserFrame = ({ uri, minHeight = 360, fitContentHeight = false }) => {
  const iframeRef = useRef(null);
  const [measuredHeight, setMeasuredHeight] = useState(minHeight);

  useEffect(() => {
    if (Platform.OS !== "web" || !fitContentHeight) {
      return undefined;
    }

    const iframe = iframeRef.current;

    if (!iframe) {
      return undefined;
    }

    let resizeObserver = null;
    let pollInterval = null;
    let animationFrame = null;

    const updateHeight = () => {
      try {
        const doc = iframe.contentDocument;

        if (!doc) {
          return;
        }

        const candidates = [
          doc.documentElement,
          doc.body,
          doc.querySelector("main"),
        ].filter(Boolean);

        const nextHeight = Math.max(
          minHeight,
          ...candidates.map((element) => Math.ceil(Math.max(
            element.scrollHeight || 0,
            element.offsetHeight || 0,
            element.getBoundingClientRect?.().height || 0,
          )))
        );

        setMeasuredHeight((current) => (Math.abs(current - nextHeight) > 1 ? nextHeight : current));
      } catch (_error) {
        // Ignore cross-document or transient access failures and keep the last measured height.
      }
    };

    const scheduleUpdate = () => {
      if (animationFrame) {
        window.cancelAnimationFrame(animationFrame);
      }

      animationFrame = window.requestAnimationFrame(updateHeight);
    };

    const attachObservers = () => {
      scheduleUpdate();

      try {
        const doc = iframe.contentDocument;
        const observedElements = [
          doc?.documentElement,
          doc?.body,
          doc?.querySelector("main"),
        ].filter(Boolean);

        if (typeof ResizeObserver !== "undefined" && observedElements.length) {
          resizeObserver = new ResizeObserver(() => scheduleUpdate());
          observedElements.forEach((element) => resizeObserver.observe(element));
        }
      } catch (_error) {
        // Ignore observer attachment failures and fall back to polling.
      }

      pollInterval = window.setInterval(updateHeight, 1000);
    };

    iframe.addEventListener("load", attachObservers);

    if (iframe.contentDocument?.readyState === "complete") {
      attachObservers();
    }

    return () => {
      iframe.removeEventListener("load", attachObservers);

      if (resizeObserver) {
        resizeObserver.disconnect();
      }

      if (pollInterval) {
        window.clearInterval(pollInterval);
      }

      if (animationFrame) {
        window.cancelAnimationFrame(animationFrame);
      }
    };
  }, [fitContentHeight, minHeight, uri]);

  if (Platform.OS === "web") {
    return (
      <iframe
        ref={iframeRef}
        src={uri}
        title={uri}
        style={{
          width: "100%",
          height: fitContentHeight ? measuredHeight : undefined,
          minHeight,
          border: "0",
          display: "block",
          background: "transparent",
        }}
      />
    );
  }

  return <WebView source={{ uri }} />;
};

class PluginRenderBoundary extends Component {
  constructor(props) {
    super(props);
    this.state = { error: null };
  }

  static getDerivedStateFromError(error) {
    return { error };
  }

  componentDidCatch(error, info) {
    this.props.onError?.(error, info);
  }

  componentDidUpdate(previousProps) {
    if (previousProps.boundaryKey !== this.props.boundaryKey && this.state.error) {
      this.setState({ error: null });
    }
  }

  reset = () => {
    this.setState({ error: null });
    this.props.onReset?.();
  };

  render() {
    if (this.state.error) {
      return this.props.renderFallback({
        error: this.state.error,
        retry: this.reset,
      });
    }

    return this.props.children;
  }
}

const PluginRenderFallback = ({ extension, error, onRetry }) => {
  const { colors } = useTheme();
  const { t } = useLocalization();
  const message = error?.message || t("plugins.unknownPluginRenderError");

  if (extension.surface === "navbar_widget") {
    return (
      <View
        style={{
          maxWidth: 240,
          paddingHorizontal: 8,
          paddingVertical: 6,
          borderRadius: 10,
          backgroundColor: colors.errorContainer,
          gap: 4,
        }}
      >
        <Text variant="labelSmall" style={{ color: colors.onErrorContainer, fontWeight: "700" }}>
          {extension.pluginName || extension.pluginId}
        </Text>
        <Text
          variant="bodySmall"
          numberOfLines={2}
          style={{ color: colors.onErrorContainer }}
        >
          {t("plugins.pluginUiFailedToRender")}
        </Text>
        <Button compact mode="text" textColor={colors.onErrorContainer} onPress={onRetry}>
          {t("plugins.retry")}
        </Button>
      </View>
    );
  }

  return (
    <Card style={{ marginBottom: 12, backgroundColor: colors.errorContainer }}>
      <Card.Title
        title={t("plugins.pluginFailedToRenderTitle", { name: extension.pluginName || extension.pluginId })}
        subtitle={extension.title || extension.id || extension.surface || t("plugins.pluginSurface")}
      />
      <Card.Content>
        <Text style={{ color: colors.onErrorContainer, marginBottom: 8 }}>
          {t("plugins.pluginSurfaceIsolated")}
        </Text>
        <Text selectable style={{ color: colors.onErrorContainer }}>
          {message}
        </Text>
      </Card.Content>
      <Card.Actions>
        <Button onPress={onRetry}>{t("plugins.retry")}</Button>
      </Card.Actions>
    </Card>
  );
};

const PluginHostRendererContent = ({ extension, modalExtensions = [], printerId = null, navbarLayout = "inline" }) => {
  const { colors } = useTheme();
  const { effectiveLanguage, t } = useLocalization();
  const { enqueueSnackbar } = useSnackbar();
  const queryClient = useQueryClient();
  const activePrinterIdQuery = useActivePrinterId();
  const effectivePrinterId = printerId || activePrinterIdQuery.data?.data || activePrinterIdQuery.data || null;

  const [ openedModalId, setOpenedModalId ] = useState(null);
  const [ formState, setFormState ] = useState({});

  const modalExtensionMap = useMemo(() => {
    return Object.fromEntries((modalExtensions || []).map(item => [item.id, item]));
  }, [modalExtensions]);

  const manifestComponentMap = useMemo(() => {
    return Object.fromEntries(
      ((extension.pluginManifest?.components) || []).map((component) => [component.id, component])
    );
  }, [extension.pluginManifest]);

  const actionMutation = useMutation({
    mutationFn: ({ actionId, payload }) => API.post(`/plugins/${extension.pluginId}/actions/${actionId}`, {
      payload,
      printerId: effectivePrinterId,
    }),
    onSuccess: (response) => {
      queryClient.invalidateQueries({ queryKey: ["pluginExtensions"] });

      const message = response?.data?.data?.message || response?.data?.message;

      if (message) {
        enqueueSnackbar({
          message,
          variant: "info",
          action: { label: t("notifications.gotIt") },
        });
      }
    },
    onError: (error) => {
      enqueueSnackbar({
        message: error?.response?.data?.message || error.message,
        variant: "error",
        action: { label: t("notifications.gotIt") },
      });
    }
  });

  const runAction = (actionId, payload = {}) => {
    actionMutation.mutate({ actionId, payload });
  };

  const openExtensionOrRunAction = (node, payload = node.payload || {}) => {
    if (node.openExtensionId) {
      setOpenedModalId(node.openExtensionId);
      return;
    }

    if (node.actionId) {
      runAction(node.actionId, payload);
    }
  };

  const renderNode = (node, keyPrefix = "root", componentStack = []) => {
    if (!node) { return null; }

    const key = `${keyPrefix}-${node.id || node.component || "node"}`;
    const componentName = normalizeHostComponentName(node.component);
    const childNodes = Array.isArray(node.children) ? node.children : [];
    const cardBackground = node.backgroundColor || colors.elevation.level1;
    const inputStateKey = node.stateKey || node.id || key;

    switch (componentName) {
      case "section":
      case "card":
        return (
          <Card
            key={key}
            style={{ marginBottom: node.marginBottom ?? 12, backgroundColor: cardBackground }}
          >
            {(node.title || node.subtitle) && (
              <Card.Title title={node.title} subtitle={node.subtitle} />
            )}
            <Card.Content>
              {childNodes.map((child, index) => renderNode(child, `${key}-${index}`, componentStack))}
            </Card.Content>
          </Card>
        );
      case "surface":
        return (
          <View
            key={key}
            style={{
              marginBottom: node.marginBottom ?? 12,
              padding: node.padding ?? 12,
              borderRadius: node.borderRadius ?? 12,
              backgroundColor: node.backgroundColor || colors.elevation.level1,
              gap: node.gap ?? 8,
            }}
          >
            {childNodes.map((child, index) => renderNode(child, `${key}-${index}`, componentStack))}
          </View>
        );
      case "stack":
      case "column":
        return (
          <View
            key={key}
            style={{
              gap: node.gap ?? 8,
              marginBottom: node.marginBottom ?? 8,
              alignItems: node.alignItems || "stretch",
            }}
          >
            {childNodes.map((child, index) => renderNode(child, `${key}-${index}`, componentStack))}
          </View>
        );
      case "row":
        return (
          <View
            key={key}
            style={{
              flexDirection: "row",
              flexWrap: node.wrap ? "wrap" : "nowrap",
              alignItems: node.alignItems || "center",
              justifyContent: node.justifyContent || "flex-start",
              gap: node.gap ?? 8,
              marginBottom: node.marginBottom ?? 8,
            }}
          >
            {childNodes.map((child, index) => renderNode(child, `${key}-${index}`, componentStack))}
          </View>
        );
      case "scroll":
        return (
          <ScrollView
            key={key}
            style={{
              maxHeight: node.maxHeight ?? 320,
              marginBottom: node.marginBottom ?? 12,
            }}
            contentContainerStyle={{
              gap: node.gap ?? 8,
              paddingRight: node.paddingRight ?? 4,
            }}
          >
            {childNodes.map((child, index) => renderNode(child, `${key}-${index}`, componentStack))}
          </ScrollView>
        );
      case "text":
        return (
          <Text
            key={key}
            variant={node.variant || "bodyMedium"}
            style={{
              marginBottom: node.marginBottom ?? 8,
              color: node.color || colors.onSurface,
              textAlign: node.align || "left",
            }}
          >
            {node.text}
          </Text>
        );
      case "heading":
        return (
          <Text
            key={key}
            variant={node.variant || "titleLarge"}
            style={{
              marginBottom: node.marginBottom ?? 8,
              color: node.color || colors.onSurface,
              fontWeight: node.weight || "700",
            }}
          >
            {node.text || node.title}
          </Text>
        );
      case "caption":
        return (
          <Text
            key={key}
            variant={node.variant || "bodySmall"}
            style={{
              marginBottom: node.marginBottom ?? 8,
              color: node.color || colors.onSurfaceVariant,
            }}
          >
            {node.text}
          </Text>
        );
      case "divider":
        return <Divider key={key} style={{ marginVertical: 8 }} />;
      case "list":
        return (
          <View key={key}>
            {(node.items || []).map((item, index) => (
              <List.Item
                key={`${key}-${index}`}
                title={item.title || item}
                description={item.description}
                left={(props) => <List.Icon {...props} icon={item.icon || "puzzle"} />}
              />
            ))}
          </View>
        );
      case "chip_group":
        return (
          <View
            key={key}
            style={{
              flexDirection: "row",
              flexWrap: "wrap",
              gap: node.gap ?? 8,
              marginBottom: node.marginBottom ?? 8,
            }}
          >
            {(node.items || []).map((item, index) => (
              <Chip
                key={`${key}-${item.id || item.label || item.text || index}`}
                compact={item.compact ?? true}
                mode={item.mode || "flat"}
                icon={item.icon || undefined}
                style={{
                  backgroundColor: item.backgroundColor || colors.elevation?.level2 || colors.surfaceVariant,
                }}
                textStyle={{
                  color: item.color || colors.onSurface,
                  fontWeight: item.emphasis ? "700" : "500",
                }}
                onPress={item.actionId || item.openExtensionId ? () => openExtensionOrRunAction(item, item.payload || {}) : undefined}
              >
                {item.text || item.label || item.value || ""}
              </Chip>
            ))}
          </View>
        );
      case "badge":
        return (
          <Chip
            key={key}
            compact
            mode={node.mode || "flat"}
            icon={node.icon || undefined}
            style={{
              alignSelf: node.alignSelf || "flex-start",
              marginBottom: node.marginBottom ?? 8,
              backgroundColor: node.backgroundColor || colors.elevation?.level2 || colors.surfaceVariant,
            }}
            textStyle={{
              color: node.color || colors.onSurface,
            }}
            onPress={node.actionId || node.openExtensionId ? () => openExtensionOrRunAction(node, node.payload || {}) : undefined}
          >
            {node.text || node.label || ""}
          </Chip>
        );
      case "key_value":
        return (
          <View
            key={key}
            style={{
              flexDirection: "row",
              justifyContent: "space-between",
              marginBottom: 8,
              gap: 12,
            }}
          >
            <Text variant="titleSmall">{node.label}</Text>
            <Text>{node.value}</Text>
          </View>
        );
      case "button":
        return (
          <Button
            key={key}
            mode={node.mode || "contained-tonal"}
            icon={node.icon || undefined}
            style={{ marginBottom: node.marginBottom ?? 8 }}
            onPress={() => openExtensionOrRunAction(node, node.payload || {})}
          >
            {node.label || t("plugins.run")}
          </Button>
        );
      case "input":
        return (
          <TextInput
            key={key}
            mode={node.mode || "outlined"}
            label={node.label}
            value={formState[inputStateKey] ?? node.defaultValue ?? ""}
            onChangeText={(value) => setFormState((previous) => ({ ...previous, [inputStateKey]: value }))}
            multiline={!!node.multiline}
            disabled={!!node.disabled}
            style={{ marginBottom: node.marginBottom ?? 8 }}
          />
        );
      case "switch":
        return (
          <View
            key={key}
            style={{
              flexDirection: "row",
              alignItems: "center",
              justifyContent: "space-between",
              gap: 12,
              marginBottom: node.marginBottom ?? 8,
            }}
          >
            <View style={{ flex: 1 }}>
              <Text variant="titleSmall">{node.label}</Text>
              {node.description ? (
                <Text variant="bodySmall" style={{ color: colors.onSurfaceVariant, marginTop: 2 }}>
                  {node.description}
                </Text>
              ) : null}
            </View>
            <Switch
              value={!!(formState[inputStateKey] ?? node.defaultValue ?? false)}
              onValueChange={(value) => setFormState((previous) => ({ ...previous, [inputStateKey]: value }))}
              disabled={!!node.disabled}
            />
          </View>
        );
      case "form":
        return (
          <Card key={key} style={{ marginBottom: 12, backgroundColor: colors.elevation.level1 }}>
            {(node.title || node.subtitle) && <Card.Title title={node.title} subtitle={node.subtitle} />}
            <Card.Content>
              {(node.fields || []).map((field) => {
                const fieldKey = `${node.id || key}.${field.id}`;

                if (field.type === "boolean" || field.component === "switch") {
                  return (
                    <View
                      key={fieldKey}
                      style={{
                        flexDirection: "row",
                        alignItems: "center",
                        justifyContent: "space-between",
                        gap: 12,
                        marginBottom: 8,
                      }}
                    >
                      <View style={{ flex: 1 }}>
                        <Text variant="titleSmall">{field.label}</Text>
                        {field.description ? (
                          <Text variant="bodySmall" style={{ color: colors.onSurfaceVariant, marginTop: 2 }}>
                            {field.description}
                          </Text>
                        ) : null}
                      </View>
                      <Switch
                        value={!!(formState[fieldKey] ?? field.defaultValue ?? false)}
                        onValueChange={(value) => setFormState(previous => ({ ...previous, [fieldKey]: value }))}
                      />
                    </View>
                  );
                }

                return (
                  <TextInput
                    key={fieldKey}
                    mode="outlined"
                    label={field.label}
                    value={formState[fieldKey] || field.defaultValue || ""}
                    onChangeText={(value) => setFormState(previous => ({ ...previous, [fieldKey]: value }))}
                    style={{ marginBottom: 8 }}
                  />
                );
              })}
              <Button
                mode="contained"
                onPress={() => {
                  const payload = {};

                  for (const field of (node.fields || [])) {
                    const fieldKey = `${node.id || key}.${field.id}`;
                    payload[field.id] = formState[fieldKey] || field.defaultValue || "";
                  }

                  runAction(node.submitActionId, payload);
                }}
              >
                {node.submitLabel || t("plugins.submit")}
              </Button>
            </Card.Content>
          </Card>
        );
      case "progress":
        return (
          <View key={key} style={{ marginBottom: node.marginBottom ?? 8, gap: 6 }}>
            {(node.label || node.valueLabel) && (
              <View style={{ flexDirection: "row", alignItems: "center", justifyContent: "space-between" }}>
                <Text variant="bodySmall" style={{ color: colors.onSurfaceVariant }}>
                  {node.label || ""}
                </Text>
                <Text variant="bodySmall" style={{ color: colors.onSurface }}>
                  {node.valueLabel || (Number.isFinite(Number(node.value)) ? `${Math.round(clampPercentage(Number(node.value)))}%` : "--")}
                </Text>
              </View>
            )}
            <ProgressBar
              progress={clampPercentage(Number(node.value || 0)) / 100}
              color={node.color || colors.primary}
              style={{
                height: node.height ?? 6,
                borderRadius: 999,
                backgroundColor: node.trackColor || colors.elevation?.level2 || colors.surfaceVariant,
              }}
            />
          </View>
        );
      case "spacer":
        return (
          <View
            key={key}
            style={{
              width: node.width ?? "100%",
              height: node.height ?? 8,
            }}
          />
        );
      case "progress_cluster":
        return (
          <ProgressClusterNode
            key={key}
            extension={extension}
            node={node}
            printerId={effectivePrinterId}
          />
        );
      case "data_strip":
        return (
          <DataStripNode
            key={key}
            extension={extension}
            node={node}
            printerId={effectivePrinterId}
            navbarLayout={navbarLayout}
          />
        );
      case "remote_component": {
        const componentId = node.componentId;
        const definition = componentId ? manifestComponentMap[componentId] : null;

        if (!definition || definition.kind !== "remote_component") {
          return (
            <Card key={key} style={{ marginBottom: 12, backgroundColor: colors.errorContainer }}>
              <Card.Content>
                <Text style={{ color: colors.onErrorContainer }}>
                  {t("plugins.unableToRenderRemoteComponent", { id: componentId || t("plugins.unknown") })}
                </Text>
              </Card.Content>
            </Card>
          );
        }

        if (componentStack.includes(componentId)) {
          return (
            <Card key={key} style={{ marginBottom: 12, backgroundColor: colors.errorContainer }}>
              <Card.Content>
                <Text style={{ color: colors.onErrorContainer }}>
                  {t("plugins.remoteComponentRecursionDetected", { id: componentId })}
                </Text>
              </Card.Content>
            </Card>
          );
        }

        const resolvedSchema = resolveRemoteComponentValue(definition.schema, node.props || {});

        return renderNode(
          resolvedSchema,
          `${key}-remote-${componentId}`,
          [...componentStack, componentId]
        );
      }
      default:
        return null;
    }
  };

  if ((extension.mode || "declarative") === "webview") {
    const embeddedUrl = buildEmbeddedUiUrl(
      extension.url,
      { ...extension, currentPrinterId: effectivePrinterId },
      colors,
      effectiveLanguage
    );

    return (
      <Card style={{ marginBottom: 12, overflow: "hidden" }}>
        <Card.Title title={extension.title} subtitle={t("plugins.webViewSubtitle", { name: extension.pluginName })} />
        <View style={{ minHeight: 360 }}>
          <EmbeddedBrowserFrame uri={embeddedUrl} minHeight={360} fitContentHeight={extension.surface === "settings_tab"} />
        </View>
      </Card>
    );
  }

  if ((extension.mode || "declarative") === "custom_bundle") {
    const customBundleUrl = extension.bundle?.url || extension.url;
    const embeddedUrl = buildEmbeddedUiUrl(
      customBundleUrl,
      { ...extension, currentPrinterId: effectivePrinterId },
      colors,
      effectiveLanguage
    );

    return (
      <Card style={{ marginBottom: 12, overflow: "hidden" }}>
        <Card.Title title={extension.title} subtitle={t("plugins.customBundleSubtitle", { name: extension.pluginName })} />
        <Card.Content>
          <Text style={{ marginBottom: 12 }}>
            {t("plugins.customBundleDescription")}
          </Text>
        </Card.Content>
        {embeddedUrl ? (
          <View style={{ minHeight: 360 }}>
            <EmbeddedBrowserFrame uri={embeddedUrl} minHeight={360} fitContentHeight={extension.surface === "settings_tab"} />
          </View>
        ) : (
          <Card.Content>
            <Text>{t("plugins.noBundleUrl")}</Text>
          </Card.Content>
        )}
      </Card>
    );
  }

  const openedModal = openedModalId ? modalExtensionMap[openedModalId] : null;

  return (
    <>
      {renderNode(extension.schema)}

      <Portal>
        <Dialog visible={!!openedModal} onDismiss={() => setOpenedModalId(null)} style={{ maxWidth: 720, alignSelf: "center", width: "95%" }}>
          <Dialog.Title>{openedModal?.title}</Dialog.Title>
          <Dialog.ScrollArea>
            <ScrollView contentContainerStyle={{ paddingHorizontal: 8 }}>
              {openedModal ? renderNode(openedModal.schema, `modal-${openedModal.id}`) : null}
            </ScrollView>
          </Dialog.ScrollArea>
          <Dialog.Actions>
            <Button onPress={() => setOpenedModalId(null)}>{t("plugins.close")}</Button>
          </Dialog.Actions>
        </Dialog>
      </Portal>
    </>
  );
};

const PluginHostRenderer = ({ extension, modalExtensions = [], printerId = null, navbarLayout = "inline" }) => {
  const { enqueueSnackbar } = useSnackbar();
  const queryClient = useQueryClient();
  const { t } = useLocalization();
  const [ boundaryNonce, setBoundaryNonce ] = useState(0);
  const lastBoundaryErrorRef = useRef(null);

  const boundaryKey = `${extension.pluginId}:${extension.id}:${printerId || "global"}:${boundaryNonce}`;

  const handleBoundaryError = (error) => {
    const message = error?.message || t("plugins.unknownPluginRenderError");

    if (lastBoundaryErrorRef.current === message) {
      return;
    }

    lastBoundaryErrorRef.current = message;

    enqueueSnackbar({
      message: t("plugins.pluginUiCrashed", { name: extension.pluginName || extension.pluginId }),
      variant: "error",
      action: { label: t("notifications.dismiss") },
    });

    console.error("Plugin UI render error", {
      pluginId: extension.pluginId,
      extensionId: extension.id,
      surface: extension.surface,
      error,
    });
  };

  const resetBoundary = () => {
    queryClient.invalidateQueries({ queryKey: ["pluginExtensions"] });
    queryClient.invalidateQueries({
      predicate: ({ queryKey }) => Array.isArray(queryKey)
        && ["pluginExtensionData", "pluginExtensionStripData"].includes(queryKey[0])
        && queryKey[1] === extension.pluginId
        && queryKey[2] === extension.id,
    });
    lastBoundaryErrorRef.current = null;
    setBoundaryNonce((current) => current + 1);
  };

  return (
    <PluginRenderBoundary
      boundaryKey={boundaryKey}
      onError={handleBoundaryError}
      onReset={resetBoundary}
      renderFallback={({ error, retry }) => (
        <PluginRenderFallback
          extension={extension}
          error={error}
          onRetry={retry}
        />
      )}
    >
      <PluginHostRendererContent
        extension={extension}
        modalExtensions={modalExtensions}
        printerId={printerId}
        navbarLayout={navbarLayout}
      />
    </PluginRenderBoundary>
  );
};

export default PluginHostRenderer;
