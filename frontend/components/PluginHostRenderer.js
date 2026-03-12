import { useEffect, useMemo, useRef, useState } from "react";
import { Platform, ScrollView, View } from "react-native";
import { Button, Card, Dialog, Divider, List, Portal, ProgressBar, Text, TextInput, useTheme } from "react-native-paper";
import { WebView } from "react-native-webview";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useSnackbar } from "react-native-paper-snackbar-stack";
import API from "../includes/API";
import { usePluginLoading } from "./PluginLoadingProvider";

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

const buildEmbeddedUiUrl = (rawUrl, extension, colors) => {
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
  resolvedUrl.searchParams.set("components", JSON.stringify(extension.pluginManifest?.components || []));
  resolvedUrl.searchParams.set("componentIds", JSON.stringify(extension.components || []));
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
            label={item.label || item.id || "Metric"}
            percentage={resolvedValue}
            accentColor={tones[index % tones.length]}
          />
        );
      })}
    </View>
  );
};

const EmbeddedBrowserFrame = ({ uri, minHeight = 360 }) => {
  if (Platform.OS === "web") {
    return (
      <iframe
        src={uri}
        title={uri}
        style={{
          width: "100%",
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

const PluginHostRenderer = ({ extension, modalExtensions = [], printerId = null }) => {
  const { colors } = useTheme();
  const { enqueueSnackbar } = useSnackbar();
  const queryClient = useQueryClient();

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
      printerId,
    }),
    onSuccess: (response) => {
      queryClient.invalidateQueries({ queryKey: ["pluginExtensions"] });

      const message = response?.data?.data?.message || response?.data?.message;

      if (message) {
        enqueueSnackbar({
          message,
          variant: "info",
          action: { label: "Got it" },
        });
      }
    },
    onError: (error) => {
      enqueueSnackbar({
        message: error?.response?.data?.message || error.message,
        variant: "error",
        action: { label: "Got it" },
      });
    }
  });

  const runAction = (actionId, payload = {}) => {
    actionMutation.mutate({ actionId, payload });
  };

  const renderNode = (node, keyPrefix = "root", componentStack = []) => {
    if (!node) { return null; }

    const key = `${keyPrefix}-${node.id || node.component || "node"}`;

    switch (node.component) {
      case "section":
        return (
          <Card
            key={key}
            style={{ marginBottom: 12, backgroundColor: colors.elevation.level1 }}
          >
            {(node.title || node.subtitle) && (
              <Card.Title title={node.title} subtitle={node.subtitle} />
            )}
            <Card.Content>
              {(node.children || []).map((child, index) => renderNode(child, `${key}-${index}`, componentStack))}
            </Card.Content>
          </Card>
        );
      case "text":
        return (
          <Text key={key} style={{ marginBottom: 8 }}>
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
            style={{ marginBottom: 8 }}
            onPress={() => {
              if (node.openExtensionId) {
                setOpenedModalId(node.openExtensionId);
                return;
              }

              if (node.actionId) {
                runAction(node.actionId, node.payload || {});
              }
            }}
          >
            {node.label || "Run"}
          </Button>
        );
      case "form":
        return (
          <Card key={key} style={{ marginBottom: 12, backgroundColor: colors.elevation.level1 }}>
            {(node.title || node.subtitle) && <Card.Title title={node.title} subtitle={node.subtitle} />}
            <Card.Content>
              {(node.fields || []).map((field) => {
                const fieldKey = `${node.id || key}.${field.id}`;

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
                {node.submitLabel || "Submit"}
              </Button>
            </Card.Content>
          </Card>
        );
      case "progress_cluster":
        return (
          <ProgressClusterNode
            key={key}
            extension={extension}
            node={node}
            printerId={printerId}
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
                  Unable to render remote component {componentId || "unknown"}.
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
                  Remote component recursion detected for {componentId}.
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
    const embeddedUrl = buildEmbeddedUiUrl(extension.url, extension, colors);

    return (
      <Card style={{ marginBottom: 12, overflow: "hidden" }}>
        <Card.Title title={extension.title} subtitle={`${extension.pluginName} WebView`} />
        <View style={{ minHeight: 360 }}>
          <EmbeddedBrowserFrame uri={embeddedUrl} minHeight={360} />
        </View>
      </Card>
    );
  }

  if ((extension.mode || "declarative") === "custom_bundle") {
    const customBundleUrl = extension.bundle?.url || extension.url;
    const embeddedUrl = buildEmbeddedUiUrl(customBundleUrl, extension, colors);

    return (
      <Card style={{ marginBottom: 12, overflow: "hidden" }}>
        <Card.Title title={extension.title} subtitle={`${extension.pluginName} custom bundle`} />
        <Card.Content>
          <Text style={{ marginBottom: 12 }}>
            This extension uses the elevated custom bundle mode. It is isolated and may consume more resources than the default declarative mode.
          </Text>
        </Card.Content>
        {embeddedUrl ? (
          <View style={{ minHeight: 360 }}>
            <EmbeddedBrowserFrame uri={embeddedUrl} minHeight={360} />
          </View>
        ) : (
          <Card.Content>
            <Text>No bundle URL was provided by this plugin.</Text>
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
            <Button onPress={() => setOpenedModalId(null)}>Close</Button>
          </Dialog.Actions>
        </Dialog>
      </Portal>
    </>
  );
};

export default PluginHostRenderer;
