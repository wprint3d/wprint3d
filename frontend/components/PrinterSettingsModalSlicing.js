import { useEffect, useMemo, useState } from "react";
import { ScrollView, View } from "react-native";
import { ActivityIndicator, Button, Card, Chip, Icon, List, Switch, Text, TextInput, TouchableRipple, useTheme } from "react-native-paper";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useSnackbar } from "react-native-paper-snackbar-stack";
import API from "../includes/API";
import { useLocalization } from "../includes/LocalizationProvider";
import UserPaneLoadingIndicator from "./UserPaneLoadingIndicator";

const numericValue = (value) => Number.isFinite(Number(value)) ? String(value) : "";

const buildOverrides = (snapshot) => ({
    buildVolume: {
        width: numericValue(snapshot?.buildVolume?.width),
        depth: numericValue(snapshot?.buildVolume?.depth),
        height: numericValue(snapshot?.buildVolume?.height),
    },
    heatedBed: !!snapshot?.heatedBed,
    gcodeFlavor: snapshot?.gcodeFlavor || "",
    extruders: (snapshot?.extruders || []).map((extruder) => ({
        nozzleDiameter: numericValue(extruder?.nozzleDiameter),
        filamentDiameter: numericValue(extruder?.filamentDiameter),
        offsets: extruder?.offsets || [0, 0],
        coolingFan: extruder?.coolingFan !== false,
        startGcode: extruder?.startGcode || "",
        endGcode: extruder?.endGcode || "",
    })),
});

const serializeOverrides = (overrides) => ({
    buildVolume: {
        width: Number(overrides.buildVolume.width),
        depth: Number(overrides.buildVolume.depth),
        height: Number(overrides.buildVolume.height),
    },
    heatedBed: !!overrides.heatedBed,
    gcodeFlavor: overrides.gcodeFlavor,
    extruders: overrides.extruders.map((extruder) => ({
        ...extruder,
        nozzleDiameter: Number(extruder.nozzleDiameter),
        filamentDiameter: Number(extruder.filamentDiameter),
    })),
});

