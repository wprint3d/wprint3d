import { useEffect } from "react";
import { View } from "react-native";
import { List, Text } from "react-native-paper";

import UserPaneLoadingIndicator from "./UserPaneLoadingIndicator";
import { useLocalization } from "../includes/LocalizationProvider";

const PrinterSettingsModalDetails = ({ details, isLoading, error }) => {
    const { t } = useLocalization();

    const normalizeMetaValue = (key, value) => {
        if (typeof value === "boolean") {
            return value ? t("notifications.yes") : t("notifications.no");
        }

        if (value === null || value === undefined || value === "") {
            return null;
        }

        if (key === "connectionType") {
            const localizedValue = t(`settings.printerMetaValues.connectionType.${value}`);

            return localizedValue === `settings.printerMetaValues.connectionType.${value}` ? value : localizedValue;
        }

        return String(value);
    };

    useEffect(() => {
        console.debug('PrinterSettingsModalDetails: details:', details);
    }, [ details ]);

    if (isLoading) {
        return (
            <View>
                <UserPaneLoadingIndicator message={t("settings.loadingPrinterDetails")} />
            </View>
        );
    }

    if (error) {
        return (
            <View style={{ flex: 1, justifyContent: 'center', alignItems: 'center' }}>
                <Text style={{ textAlign: 'center' }}>
                    {t("settings.printerDetailsLoadError")}
                    {'\n\n'}
                    {error}
                </Text>
            </View>
        );
    }

    const { node, baudRate, machine } = details;

    const CAPABILITY_NAMES = {
        serialXonXoff: t("settings.printerCapabilities.serialXonXoff"),
        binaryFileTransfer: t("settings.printerCapabilities.binaryFileTransfer"),
        eeprom: t("settings.printerCapabilities.eeprom"),
        volumetric: t("settings.printerCapabilities.volumetric"),
        autoreportPos: t("settings.printerCapabilities.autoreportPos"),
        autoreportTemp: t("settings.printerCapabilities.autoreportTemp"),
        progress: t("settings.printerCapabilities.progress"),
        printJob: t("settings.printerCapabilities.printJob"),
        autolevel: t("settings.printerCapabilities.autolevel"),
        runout: t("settings.printerCapabilities.runout"),
        zProbe: t("settings.printerCapabilities.zProbe"),
        levelingData: t("settings.printerCapabilities.levelingData"),
        buildPercent: t("settings.printerCapabilities.buildPercent"),
        softwarePower: t("settings.printerCapabilities.softwarePower"),
        toggleLights: t("settings.printerCapabilities.toggleLights"),
        caseLightBrightness: t("settings.printerCapabilities.caseLightBrightness"),
        emergencyParser: t("settings.printerCapabilities.emergencyParser"),
        hostActionCommands: t("settings.printerCapabilities.hostActionCommands"),
        promptSupport: t("settings.printerCapabilities.promptSupport"),
        sdcard: t("settings.printerCapabilities.sdcard"),
        repeat: t("settings.printerCapabilities.repeat"),
        sdWrite: t("settings.printerCapabilities.sdWrite"),
        autoreportSdStatus: t("settings.printerCapabilities.autoreportSdStatus"),
        longFilename: t("settings.printerCapabilities.longFilename"),
        lfnWrite: t("settings.printerCapabilities.lfnWrite"),
        customFirmwareUpload: t("settings.printerCapabilities.customFirmwareUpload"),
        extendedM20: t("settings.printerCapabilities.extendedM20"),
        thermalProtection: t("settings.printerCapabilities.thermalProtection"),
        motionModes: t("settings.printerCapabilities.motionModes"),
        arcs: t("settings.printerCapabilities.arcs"),
        babystepping: t("settings.printerCapabilities.babystepping"),
        chamberTemperature: t("settings.printerCapabilities.chamberTemperature"),
        coolerTemperature: t("settings.printerCapabilities.coolerTemperature"),
        meatpack: t("settings.printerCapabilities.meatpack"),
        configExport: t("settings.printerCapabilities.configExport"),
    };

    const META_NAMES = {
        firmwareName: t("settings.printerMeta.firmwareName"),
        sourceCodeUrl: t("settings.printerMeta.sourceCodeUrl"),
        fakeSerialSimsourceCodeUrl: t("settings.printerMeta.sourceCodeUrl"),
        protocolVersion: t("settings.printerMeta.protocolVersion"),
        machineType: t("settings.printerMeta.machineType"),
        extruderCount: t("settings.printerMeta.extruderCount"),
        axisCount: t("settings.printerMeta.axisCount"),
        uuid: t("settings.printerMeta.uuid"),
        connectionType: t("settings.printerMeta.connectionType"),
        simulated: t("settings.printerMeta.simulated"),
    };

    return (
        <View>
            <List.Section title={t("settings.connectionSection")}>
                <List.Item title={t("settings.portLabel")} description={node} />
                <List.Item title={t("settings.baudRateLabel")} description={t("settings.baudRateValue", { rate: baudRate })} />
            </List.Section>

            <List.Section title={t("settings.machineSection")}>
                {Object.keys(machine).map(key => {
                    if (key === 'capabilities') return;

                    return (
                        <List.Item
                            key={key}
                            title={META_NAMES[key] ?? key}
                            description={normalizeMetaValue(key, machine[key])}
                        />
                    );
                })}
            </List.Section>

            <List.Section title={t("settings.featuresSection")}>
                {Object.keys(machine?.capabilities).map(capability => {
                    return (
                        <List.Item
                            key={capability}
                            title={CAPABILITY_NAMES[capability] ?? capability}
                            description={machine.capabilities[capability] ? t("notifications.yes") : t("notifications.no")}
                        />
                    );
                })}
            </List.Section>
        </View>
    );
}

export default PrinterSettingsModalDetails;
