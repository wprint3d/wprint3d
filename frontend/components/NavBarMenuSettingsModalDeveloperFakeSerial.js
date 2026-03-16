import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useEffect, useMemo, useState } from "react";
import { ScrollView, View } from "react-native";
import { ActivityIndicator, Button, Card, Chip, Divider, Icon, SegmentedButtons, Switch, Text, useTheme } from "react-native-paper";

import API from "../includes/API";
import { useLocalization } from "../includes/LocalizationProvider";

const formatTimestamp = (timestamp) => {
    if (!timestamp) {
        return "";
    }

    return new Date(timestamp * 1000).toLocaleTimeString();
};

const DIRECTION_ICON = {
    input: "arrow-top-right",
    output: "arrow-bottom-left",
    status: "information-outline",
};

const DIRECTION_COLOR_KEY = {
    input: "primary",
    output: "secondary",
    status: "tertiary",
};

const NavBarMenuSettingsModalDeveloperFakeSerial = ({ enqueueSnackbar }) => {
    const theme = useTheme();
    const { t } = useLocalization();
    const queryClient = useQueryClient();
    const [ selectedBaudRate, setSelectedBaudRate ] = useState("115200");

    const fakeSerialQuery = useQuery({
        queryKey: ["developerFakeSerial"],
        queryFn: () => API.get("/developer/fake-serial"),
        refetchInterval: 1000,
    });

    const updateFakeSerialMutation = useMutation({
        mutationFn: ({ enabled, baudRate }) => API.put("/developer/fake-serial", { enabled, baudRate }),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ["developerFakeSerial"] });
            queryClient.invalidateQueries({ queryKey: ["printersList"] });
        },
        onError: (error) => {
            enqueueSnackbar({
                message: error?.response?.data?.message ?? error.message,
                variant: "error",
                action: { label: t("notifications.gotIt") },
            });
        },
    });

    const state = fakeSerialQuery?.data?.data ?? {};
    const supportedBaudRates = state?.supportedBaudRates ?? [115200, 250000];
    const enabled = state?.enabled === true;
    const printer = state?.printer ?? null;
    const log = state?.log ?? [];

    useEffect(() => {
        if (state?.baudRate) {
            setSelectedBaudRate(String(state.baudRate));
        }
    }, [ state?.baudRate ]);

    const baudButtons = useMemo(
        () => supportedBaudRates.map((baudRate) => ({
            label: `${baudRate}`,
            value: `${baudRate}`,
        })),
        [ supportedBaudRates ]
    );

    if (fakeSerialQuery.isLoading && !fakeSerialQuery.data) {
        return (
            <View style={{ alignItems: "center", justifyContent: "center", minHeight: 220 }}>
                <ActivityIndicator animating size="large" />
            </View>
        );
    }

    return (
        <View style={{ gap: 16 }}>
            <Card mode="contained" style={{ backgroundColor: theme.colors.elevation.level2 }}>
                <Card.Content style={{ gap: 16 }}>
                    <View style={{ flexDirection: "row", justifyContent: "space-between", alignItems: "center", gap: 16 }}>
                        <View style={{ flex: 1, gap: 4 }}>
                            <Text variant="titleMedium">{t("plugins.fakeSerialTitle")}</Text>
                            <Text style={{ color: theme.colors.onSurfaceVariant }}>
                                {t("plugins.fakeSerialDescription")}
                            </Text>
                        </View>
                        <Switch
                            value={enabled}
                            disabled={updateFakeSerialMutation.isPending}
                            onValueChange={(nextEnabled) => updateFakeSerialMutation.mutate({
                                enabled: nextEnabled,
                                baudRate: Number(selectedBaudRate),
                            })}
                        />
                    </View>

                    <View style={{ flexDirection: "row", flexWrap: "wrap", gap: 8 }}>
                        <Chip icon="usb-port" compact>{state?.node ?? "FAKE0"}</Chip>
                        <Chip icon="connection" compact>{enabled ? t("plugins.pluggedIn") : t("plugins.unplugged")}</Chip>
                        <Chip icon={printer?.connected ? "check-circle" : "pause-circle"} compact>
                            {printer?.connected ? t("plugins.detectedByMapper") : t("plugins.waitingForMapper")}
                        </Chip>
                        {printer?.machine?.machineType && (
                            <Chip icon="printer-3d-nozzle-outline" compact>{printer.machine.machineType}</Chip>
                        )}
                    </View>

                    <View style={{ gap: 8 }}>
                        <Text variant="labelLarge">{t("plugins.activeBaudRate")}</Text>
                        <SegmentedButtons
                            value={selectedBaudRate}
                            buttons={baudButtons}
                            onValueChange={(value) => {
                                setSelectedBaudRate(value);

                                updateFakeSerialMutation.mutate({
                                    enabled,
                                    baudRate: Number(value),
                                });
                            }}
                        />
                    </View>

                    <Divider />

                    <View style={{ gap: 8 }}>
                        <View style={{ flexDirection: "row", justifyContent: "space-between", alignItems: "center", gap: 16 }}>
                            <Text variant="labelLarge">{t("plugins.liveTranscript")}</Text>
                            <Button
                                mode="text"
                                icon="refresh"
                                compact
                                onPress={() => fakeSerialQuery.refetch()}
                                disabled={fakeSerialQuery.isFetching}
                            >
                                {t("plugins.refresh")}
                            </Button>
                        </View>

                        <ScrollView
                            style={{
                                maxHeight: 320,
                                borderRadius: 16,
                                backgroundColor: theme.colors.elevation.level1,
                                borderWidth: 1,
                                borderColor: theme.colors.outlineVariant,
                            }}
                            contentContainerStyle={{ padding: 12, gap: 10 }}
                        >
                            {!log.length && (
                                <Text style={{ color: theme.colors.onSurfaceVariant }}>
                                    {t("plugins.noFakeSerialActivity")}
                                </Text>
                            )}

                            {log.map((entry, index) => {
                                const colorKey = DIRECTION_COLOR_KEY[entry.direction] ?? "secondary";
                                const icon = DIRECTION_ICON[entry.direction] ?? "information-outline";

                                return (
                                    <View key={`${entry.timestamp}-${index}`} style={{ flexDirection: "row", alignItems: "flex-start", gap: 10 }}>
                                        <Icon source={icon} size={18} color={theme.colors[colorKey]} />
                                        <View style={{ flex: 1, gap: 2 }}>
                                            <Text style={{ color: theme.colors.onSurface, fontWeight: "600" }}>
                                                {entry.message}
                                            </Text>
                                            <Text variant="bodySmall" style={{ color: theme.colors.onSurfaceVariant }}>
                                                {entry.direction} {formatTimestamp(entry.timestamp)}
                                            </Text>
                                        </View>
                                    </View>
                                );
                            })}
                        </ScrollView>
                    </View>
                </Card.Content>
            </Card>
        </View>
    );
};

export default NavBarMenuSettingsModalDeveloperFakeSerial;