const SnapshotSummary = ({ snapshot, overrides, setOverrides, editable, t }) => {
    const { colors } = useTheme();
    const updateVolume = (key, value) => setOverrides?.((current) => ({
        ...current,
        buildVolume: { ...current.buildVolume, [key]: value },
    }));
    const updateExtruder = (index, key, value) => setOverrides?.((current) => ({
        ...current,
        extruders: current.extruders.map((extruder, candidateIndex) => (
            candidateIndex === index ? { ...extruder, [key]: value } : extruder
        )),
    }));

    return (
        <View style={{ gap: 12 }}>
            <View style={{ flexDirection: "row", flexWrap: "wrap", gap: 8 }}>
                <Chip icon="cube-outline">{snapshot?.buildVolume?.shape || t("settings.slicing.rectangular")}</Chip>
                <Chip icon={snapshot?.heatedBed ? "radiator" : "radiator-disabled"}>
                    {snapshot?.heatedBed ? t("settings.slicing.heatedBed") : t("settings.slicing.unheatedBed")}
                </Chip>
                <Chip icon="printer-3d-nozzle">{t("settings.slicing.extruderCount", { count: snapshot?.extruders?.length || 0 })}</Chip>
            </View>

            <View style={{ gap: 8 }}>
                <Text variant="titleMedium">{t("settings.slicing.buildVolume")}</Text>
                <View style={{ flexDirection: "row", flexWrap: "wrap", gap: 8 }}>
                    {["width", "depth", "height"].map((dimension) => (
                        <TextInput
                            key={dimension}
                            mode="outlined"
                            dense
                            disabled={!editable}
                            keyboardType="decimal-pad"
                            label={t(`settings.slicing.${dimension}`)}
                            value={editable ? overrides?.buildVolume?.[dimension] : numericValue(snapshot?.buildVolume?.[dimension])}
                            onChangeText={(value) => updateVolume(dimension, value)}
                            right={<TextInput.Affix text="mm" />}
                            style={{ flexGrow: 1, flexBasis: 130, minWidth: 120 }}
                        />
                    ))}
                </View>
            </View>

            <View style={{ gap: 8 }}>
                <Text variant="titleMedium">{t("settings.slicing.machineBehavior")}</Text>
                <TextInput
                    mode="outlined"
                    dense
                    disabled={!editable}
                    label={t("settings.slicing.gcodeFlavor")}
                    value={editable ? overrides?.gcodeFlavor : (snapshot?.gcodeFlavor || "")}
                    onChangeText={(value) => setOverrides?.((current) => ({ ...current, gcodeFlavor: value }))}
                />
                <View style={{ flexDirection: "row", alignItems: "center", justifyContent: "space-between", minHeight: 48 }}>
                    <Text>{t("settings.slicing.heatedBed")}</Text>
                    <Switch
                        disabled={!editable}
                        value={editable ? !!overrides?.heatedBed : !!snapshot?.heatedBed}
                        onValueChange={(value) => setOverrides?.((current) => ({ ...current, heatedBed: value }))}
                    />
                </View>
            </View>

            <View style={{ gap: 8 }}>
                <Text variant="titleMedium">{t("settings.slicing.extruders")}</Text>
                {(snapshot?.extruders || []).map((extruder, index) => (
                    <Card key={`extruder-${index}`} mode="outlined" style={{ backgroundColor: colors.elevation.level1 }}>
                        <Card.Content style={{ gap: 8 }}>
                            <Text variant="labelLarge">{t("settings.slicing.extruder", { index: index + 1 })}</Text>
                            <View style={{ flexDirection: "row", flexWrap: "wrap", gap: 8 }}>
                                <TextInput
                                    mode="outlined"
                                    dense
                                    disabled={!editable}
                                    keyboardType="decimal-pad"
                                    label={t("settings.slicing.nozzleDiameter")}
                                    value={editable ? overrides?.extruders?.[index]?.nozzleDiameter : numericValue(extruder.nozzleDiameter)}
                                    onChangeText={(value) => updateExtruder(index, "nozzleDiameter", value)}
                                    right={<TextInput.Affix text="mm" />}
                                    style={{ flexGrow: 1, flexBasis: 160 }}
                                />
                                <TextInput
                                    mode="outlined"
                                    dense
                                    disabled={!editable}
                                    keyboardType="decimal-pad"
                                    label={t("settings.slicing.filamentDiameter")}
                                    value={editable ? overrides?.extruders?.[index]?.filamentDiameter : numericValue(extruder.filamentDiameter)}
                                    onChangeText={(value) => updateExtruder(index, "filamentDiameter", value)}
                                    right={<TextInput.Affix text="mm" />}
                                    style={{ flexGrow: 1, flexBasis: 160 }}
                                />
                            </View>
                        </Card.Content>
                    </Card>
                ))}
            </View>
        </View>
    );
};

export default function PrinterSettingsModalSlicing({ printer }) {
    const { colors } = useTheme();
    const { t } = useLocalization();
    const { enqueueSnackbar } = useSnackbar();
    const queryClient = useQueryClient();
    const printerId = printer?._id;
    const [search, setSearch] = useState("");
    const [debouncedSearch, setDebouncedSearch] = useState("");
    const [selectedDefinitionId, setSelectedDefinitionId] = useState(null);
    const [editing, setEditing] = useState(false);
    const [overrides, setOverrides] = useState(null);

    useEffect(() => {
        const timer = setTimeout(() => setDebouncedSearch(search.trim()), 250);
        return () => clearTimeout(timer);
    }, [search]);

    const configurationQuery = useQuery({
        queryKey: ["printerSlicing", printerId],
        queryFn: () => API.get(`/printer/${printerId}/slicing`),
        enabled: !!printerId,
    });
    const candidatesQuery = useQuery({
        queryKey: ["printerSlicingCandidates", printerId, debouncedSearch],
        queryFn: () => API.get(`/printer/${printerId}/slicing/candidates`, { q: debouncedSearch }),
        enabled: !!printerId && debouncedSearch.length >= 2,
    });
    const selectedCandidateQuery = useQuery({
        queryKey: ["printerSlicingCandidate", printerId, selectedDefinitionId],
        queryFn: () => API.get(`/printer/${printerId}/slicing/candidates`, {
            q: selectedDefinitionId,
            definitionId: selectedDefinitionId,
        }),
        enabled: !!printerId && !!selectedDefinitionId,
    });

    const configuration = configurationQuery.data?.data;
    const stored = configuration?.configuration;
    const selectedCandidate = selectedCandidateQuery.data?.data?.items?.find((item) => item.definitionId === selectedDefinitionId);
    const proposal = selectedCandidate || (!editing ? configuration?.proposal : null);
    const activeDefinitionId = proposal?.definitionId || stored?.definitionId;
    const activeSnapshot = proposal?.snapshot || stored?.snapshot;
    const showingConfigured = configuration?.status === "configured" && !proposal && !editing;

    useEffect(() => {
        if (!activeSnapshot) { return; }
        setOverrides(buildOverrides(activeSnapshot));
    }, [activeDefinitionId, activeSnapshot]);

    const confirmMutation = useMutation({
        mutationFn: () => API.put(`/printer/${printerId}/slicing`, {
            expectedRevision: configuration?.revision || 0,
            definitionId: activeDefinitionId,
            overrides: serializeOverrides(overrides),
        }),
        onSuccess: async (response) => {
            setEditing(false);
            setSelectedDefinitionId(null);
            setSearch("");
            await queryClient.invalidateQueries({ queryKey: ["printerSlicing", printerId] });
            queryClient.invalidateQueries({ queryKey: ["pluginHostContext"], refetchType: "none" });
            if (typeof globalThis.window !== "undefined") {
                globalThis.window.dispatchEvent(new globalThis.CustomEvent("wprint3d:printer-slicing-updated", {
                    detail: { printerId, revision: response?.data?.revision || 0 },
                }));
            }
            enqueueSnackbar({ message: t("settings.slicing.saved"), variant: "success" });
        },
        onError: (error) => enqueueSnackbar({
            message: error?.response?.data?.error?.message || error?.response?.data?.message || t("settings.slicing.saveError"),
            variant: "error",
        }),
    });

    const invalidOverrides = !overrides
        || [overrides.buildVolume.width, overrides.buildVolume.depth, overrides.buildVolume.height]
            .some((value) => !Number.isFinite(Number(value)) || Number(value) <= 0)
        || overrides.extruders.some((extruder) => (
            !Number.isFinite(Number(extruder.nozzleDiameter))
            || Number(extruder.nozzleDiameter) <= 0
            || !Number.isFinite(Number(extruder.filamentDiameter))
            || Number(extruder.filamentDiameter) <= 0
        ));

    if (configurationQuery.isLoading) {
        return <UserPaneLoadingIndicator message={t("settings.slicing.loading")} />;
    }

    if (configurationQuery.isError) {
        return (
            <View style={{ flex: 1, alignItems: "center", justifyContent: "center", gap: 12, padding: 24 }}>
                <Icon source="alert-circle-outline" size={42} color={colors.error} />
                <Text style={{ textAlign: "center" }}>{t("settings.slicing.loadError")}</Text>
                <Button mode="outlined" onPress={() => configurationQuery.refetch()}>{t("settings.slicing.retry")}</Button>
            </View>
        );
    }

    return (
        <ScrollView contentContainerStyle={{ gap: 16, paddingBottom: 24 }}>
            <Card mode="outlined" style={{ backgroundColor: colors.elevation.level1 }}>
                <Card.Content style={{ gap: 10 }}>
                    <View style={{ flexDirection: "row", alignItems: "center", gap: 12 }}>
                        <Icon
                            source={configuration?.status === "configured" ? "check-circle" : "alert-circle-outline"}
                            size={28}
                            color={configuration?.status === "configured" ? colors.success : colors.warning}
                        />
                        <View style={{ flex: 1, minWidth: 0 }}>
                            <Text variant="titleMedium">
                                {configuration?.status === "configured" ? t("settings.slicing.configured") : t("settings.slicing.confirmationRequired")}
                            </Text>
                            <Text style={{ color: colors.onSurfaceVariant }}>
                                {configuration?.status === "configured"
                                    ? t("settings.slicing.configuredDescription", { revision: configuration.revision })
                                    : t("settings.slicing.confirmationDescription")}
                            </Text>
                        </View>
                    </View>
                </Card.Content>
            </Card>

            {(editing || !showingConfigured) && (
                <View style={{ gap: 8 }}>
                    <TextInput
                        mode="outlined"
                        label={t("settings.slicing.searchLabel")}
                        accessibilityLabel={t("settings.slicing.searchLabel")}
                        placeholder={t("settings.slicing.searchPlaceholder")}
                        left={<TextInput.Icon icon="magnify" />}
                        value={search}
                        onChangeText={setSearch}
                    />
                    {candidatesQuery.isFetching && <ActivityIndicator style={{ padding: 8 }} />}
                    {!!debouncedSearch && !candidatesQuery.isFetching && (candidatesQuery.data?.data?.items || []).length === 0 && (
                        <Text style={{ color: colors.onSurfaceVariant }}>{t("settings.slicing.noCandidates")}</Text>
                    )}
                    {(candidatesQuery.data?.data?.items || []).slice(0, 20).map((candidate) => (
                        <TouchableRipple
                            key={candidate.definitionId}
                            onPress={() => setSelectedDefinitionId(candidate.definitionId)}
                            accessibilityRole="button"
                            accessibilityLabel={t("settings.slicing.selectCandidate", { name: candidate.displayName })}
                            style={{ borderRadius: 8, borderWidth: 1, borderColor: colors.outlineVariant, minHeight: 52 }}
                        >
                            <List.Item
                                title={candidate.displayName}
                                description={`${candidate.manufacturer || "Cura"} • ${t("settings.slicing.extruderCount", { count: candidate.extruderCount })}`}
                                left={(props) => <List.Icon {...props} icon="printer-3d" />}
                                right={(props) => <List.Icon {...props} icon="chevron-right" />}
                            />
                        </TouchableRipple>
                    ))}
                </View>
            )}

            {activeSnapshot ? (
                <Card mode="outlined" style={{ backgroundColor: colors.elevation.level1 }}>
                    <Card.Title
                        title={activeSnapshot.displayName}
                        subtitle={`${activeDefinitionId} • Cura ${stored?.resourceVersion || "5.12.1"}`}
                        left={(props) => <Icon {...props} source="printer-3d" />}
                    />
                    <Card.Content>
                        <SnapshotSummary
                            snapshot={activeSnapshot}
                            overrides={overrides}
                            setOverrides={setOverrides}
                            editable={!showingConfigured}
                            t={t}
                        />
                    </Card.Content>
                    <Card.Actions style={{ flexWrap: "wrap", gap: 8 }}>
                        {showingConfigured ? (
                            <Button mode="outlined" icon="swap-horizontal" onPress={() => setEditing(true)}>
                                {t("settings.slicing.changeDefinition")}
                            </Button>
                        ) : (
                            <>
                                {configuration?.status === "configured" && (
                                    <Button mode="text" onPress={() => { setEditing(false); setSelectedDefinitionId(null); }}>
                                        {t("settings.slicing.cancel")}
                                    </Button>
                                )}
                                <Button
                                    mode="contained"
                                    icon="check"
                                    loading={confirmMutation.isPending || selectedCandidateQuery.isFetching}
                                    disabled={!activeDefinitionId || invalidOverrides || confirmMutation.isPending || selectedCandidateQuery.isFetching}
                                    onPress={() => confirmMutation.mutate()}
                                >
                                    {t("settings.slicing.confirm")}
                                </Button>
                            </>
                        )}
                    </Card.Actions>
                </Card>
            ) : (
                <View style={{ alignItems: "center", gap: 8, padding: 24 }}>
                    <Icon source="printer-search" size={48} color={colors.onSurfaceVariant} />
                    <Text variant="titleMedium">{t("settings.slicing.noProposalTitle")}</Text>
                    <Text style={{ textAlign: "center", color: colors.onSurfaceVariant }}>{t("settings.slicing.noProposalBody")}</Text>
                </View>
            )}
        </ScrollView>
    );
}
